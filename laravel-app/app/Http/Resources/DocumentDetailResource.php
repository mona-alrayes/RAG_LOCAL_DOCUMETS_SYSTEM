<?php

namespace App\Http\Resources;

use App\Services\Documents\Presentation\Data\DocumentDetailData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DocumentDetailData $document */
        $document = $this->resource;

        return [
            'summary' => new DocumentSummaryResource(
                $document->summary,
            ),

            'processing_timeline' => collect($document->timeline)
                ->map(fn ($run): array => [
                    'id' => $run->id,

                    'profile' => [
                        'code' => $run->profile->value,
                        'label' => __(
                            'documents.processing_run.profile.'
                            .$run->profile->value,
                        ),
                    ],

                    'status' => [
                        'code' => $run->status->value,
                        'label' => __(
                            'documents.processing_run.status.'
                            .$run->status->value,
                        ),
                    ],

                    'kind' => [
                        'code' => $run->kind->value,
                        'label' => __(
                            'documents.processing_run.kind.'
                            .$run->kind->value,
                        ),
                    ],

                    'is_active' => $run->isActive,

                    'total_pages' => $run->totalPages,
                    'total_chunks' => $run->totalChunks,

                    'stage_timings_ms' => $run->stageTimingsMs,
                    'warnings' => $run->warnings,

                    'queued_at' => $run->queuedAt->toISOString(),
                    'started_at' => $run->startedAt?->toISOString(),
                    'indexing_started_at' => $run
                        ->indexingStartedAt?->toISOString(),
                    'indexed_at' => $run->indexedAt?->toISOString(),
                    'failed_at' => $run->failedAt?->toISOString(),
                ])
                ->all(),
        ];
    }
}
