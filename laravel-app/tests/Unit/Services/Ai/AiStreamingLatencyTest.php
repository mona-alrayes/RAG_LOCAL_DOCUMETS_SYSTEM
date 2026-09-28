<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AiServiceClient;
use App\Services\Ai\Data\RagQueryRequestData;
use App\Services\Ai\Data\RagTokenEventData;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AiStreamingLatencyTest extends TestCase
{
    public function test_first_token_arrives_before_a_small_response_finishes(): void
    {
        $server = new Process([PHP_BINARY, base_path('tests/Fixtures/streaming_ai_server.php')]);
        $server->start();
        try {
            $output = '';
            $deadline = microtime(true) + 3;
            do {
                $output .= $server->getIncrementalOutput();
                if (str_contains($output, "\n")) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline && $server->isRunning());
            $address = trim($output);
            $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $address, $server->getErrorOutput());
            config([
                'services.ai_service.base_url' => 'http://'.$address,
                'services.ai_service.internal_api_key' => 'fixture-only',
                'services.ai_service.timeout' => 5,
            ]);
            $started = microtime(true);
            $events = app(AiServiceClient::class)->streamRagQuery(new RagQueryRequestData(
                userId: 1, question: 'test', documentTargets: [], recentCompletedTurns: [],
            ));
            $first = $events->current();
            $this->assertInstanceOf(RagTokenEventData::class, $first);
            $this->assertSame('first', $first->content);
            $this->assertLessThan(1.0, microtime(true) - $started, 'The client waited for response completion before yielding the first token.');
        } finally {
            $server->stop(0);
        }
    }
}
