<?php

namespace App\Services\Ai\Data;

use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;

final readonly class ProcessDocumentResult
{
    /**
     * @param  array<string, mixed>  $profileSnapshot
     * @param  array<string, int>  $stageTimingsMs
     * @param  list<array<string, mixed>>  $warnings
     */
    public function __construct(
        public int $documentId,
        public int $processingRunId,
        public ProcessingProfile $profile,
        public ProcessingRunStatus $status,
        public string $qdrantCollection,
        public array $profileSnapshot,
        public ?int $totalPages,
        public int $totalChunks,
        public int $vectorCount,
        public ?int $vectorDimension,
        public array $stageTimingsMs,
        public array $warnings,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidatedResponse(array $validated): self
    {
        return new self(
            documentId: (int) $validated['document_id'],
            processingRunId: (int) $validated['processing_run_id'],
            profile: ProcessingProfile::from(
                (string) $validated['profile'],
            ),
            status: ProcessingRunStatus::from(
                (string) $validated['status'],
            ),
            qdrantCollection: (string) $validated['qdrant_collection'],
            profileSnapshot: $validated['profile_snapshot'],
            totalPages: isset($validated['total_pages'])
                ? (int) $validated['total_pages']
                : null,
            totalChunks: (int) $validated['total_chunks'],
            vectorCount: (int) $validated['vector_count'],
            vectorDimension: isset($validated['vector_dimension'])
                ? (int) $validated['vector_dimension']
                : null,
            stageTimingsMs: $validated['stage_timings_ms'],
            warnings: $validated['warnings'],
        );
    }
}
