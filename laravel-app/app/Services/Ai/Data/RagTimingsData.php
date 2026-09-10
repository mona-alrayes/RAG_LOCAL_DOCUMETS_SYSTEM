<?php

namespace App\Services\Ai\Data;

final readonly class RagTimingsData
{
    public function __construct(
        public ?float $queryEmbedding,
        public ?float $retrieval,
        public ?float $fusion,
        public ?float $reranking,
        public ?float $contextBuilding,
        public ?float $generation,
        public float $total,
    ) {}

    /**
     * @return array<string, float|null>
     */
    public function toArray(): array
    {
        return [
            'query_embedding' => $this->queryEmbedding,
            'retrieval' => $this->retrieval,
            'fusion' => $this->fusion,
            'reranking' => $this->reranking,
            'context_building' => $this->contextBuilding,
            'generation' => $this->generation,
            'total' => $this->total,
        ];
    }
}
