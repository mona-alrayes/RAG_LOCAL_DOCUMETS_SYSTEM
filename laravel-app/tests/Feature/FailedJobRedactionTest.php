<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FailedJobRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_job_keeps_replay_payload_without_raw_exception_text(): void
    {
        config(['queue.failed.driver' => 'database-uuids', 'queue.failed.database' => config('database.default')]);
        $uuid = (string) Str::uuid();
        $payload = json_encode(['uuid' => $uuid, 'data' => ['processingRunId' => 123]]);
        app('queue.failer')->log('redis', 'ai-local', $payload, new RuntimeException('private-provider-token; confidential prompt', 0, new RuntimeException('private-chain')));
        $record = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        $this->assertStringNotContainsString('private-provider-token', $record->exception);
        $this->assertStringNotContainsString('confidential prompt', $record->exception);
        $this->assertStringNotContainsString('Stack trace', $record->exception);
        $this->assertStringNotContainsString('private-chain', $record->exception);
        $this->assertStringContainsString('RuntimeException', $record->exception);
        $this->assertSame($payload, app('queue.failer')->find($uuid)->payload);
    }
}
