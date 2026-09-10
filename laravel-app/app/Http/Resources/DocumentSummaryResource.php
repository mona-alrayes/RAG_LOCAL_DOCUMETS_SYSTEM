<?php

namespace App\Http\Resources;

use App\Services\Documents\Presentation\Data\DocumentSummaryData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentSummaryResource extends JsonResource
{
    /**
     * Transform the document summary into its presentation representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DocumentSummaryData $document */
        $document = $this->resource;

        return [
            'id' => $document->id,
            'title' => $document->title,
            'original_name' => $document->originalName,

            'file_type' => [
                'code' => $document->fileType->value,
                'label' => strtoupper($document->fileType->value),
            ],

            'file_size' => $document->fileSize,

            'document_availability' => [
                'code' => $document->availability->value,
                'label' => __(
                    'documents.availability.'.$document->availability->value
                ),
            ],

            'active_run' => $document->activeRun === null
                ? null
                : new ProcessingRunSummaryResource($document->activeRun),

            'latest_attempt' => $document->latestAttempt === null
                ? null
                : new ProcessingRunSummaryResource($document->latestAttempt),

            'reprocessing_in_progress' => $document->reprocessingInProgress,
            'poll_required' => $document->pollRequired,

            'safe_failure' => $document->safeFailure === null
                ? null
                : [
                    'message' => __('documents.failure.processing_failed'),
                ],

            'allowed_actions' => [
                'download' => $document->canDownload,
                'reprocess' => $document->canReprocess,
                'delete' => $document->canDelete,
            ],

            'created_at' => $document->createdAt->toISOString(),
            'updated_at' => $document->updatedAt->toISOString(),
        ];
    }
}
