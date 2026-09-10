<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\ProcessingRun;
use LogicException;

class DocumentStatusProjector
{
    public function project(
        Document $document,
        ProcessingRun $processingRun,
    ): void {
        $this->assertRunBelongsToDocument($document, $processingRun);

        if ($document->active_processing_run_id !== null) {
            $this->assertValidActiveRun($document);

            $document->status = DocumentStatus::Ready;

            if ($document->isDirty()) {
                $document->save();
            }

            return;
        }

        $document->status = match ($processingRun->status) {
            ProcessingRunStatus::Pending => DocumentStatus::Queued,
            ProcessingRunStatus::Processing => DocumentStatus::Processing,
            ProcessingRunStatus::Indexing => DocumentStatus::Indexing,
            ProcessingRunStatus::Failed => DocumentStatus::Failed,
            ProcessingRunStatus::Indexed => throw new LogicException(
                'Indexed processing run must be activated before the document becomes ready.',
            ),
        };

        if ($document->isDirty()) {
            $document->save();
        }
    }

    public function projectActivation(
        Document $document,
        ProcessingRun $processingRun,
    ): void {
        $this->assertRunBelongsToDocument($document, $processingRun);

        if ($processingRun->status !== ProcessingRunStatus::Indexed) {
            throw new LogicException(
                'Only an indexed processing run may make a document ready.',
            );
        }

        $document->active_processing_run_id = $processingRun->getKey();
        $document->status = DocumentStatus::Ready;
        if ($document->isDirty()) {
            $document->save();
        }
    }

    private function assertRunBelongsToDocument(
        Document $document,
        ProcessingRun $processingRun,
    ): void {
        if (
            (int) $processingRun->document_id
            !== (int) $document->getKey()
        ) {
            throw new LogicException(
                'Processing run does not belong to the document being projected.',
            );
        }
    }

    private function assertValidActiveRun(Document $document): void
    {
        $activeProcessingRun = ProcessingRun::query()
            ->find($document->active_processing_run_id);

        if (
            ! $activeProcessingRun instanceof ProcessingRun
            || (int) $activeProcessingRun->document_id
            !== (int) $document->getKey()
            || $activeProcessingRun->status
            !== ProcessingRunStatus::Indexed
        ) {
            throw new LogicException(
                'Document active processing run is invalid for status projection.',
            );
        }
    }
}
