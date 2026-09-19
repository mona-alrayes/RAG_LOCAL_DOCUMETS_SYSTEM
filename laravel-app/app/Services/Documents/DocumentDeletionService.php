<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingRunStatus;
use App\Exceptions\DocumentDeletionException;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Services\Ai\AiServiceClient;
use Illuminate\Support\Facades\DB;
use Throwable;

class DocumentDeletionService
{
    public function __construct(
        private readonly AiServiceClient $aiServiceClient,
        private readonly DocumentStorageService $documentStorageService,
    ) {}

    public function delete(Document $document): void
    {
        DB::transaction(function () use ($document): void {
            $lockedDocument = Document::query()
                ->lockForUpdate()
                ->findOrFail($document->id);

            if (in_array($lockedDocument->status, [DocumentStatus::Pending, DocumentStatus::Scanning], true)) {
                throw DocumentDeletionException::processingInProgress();
            }

            $processingRuns = $lockedDocument
                ->processingRuns()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $hasProcessingInProgress = $processingRuns->contains(
                static fn (ProcessingRun $processingRun): bool => in_array(
                    $processingRun->status,
                    [
                        ProcessingRunStatus::Pending,
                        ProcessingRunStatus::Processing,
                        ProcessingRunStatus::Indexing,
                    ],
                    true,
                ),
            );

            if ($hasProcessingInProgress) {
                throw DocumentDeletionException::processingInProgress();
            }

            // Commit the tombstone before irreversible external cleanup. Retrying
            // deletion reuses the retained run inventory and private file path.
            $lockedDocument->forceFill([
                'deletion_started_at' => $lockedDocument->deletion_started_at ?? now(),
                'active_processing_run_id' => null,
                'status' => DocumentStatus::Failed,
            ])->save();
        });

        DB::transaction(function () use ($document): void {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            $processingRuns = $lockedDocument->processingRuns()->orderBy('id')->lockForUpdate()->get();

            foreach ($processingRuns as $processingRun) {
                $this->aiServiceClient->deleteProcessingRunPoints(
                    userId: (int) $lockedDocument->user_id,
                    documentId: (int) $lockedDocument->getKey(),
                    processingRunId: (int) $processingRun->getKey(),
                    processingProfile: $processingRun->profile,
                );
            }

            try {
                $this->documentStorageService->delete(
                    $lockedDocument,
                );
            } catch (Throwable $exception) {
                throw DocumentDeletionException::storageCleanupFailed(
                    $exception,
                );
            }

            if ($lockedDocument->active_processing_run_id !== null) {
                $lockedDocument->forceFill([
                    'active_processing_run_id' => null,
                ])->save();
            }

            $lockedDocument->processingRuns()->delete();

            $lockedDocument->delete();
        });
    }
}
