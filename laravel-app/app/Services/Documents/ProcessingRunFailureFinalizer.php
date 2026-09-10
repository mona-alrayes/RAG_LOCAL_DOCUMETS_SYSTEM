<?php

namespace App\Services\Documents;

use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\ProcessingRun;
use Illuminate\Support\Facades\DB;
use LogicException;

class ProcessingRunFailureFinalizer
{
    public function __construct(
        private readonly DocumentStatusProjector $documentStatusProjector,
    ) {}

    public function finalize(
        int $processingRunId,
        string $errorCode,
        string $failureReason,
    ): ProcessingRun {
        $documentId = ProcessingRun::query()
            ->whereKey($processingRunId)
            ->value('document_id');

        if ($documentId === null) {
            throw new LogicException(
                'Processing run does not exist for failure finalization.',
            );
        }

        return DB::transaction(function () use (
            $processingRunId,
            $documentId,
            $errorCode,
            $failureReason,
        ): ProcessingRun {
            // نقفل الوثيقة أولاً ثم الـ Run حتى يكون ترتيب الأقفال ثابتاً.
            $document = Document::query()
                ->lockForUpdate()
                ->findOrFail($documentId);

            $processingRun = ProcessingRun::query()
                ->lockForUpdate()
                ->findOrFail($processingRunId);

            if (
                (int) $processingRun->document_id
                !== (int) $document->getKey()
            ) {
                throw new LogicException(
                    'Processing run does not belong to the document being finalized.',
                );
            }

            // Run ناجحة أصبحت Indexed لا يجوز لخطأ متأخر أن يحولها إلى Failed.
            if ($processingRun->status === ProcessingRunStatus::Indexed) {
                return $processingRun;
            }

            // إعادة الاستدعاء بعد الفشل لا تعيد كتابة السبب أو failed_at.
            if ($processingRun->status === ProcessingRunStatus::Failed) {
                return $processingRun;
            }

            $processingRun->status = ProcessingRunStatus::Failed;
            $processingRun->error_code = $errorCode;
            $processingRun->failure_reason = $failureReason;
            $processingRun->failed_at = now();
            $processingRun->save();

            $this->documentStatusProjector->project(
                document: $document,
                processingRun: $processingRun,
            );

            return $processingRun;
        }, 3);
    }
}
