<?php

namespace App\Jobs;

use App\Services\Conversations\AskConversationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Tries(1)]
#[Timeout(330)]
class AskConversationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $userId,
        public int $conversationId,
        public int $userMessageId,
        public int $assistantMessageId,
    ) {}

    public function handle(
        AskConversationService $service,
    ): void {
        $service->execute(
            userId: $this->userId,
            conversationId: $this->conversationId,
            userMessageId: $this->userMessageId,
            assistantMessageId: $this->assistantMessageId,
        );
    }
}
