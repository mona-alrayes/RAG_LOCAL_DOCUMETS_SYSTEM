<?php

namespace App\Services\Ai\Data;

final readonly class RagCompletedEventData implements RagStreamEventData
{
    /**
     * @param  list<RagSourceData>  $sources
     */
    public function __construct(
        public string $answer,
        public array $sources,
        public RagTimingsData $timings,
    ) {}
}
