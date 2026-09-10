<?php

namespace App\Services\Ai\Data;

final readonly class RagQueryRequestData
{
    /**
     * @param  list<array{
     *     document_id: int,
     *     processing_run_id: int,
     *     processing_profile: string
     * }>  $documentTargets
     * @param  list<array{user: string, assistant: string}>  $recentCompletedTurns
     */
    public function __construct(
        public int $userId,
        public string $question,
        public array $documentTargets,
        public array $recentCompletedTurns,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'question' => $this->question,
            'document_targets' => $this->documentTargets,
            'recent_completed_turns' => $this->recentCompletedTurns,
        ];
    }
}
