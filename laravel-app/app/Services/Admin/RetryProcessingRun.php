<?php

namespace App\Services\Admin;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Documents\DocumentProcessingDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryProcessingRun
{
    public function execute(User $actor, int $runId): ProcessingRun
    {
        AdminAccess::authorize($actor);
        $candidate = ProcessingRun::query()->findOrFail($runId);
        try {
            return DB::transaction(function () use ($candidate, $actor): ProcessingRun {
                $document = Document::query()->lockForUpdate()->findOrFail($candidate->document_id);
                $run = ProcessingRun::query()->lockForUpdate()->findOrFail($candidate->id);
                if ($run->status !== ProcessingRunStatus::Failed
                    || ! in_array($document->status, [DocumentStatus::Failed, DocumentStatus::Ready], true)
                    || $document->processingRuns()->where('id', '>', $run->id)->exists()
                    || $document->processingRuns()->whereIn('status', ['pending', 'processing', 'indexing'])->exists()) {
                    throw ValidationException::withMessages(['retry' => 'Only the latest failed attempt on a safe, idle document can be retried.']);
                }
                $newRun = app(DocumentProcessingDispatcher::class)->dispatchFailedRetry($document, $run);
                AdminAudit::record($actor->id, 'processing.retry', 'processing_run', $run->id);

                return $newRun;
            });
        } catch (\Throwable $exception) {
            AdminAudit::record($actor->id, 'processing.retry', 'processing_run', $candidate->id, 'failed');
            throw $exception;
        }
    }
}
