<?php

namespace App\Http\Resources;

use App\Services\Documents\Presentation\Data\ProcessingRunSummaryData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcessingRunSummaryResource extends JsonResource
{
    /**
     * Transform the processing run summary into its presentation representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProcessingRunSummaryData $run */
        $run = $this->resource;

        return [
            'id' => $run->id,

            'profile' => [
                'code' => $run->profile->value,
                'label' => __(
                    'documents.processing_run.profile.'.$run->profile->value
                ),
            ],

            'status' => [
                'code' => $run->status->value,
                'label' => __(
                    'documents.processing_run.status.'.$run->status->value
                ),
            ],

            'kind' => [
                'code' => $run->kind->value,
                'label' => __(
                    'documents.processing_run.kind.'.$run->kind->value
                ),
            ],

            'queued_at' => $run->queuedAt->toISOString(),
            'started_at' => $run->startedAt?->toISOString(),
            'indexing_started_at' => $run->indexingStartedAt?->toISOString(),
            'indexed_at' => $run->indexedAt?->toISOString(),
            'failed_at' => $run->failedAt?->toISOString(),
        ];
    }
}
