<?php

namespace App\Services\Conversations\Streaming;

use Illuminate\Support\Facades\Redis;
use RuntimeException;

class ConversationAnswerStreamService
{
    private const TTL_SECONDS = 600;

    private const READ_COUNT = 50;

    /**
     * @return list<array{
     *     id: string,
     *     type: string,
     *     content: string
     * }>
     */
    public function readAfter(
        int $assistantMessageId,
        string $cursor,
        int $blockMilliseconds = 10000,
    ): array {
        $streams = Redis::connection()->xread(
            [$this->key($assistantMessageId) => $cursor],
            self::READ_COUNT,
            $blockMilliseconds,
        );

        if (! is_array($streams) || $streams === []) {
            return [];
        }

        $events = [];

        foreach ($streams as $streamEntries) {
            if (! is_array($streamEntries)) {
                continue;
            }

            foreach ($streamEntries as $id => $fields) {
                if (
                    ! is_string($id)
                    || ! is_array($fields)
                    || ! is_string($fields['type'] ?? null)
                    || ! is_string($fields['content'] ?? null)
                    || ! in_array(
                        $fields['type'],
                        ['token', 'completed', 'failed'],
                        true,
                    )
                ) {
                    continue;
                }

                $events[] = [
                    'id' => $id,
                    'type' => $fields['type'],
                    'content' => $fields['content'],
                ];
            }

            break;
        }

        return $events;
    }

    public function publishToken(
        int $assistantMessageId,
        string $content,
    ): void {
        $this->publish(
            assistantMessageId: $assistantMessageId,
            type: 'token',
            content: $content,
        );
    }

    public function publishCompleted(
        int $assistantMessageId,
    ): void {
        $this->publish(
            assistantMessageId: $assistantMessageId,
            type: 'completed',
        );
    }

    public function publishFailed(
        int $assistantMessageId,
    ): void {
        $this->publish(
            assistantMessageId: $assistantMessageId,
            type: 'failed',
        );
    }

    public function reset(int $assistantMessageId): void
    {
        Redis::connection()->del(
            $this->key($assistantMessageId),
        );
    }

    private function publish(
        int $assistantMessageId,
        string $type,
        string $content = '',
    ): void {
        $redis = Redis::connection();
        $key = $this->key($assistantMessageId);

        $id = $redis->xadd(
            $key,
            '*',
            [
                'type' => $type,
                'content' => $content,
            ],
        );

        if (! is_string($id) || $id === '') {
            throw new RuntimeException(
                'Unable to publish conversation answer stream event.',
            );
        }

        $redis->expire($key, self::TTL_SECONDS);
    }

    private function key(int $assistantMessageId): string
    {
        return 'conversation-answer:'
            .$assistantMessageId
            .':events';
    }
}
