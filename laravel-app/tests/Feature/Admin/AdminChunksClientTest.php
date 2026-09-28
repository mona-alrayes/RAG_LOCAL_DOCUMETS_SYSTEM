<?php

namespace Tests\Feature\Admin;

use App\Enums\ProcessingProfile;
use App\Exceptions\AiServiceException;
use App\Services\Ai\AiServiceClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminChunksClientTest extends TestCase
{
    public function test_client_sends_trusted_scope_and_strips_unapproved_payload_keys(): void
    {
        config(['services.ai_service.base_url' => 'http://ai.test', 'services.ai_service.internal_api_key' => 'test-key']);
        Http::fake(['*/api/v1/admin/chunks' => Http::response(['document_id' => 12, 'processing_run_id' => 81, 'processing_profile' => 'cloud', 'total' => 1, 'next_cursor' => null, 'vectors' => [99], 'chunks' => [['point_id' => 'abc', 'chunk_index' => 0, 'text' => 'Safe text', 'source' => 'notes.txt', 'page' => null, 'section' => null, 'vector' => [88]]]])]);
        $data = app(AiServiceClient::class)->adminChunks(7, 12, 81, ProcessingProfile::Cloud);
        $this->assertSame('Safe text', $data['chunks'][0]['text']);
        $this->assertStringNotContainsString('vector', json_encode($data));
        Http::assertSent(fn ($request) => $request['user_id'] === 7 && $request['document_id'] === 12 && $request['processing_run_id'] === 81 && $request['processing_profile'] === 'cloud' && $request->hasHeader('X-Internal-API-Key', 'test-key'));
    }

    public function test_wrong_run_response_is_rejected(): void
    {
        config(['services.ai_service.base_url' => 'http://ai.test', 'services.ai_service.internal_api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['document_id' => 12, 'processing_run_id' => 82, 'processing_profile' => 'cloud', 'total' => 0, 'next_cursor' => null, 'chunks' => []])]);
        $this->expectException(AiServiceException::class);
        app(AiServiceClient::class)->adminChunks(7, 12, 81, ProcessingProfile::Cloud);
    }
}
