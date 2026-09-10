<?php

namespace App\Services\Documents;

use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Services\Ai\Data\ProcessDocumentResult;
use Illuminate\Support\Facades\DB;
use LogicException;

class ProcessingRunResultPersister
{
    public function persist(
        int $processingRunId,
        ProcessDocumentResult $result,
    ): ProcessingRun {
        return DB::transaction(function () use (
            $processingRunId,
            $result,
        ): ProcessingRun {
            $document = Document::query()
                ->lockForUpdate()
                ->findOrFail($result->documentId);

            $processingRun = ProcessingRun::query()
                ->lockForUpdate()
                ->findOrFail($processingRunId);

            $this->assertResultMatchesRun($processingRun, $document, $result);

            if ($processingRun->status !== ProcessingRunStatus::Indexing) {
                throw new LogicException(
                    'Only an indexing run may persist a successful processing result.',
                );
            }

            if ($result->status !== ProcessingRunStatus::Indexed) {
                throw new LogicException(
                    'Only an indexed processing result may be persisted as successful.',
                );
            }

            $processingRun->fill([
                'profile_snapshot' => $result->profileSnapshot,
                'total_pages' => $result->totalPages,
                'total_chunks' => $result->totalChunks,
                'vector_count' => $result->vectorCount,
                'vector_dimension' => $result->vectorDimension,
                'stage_timings_ms' => $result->stageTimingsMs,
                'warnings' => $result->warnings,
                'qdrant_collection' => $result->qdrantCollection,
                'status' => $result->status,
                'indexed_at' => now(),
            ]);

            $processingRun->save();

            return $processingRun;
        }, 3);
    }

    private function assertResultMatchesRun(
        ProcessingRun $processingRun,
        Document $document,
        ProcessDocumentResult $result,
    ): void {
        if ((int) $processingRun->getKey() !== $result->processingRunId) {
            throw new LogicException(
                'Process document result does not belong to the processing run.',
            );
        }

        if ((int) $processingRun->document_id !== $result->documentId) {
            throw new LogicException(
                'Process document result does not belong to the processing run document.',
            );
        }

        if ((int) $processingRun->document_id !== (int) $document->getKey()) {
            throw new LogicException(
                'Process document result document does not own the processing run.',
            );
        }

        if ($processingRun->profile !== $result->profile) {
            throw new LogicException(
                'Process document result profile does not match the processing run profile.',
            );
        }

    }
}
