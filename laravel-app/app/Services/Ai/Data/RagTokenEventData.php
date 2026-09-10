<?php

namespace App\Services\Ai\Data;

final readonly class RagTokenEventData implements RagStreamEventData
{
    public function __construct(
        public string $content,
    ) {}
}
