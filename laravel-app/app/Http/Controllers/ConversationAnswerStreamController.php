<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversations\Streaming\ConversationAnswerStreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ConversationAnswerStreamController extends Controller
{
    public function __invoke(
        Request $request,
        Conversation $conversation,
        Message $message,
        ConversationAnswerStreamService $streamService,
    ): StreamedResponse {
        Gate::authorize(
            'view',
            $conversation,
        );

        abort_unless(
            (int) $message->conversation_id
                === (int) $conversation->getKey()
            && $message->role
                === MessageRole::Assistant,
            404,
        );

        $cursor = $request->header(
            'Last-Event-ID',
            '0-0',
        );

        if (
            ! is_string($cursor)
            || preg_match(
                '/^\d+-\d+$/',
                $cursor,
            ) !== 1
        ) {
            $cursor = '0-0';
        }

        return response()->stream(
            function () use (
                $conversation,
                $message,
                $streamService,
                $cursor,
            ): void {
                set_time_limit(360);

                $lastEventId = $cursor;
                $startedAt = microtime(true);

                echo "retry: 1500\n\n";
                $this->flushOutput();

                while (
                    ! connection_aborted()
                    && (
                        microtime(true) - $startedAt
                    ) < 360
                ) {
                    $events =
                        $streamService->readAfter(
                            assistantMessageId: (int) $message
                                ->getKey(),
                            cursor: $lastEventId,
                            blockMilliseconds: 10000,
                        );

                    foreach ($events as $event) {
                        $lastEventId =
                            $event['id'];

                        $this->sendEvent(
                            type: $event['type'],
                            content: $event['content'],
                            id: $event['id'],
                        );

                        if (
                            $event['type']
                                === 'completed'
                            || $event['type']
                                === 'failed'
                        ) {
                            return;
                        }
                    }

                    /*
                     * Redis transports live events only.
                     * MySQL remains the terminal source
                     * of truth if an event was missed.
                     */
                    $status = Message::query()
                        ->whereKey(
                            $message->getKey(),
                        )
                        ->where(
                            'conversation_id',
                            $conversation->getKey(),
                        )
                        ->value('status');

                    if (
                        $status
                        === MessageStatus::Completed->value
                    ) {
                        $this->sendEvent(
                            'completed',
                        );

                        return;
                    }

                    if (
                        $status
                        === MessageStatus::Failed->value
                    ) {
                        $this->sendEvent(
                            'failed',
                        );

                        return;
                    }

                    echo ": keep-alive\n\n";
                    $this->flushOutput();
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function sendEvent(
        string $type,
        string $content = '',
        ?string $id = null,
    ): void {
        if ($id !== null) {
            echo 'id: '.$id."\n";
        }

        echo 'event: '.$type."\n";

        echo 'data: '.json_encode(
            [
                'content' => $content,
            ],
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
        )."\n\n";

        $this->flushOutput();
    }

    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }
}
