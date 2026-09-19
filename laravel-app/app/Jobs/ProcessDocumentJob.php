<?php

namespace App\Jobs;

use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Exceptions\AiServiceException;
use App\Exceptions\LocalHeavyResourceBusyException;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Services\Ai\AiServiceClient;
use App\Services\Ai\Data\ProcessDocumentRequestData;
use App\Services\Documents\ProcessingRunActivator;
use App\Services\Documents\ProcessingRunFailureClassifier;
use App\Services\Documents\ProcessingRunFailureFinalizer;
use App\Services\Documents\ProcessingRunProgressor;
use App\Services\Documents\ProcessingRunResultPersister;
use App\Services\Infrastructure\LocalHeavyResourceLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Tries(3)] // الحد الأقصى لعدد محاولات تنفيذ الـ Job هو 3 محاولات
#[Backoff([15, 60])] // الانتظار 15 ثانية قبل المحاولة الثانية و60 ثانية قبل المحاولة الثالثة
#[Timeout(330)] // إنهاء محاولة الـ Job إذا تجاوز تنفيذها 330 ثانية
class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $processingRunId,
    ) {}

    public function handle(
        AiServiceClient $client,
        ProcessingRunProgressor $processingRunProgressor,
        ProcessingRunResultPersister $resultPersister,
        ProcessingRunActivator $processingRunActivator,
        ProcessingRunFailureClassifier $failureClassifier,
        ProcessingRunFailureFinalizer $failureFinalizer,
        ?LocalHeavyResourceLock $localHeavyResourceLock = null,
    ): void {
        try {
            // Indexed is a durable checkpoint: retries finish activation/cleanup
            // without invoking parsers, embedding providers or models again.
            $completedRun = ProcessingRun::with('document')->find($this->processingRunId);
            if ($completedRun?->status === ProcessingRunStatus::Indexed) {
                $document = $completedRun->document;
                if (! $document || $document->deletion_started_at !== null) {
                    return;
                }
                if ($document->active_processing_run_id === null
                    || $document->active_processing_run_id < $completedRun->id) {
                    $processingRunActivator->activate($completedRun);
                }
                $this->cleanupSupersededRuns($client, $document);

                return;
            }

            $processingRun = $processingRunProgressor
                ->markProcessingStarted($this->processingRunId)
                ->load('document');

            $document = $processingRun->document;

            if (! $document instanceof Document) {
                throw new \RuntimeException(
                    'Processing run does not have an associated document.',
                );
            }

            $requestData = new ProcessDocumentRequestData(
                userId: (int) $document->user_id,
                documentId: (int) $document->id,
                processingRunId: (int) $processingRun->id,
                processingProfile: $processingRun->profile,
                fileType: $document->file_type,
            );

            $lockToken = null;

            if (
                $processingRun->profile === ProcessingProfile::HybridLocal
                && $localHeavyResourceLock instanceof LocalHeavyResourceLock
                && $localHeavyResourceLock->enabled()
            ) {
                $lockToken = $localHeavyResourceLock->acquireWithin();

                if ($lockToken === null) {
                    throw new LocalHeavyResourceBusyException(
                        'Local heavy-resource lock could not be acquired within the configured wait window.',
                    );
                }
            }

            try {
                $result = $client->processDocument(
                    data: $requestData,
                    filePath: $document->file_path,
                    fileName: $document->original_name,
                );
            } finally {
                if ($localHeavyResourceLock instanceof LocalHeavyResourceLock) {
                    $this->releaseLocalHeavyResourceLock(
                        $localHeavyResourceLock,
                        $lockToken,
                    );
                }
            }

            $indexedProcessingRun = $resultPersister->persist(
                processingRunId: $this->processingRunId,
                result: $result,
            );

            $processingRunActivator->activate(
                $indexedProcessingRun,
            );

            $this->cleanupSupersededRuns($client, $document);
        } catch (Throwable $exception) {
            if ($failureClassifier->isRetryable($exception)) {
                if ($exception instanceof AiServiceException && $exception->errorCode) {
                    ProcessingRun::whereKey($this->processingRunId)
                        ->whereNotIn('status', [ProcessingRunStatus::Indexed, ProcessingRunStatus::Failed])
                        ->update(['error_code' => $failureClassifier->terminalErrorCode($exception),
                            'failure_reason' => $failureClassifier->terminalFailureReason($exception)]);
                }
                // الخطأ المؤقت يُعاد رميه حتى تعيد Laravel نفس الـ Job ونفس الـ Run.
                throw $exception;
            }

            // الخطأ الدائم يُسجّل فوراً كفشل نهائي.
            $failureFinalizer->finalize(
                processingRunId: $this->processingRunId,
                errorCode: $failureClassifier->terminalErrorCode($exception),
                failureReason: $failureClassifier->terminalFailureReason(
                    $exception,
                ),
            );

            // إيقاف الـ Job نهائياً وعدم استهلاك باقي محاولات الـ retry.
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $timedOut = $exception instanceof TimeoutExceededException;
        $classifier = app(ProcessingRunFailureClassifier::class);
        $run = ProcessingRun::find($this->processingRunId);
        $originalCode = $exception instanceof AiServiceException && $exception->errorCode
            ? $classifier->terminalErrorCode($exception) : $run?->error_code;
        $originalReason = $exception instanceof AiServiceException && $exception->errorCode
            ? $classifier->terminalFailureReason($exception) : $run?->failure_reason;

        // عند انتهاء جميع محاولات الـ retry أو حدوث timeout نهائي،
        // نغلق الـ ProcessingRun كفشل نهائي بشكل آمن وIdempotent.
        app(ProcessingRunFailureFinalizer::class)->finalize(
            processingRunId: $this->processingRunId,
            errorCode: $originalCode ?? ($timedOut
                ? 'processing_timeout_exhausted'
                : 'processing_retries_exhausted'),
            failureReason: $originalReason ?? ($timedOut
                ? 'Document processing timed out after all allowed attempts.'
                : 'Document processing failed after all allowed retry attempts.'),
        );
    }

    private function cleanupSupersededRuns(AiServiceClient $client, Document $document): void
    {
        // Retained indexed run rows are the durable, idempotent cleanup inventory.
        // Read the current active id so a delayed job cannot remove its successor.
        $document->refresh();
        if ($document->deletion_started_at !== null || $document->active_processing_run_id === null) {
            return;
        }
        foreach ($document->processingRuns()
            ->where('status', ProcessingRunStatus::Indexed)
            ->where('id', '<', $document->active_processing_run_id)
            ->orderBy('id')->get() as $run) {
            $client->deleteProcessingRunPoints(
                userId: (int) $document->user_id,
                documentId: (int) $document->id,
                processingRunId: (int) $run->id,
                processingProfile: $run->profile,
            );
        }
    }

    private function releaseLocalHeavyResourceLock(
        LocalHeavyResourceLock $lock,
        ?string $token,
    ): void {
        if ($token === null) {
            return;
        }

        try {
            if (! $lock->release($token)) {
                Log::warning(
                    'Local heavy-resource lock was no longer owned when processing completed.',
                    [
                        'processing_run_id' => $this->processingRunId,
                    ],
                );
            }
        } catch (Throwable $exception) {
            Log::error(
                'Failed to release local heavy-resource lock after document processing.',
                [
                    'processing_run_id' => $this->processingRunId,
                    'exception' => $exception::class,
                ],
            );
        }
    }
}
