<?php

namespace App\Services\Documents;

use App\Enums\ProcessingRunStatus;
use App\Exceptions\InvalidProcessingRunTransition;
use App\Models\Document;
use App\Models\ProcessingRun;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ProcessingRunProgressor
{
    public function __construct(
        private readonly DocumentStatusProjector $documentStatusProjector,
    ) {}

    public function markProcessingStarted(int $processingRunId): ProcessingRun
    {
        $documentId = ProcessingRun::query()
            ->whereKey($processingRunId)
            ->value('document_id');

        if ($documentId === null) {
            throw (new ModelNotFoundException)->setModel(
                ProcessingRun::class,
                [$processingRunId],
            );
        }

        return DB::transaction(function () use (
            $documentId,
            $processingRunId,
        ): ProcessingRun {
            $document = Document::query()
                ->lockForUpdate()
                ->findOrFail($documentId);

            $processingRun = ProcessingRun::query()
                ->lockForUpdate()
                ->findOrFail($processingRunId);

            $this->assertOwnership($document, $processingRun);

            if ($processingRun->status === ProcessingRunStatus::Pending) {
                $processingRun->status = ProcessingRunStatus::Processing;
            } elseif (! in_array($processingRun->status, [
                ProcessingRunStatus::Processing,
                ProcessingRunStatus::Indexing,
            ], true)) {
                throw InvalidProcessingRunTransition::toProcessing();
            }

            if (
                $processingRun->status !== ProcessingRunStatus::Indexing
                && $processingRun->started_at === null
            ) {
                $processingRun->started_at = now();
            }

            if ($processingRun->isDirty()) {
                $processingRun->save();
            }

            $this->documentStatusProjector->project($document, $processingRun);

            return $processingRun;
        }, 3);
    }

    public function markIndexingStarted(
        int $userId,
        int $documentId,
        int $processingRunId,
    ): ProcessingRun {
        return DB::transaction(function () use (
            $userId,
            $documentId,
            $processingRunId,
        ): ProcessingRun {
            $document = Document::query()
                ->lockForUpdate()
                ->findOrFail($documentId);

            if ((int) $document->user_id !== $userId) {
                throw (new ModelNotFoundException)->setModel(
                    Document::class,
                    [$documentId],
                );
            }

            $processingRun = ProcessingRun::query()
                ->lockForUpdate()
                ->findOrFail($processingRunId);

            $this->assertOwnership($document, $processingRun);

            if ($processingRun->status === ProcessingRunStatus::Processing) {
                $processingRun->status = ProcessingRunStatus::Indexing;
            } elseif ($processingRun->status !== ProcessingRunStatus::Indexing) {
                throw InvalidProcessingRunTransition::toIndexing();
            }

            if ($processingRun->indexing_started_at === null) {
                $processingRun->indexing_started_at = now();
            }

            if ($processingRun->isDirty()) {
                $processingRun->save();
            }

            $this->documentStatusProjector->project($document, $processingRun);

            return $processingRun;
        }, 3);
    }

    private function assertOwnership(
        Document $document,
        ProcessingRun $processingRun,
    ): void {
        if ((int) $processingRun->document_id === (int) $document->getKey()) {
            return;
        }

        throw (new ModelNotFoundException)->setModel(
            ProcessingRun::class,
            [$processingRun->getKey()],
        );
    }
}
