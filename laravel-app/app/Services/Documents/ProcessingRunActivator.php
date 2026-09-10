<?php

namespace App\Services\Documents;

use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\ProcessingRun;
use Illuminate\Support\Facades\DB;
use LogicException;

class ProcessingRunActivator
{
    public function __construct(
        private readonly DocumentStatusProjector $documentStatusProjector,
    ) {}

    public function activate(ProcessingRun $processingRun): ?ProcessingRun
    {
        $processingRunId = (int) $processingRun->getKey();
        $documentId = (int) $processingRun->document_id;

        return DB::transaction(
            function () use ($processingRunId, $documentId): ?ProcessingRun {
                $document = Document::query()
                    ->lockForUpdate()
                    ->findOrFail($documentId);

                $lockedProcessingRun = ProcessingRun::query()
                    ->lockForUpdate()
                    ->findOrFail($processingRunId);

                if (
                    (int) $lockedProcessingRun->document_id
                    !== (int) $document->getKey()
                ) {
                    throw new LogicException(
                        'Processing run does not belong to the document being activated.',
                    );
                }

                if ($lockedProcessingRun->status !== ProcessingRunStatus::Indexed) {
                    throw new LogicException(
                        'Only an indexed processing run may be activated.',
                    );
                }

                $previousProcessingRun = null;

                if ($document->active_processing_run_id !== null) {
                    $previousProcessingRun = ProcessingRun::query()
                        ->lockForUpdate()
                        ->find($document->active_processing_run_id);

                    if (
                        ! $previousProcessingRun instanceof ProcessingRun
                        || (int) $previousProcessingRun->document_id
                        !== (int) $document->getKey()
                        || $previousProcessingRun->status
                        !== ProcessingRunStatus::Indexed
                    ) {
                        throw new LogicException(
                            'Document active processing run is invalid.',
                        );
                    }

                    if (
                        (int) $previousProcessingRun->getKey()
                        === $processingRunId
                    ) {
                        throw new LogicException(
                            'Processing run is already active for the document.',
                        );
                    }
                }

                $this->documentStatusProjector->projectActivation(
                    $document,
                    $lockedProcessingRun,
                );

                return $previousProcessingRun;
            },
        );
    }
}
