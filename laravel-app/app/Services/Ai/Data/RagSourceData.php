<?php

namespace App\Services\Ai\Data;

use App\Enums\ProcessingProfile;

final readonly class RagSourceData
{
    public function __construct(
        public string $pointId,
        public float $retrievalScore,
        public ?float $rerankerScore,
        public int $documentId,
        public int $processingRunId,
        public ProcessingProfile $processingProfile,
        public int $chunkIndex,
        public string $text,
        public ?int $page,
        public ?string $section,
        public string $source,
    ) {}
}
