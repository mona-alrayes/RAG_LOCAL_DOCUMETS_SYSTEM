<?php

namespace Tests\Unit\Services\Ai;

use App\Enums\FileType;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Exceptions\AiServiceException;
use App\Services\Ai\AiServiceClient;
use App\Services\Ai\Data\ProcessDocumentRequestData;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AiServiceClientTest extends TestCase
{
    public function test_health_returns_payload_and_sends_required_internal_headers(): void
    {
        config([
            'services.ai_service.base_url' => 'http://ai-service.test',
            'services.ai_service.internal_api_key' => 'test-secret',
            'services.ai_service.connect_timeout' => 10,
            'services.ai_service.timeout' => 600,
        ]);

        Http::fake([
            'http://ai-service.test/api/v1/health' => Http::response([
                'status' => 'ok',
            ]),
        ]);

        $result = app(AiServiceClient::class)->health();

        $this->assertSame([
            'status' => 'ok',
        ], $result);

        Http::assertSent(function (Request $request): bool {
            $correlationId = $request->header('X-Correlation-ID')[0] ?? null;

            return $request->url() === 'http://ai-service.test/api/v1/health'
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('X-Internal-API-Key', 'test-secret')
                && is_string($correlationId)
                && Str::isUuid($correlationId);
        });
    }

    public function test_health_wraps_remote_http_failure_in_ai_service_exception(): void
    {
        config([
            'services.ai_service.base_url' => 'http://ai-service.test',
            'services.ai_service.internal_api_key' => 'test-secret',
        ]);

        Http::fake([
            'http://ai-service.test/api/v1/health' => Http::response(
                ['detail' => 'Unauthorized'],
                401,
                ['X-Correlation-ID' => 'remote-correlation-id'],
            ),
        ]);

        try {
            app(AiServiceClient::class)->health();

            $this->fail('Expected AiServiceException was not thrown.');
        } catch (AiServiceException $exception) {
            $this->assertSame('Unauthorized', $exception->getMessage());
            $this->assertSame(401, $exception->statusCode);
            $this->assertSame(
                'remote-correlation-id',
                $exception->correlationId,
            );
        }
    }

    public function test_health_wraps_connection_failure_in_ai_service_exception(): void
    {
        config([
            'services.ai_service.base_url' => 'http://ai-service.test',
            'services.ai_service.internal_api_key' => 'test-secret',
        ]);

        Http::fake([
            'http://ai-service.test/api/v1/health' => Http::failedConnection(),
        ]);

        try {
            app(AiServiceClient::class)->health();

            $this->fail('Expected AiServiceException was not thrown.');
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'Unable to connect to the AI service.',
                $exception->getMessage(),
            );

            $this->assertNull($exception->statusCode);
            $this->assertTrue(Str::isUuid($exception->correlationId));
            $this->assertInstanceOf(
                ConnectionException::class,
                $exception->getPrevious(),
            );
        }
    }

    public function test_health_rejects_invalid_json_response(): void
    {
        config([
            'services.ai_service.base_url' => 'http://ai-service.test',
            'services.ai_service.internal_api_key' => 'test-secret',
        ]);

        Http::fake([
            'http://ai-service.test/api/v1/health' => Http::response(
                'not-json',
                200,
                [
                    'Content-Type' => 'text/plain',
                    'X-Correlation-ID' => 'invalid-json-correlation-id',
                ],
            ),
        ]);

        try {
            app(AiServiceClient::class)->health();

            $this->fail('Expected AiServiceException was not thrown.');
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'AI service returned an invalid JSON response.',
                $exception->getMessage(),
            );

            $this->assertSame(200, $exception->statusCode);
            $this->assertSame(
                'invalid-json-correlation-id',
                $exception->correlationId,
            );
        }
    }

    public function test_capabilities_returns_payload(): void
    {
        config([
            'services.ai_service.base_url' => 'http://ai-service.test',
            'services.ai_service.internal_api_key' => 'test-secret',
        ]);

        Http::fake([
            'http://ai-service.test/api/v1/capabilities' => Http::response([
                'deployment_mode' => 'local',
                'profiles' => [
                    'cloud',
                    'hybrid_local',
                ],
            ]),
        ]);

        $result = app(AiServiceClient::class)->capabilities();

        $this->assertSame([
            'deployment_mode' => 'local',
            'profiles' => [
                'cloud',
                'hybrid_local',
            ],
        ], $result);

        Http::assertSent(
            fn (Request $request): bool => $request->url()
                === 'http://ai-service.test/api/v1/capabilities',
        );
    }

    public function test_health_fails_before_request_when_internal_api_key_is_missing(): void
    {
        config([
            'services.ai_service.base_url' => 'http://ai-service.test',
            'services.ai_service.internal_api_key' => null,
        ]);

        Http::fake();

        try {
            app(AiServiceClient::class)->health();

            $this->fail('Expected AiServiceException was not thrown.');
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'Missing required AI service configuration: services.ai_service.internal_api_key.',
                $exception->getMessage(),
            );

            $this->assertNull($exception->statusCode);
            $this->assertNull($exception->correlationId);
        }

        Http::assertNothingSent();
    }

    public function test_health_reads_structured_fastapi_error_payload(): void
    {
        config([
            'services.ai_service.base_url' => 'http://ai-service.test',
            'services.ai_service.internal_api_key' => 'test-secret',
        ]);

        Http::fake([
            'http://ai-service.test/api/v1/health' => Http::response(
                [
                    'error' => [
                        'code' => 'internal_error',
                        'message' => 'Processing service failed.',
                    ],
                    'correlation_id' => 'body-correlation-id',
                ],
                500,
            ),
        ]);

        try {
            app(AiServiceClient::class)->health();

            $this->fail('Expected AiServiceException was not thrown.');
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'Processing service failed.',
                $exception->getMessage(),
            );

            $this->assertSame(500, $exception->statusCode);

            $this->assertSame(
                'body-correlation-id',
                $exception->correlationId,
            );

            $this->assertSame(
                'internal_error',
                $exception->errorCode,
            );
        }
    }

    public function test_fastapi_array_detail_is_converted_to_safe_ai_service_exception(): void
    {
        config([
            'services.ai_service.base_url' => 'http://ai-service.test',
            'services.ai_service.internal_api_key' => 'test-secret',
        ]);

        Http::fake([
            'http://ai-service.test/api/v1/health' => Http::response(
                [
                    'detail' => [
                        [
                            'type' => 'missing',
                            'loc' => ['body', 'question'],
                            'msg' => 'Field required',
                        ],
                    ],
                ],
                422,
            ),
        ]);

        try {
            app(AiServiceClient::class)->health();

            $this->fail('Expected AiServiceException was not thrown.');
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'AI service request failed.',
                $exception->getMessage(),
            );

            $this->assertSame(422, $exception->statusCode);
        }
    }

    public function test_process_document_sends_multipart_contract_and_returns_typed_result(): void
    {
        $this->configureAiService();

        Storage::fake('documents');
        Storage::disk('documents')->put(
            '7/document.txt',
            "Document stream content.\n",
        );

        Http::fake([
            'http://ai-service.test/api/v1/documents/process' => Http::response(
                $this->validProcessDocumentResponse(),
            ),
        ]);

        $requestData = $this->processDocumentRequestData();

        $result = app(AiServiceClient::class)->processDocument(
            data: $requestData,
            filePath: '7/document.txt',
            fileName: 'original-notes.txt',
        );

        $this->assertSame(20, $result->documentId);
        $this->assertSame(30, $result->processingRunId);
        $this->assertSame(ProcessingProfile::Cloud, $result->profile);
        $this->assertSame(ProcessingRunStatus::Indexed, $result->status);
        $this->assertSame('rag_documents_cloud', $result->qdrantCollection);
        $this->assertSame(4, $result->vectorCount);

        Http::assertSent(function (Request $request): bool {
            $parts = collect($request->data())->keyBy('name');
            $correlationId = $request->header('X-Correlation-ID')[0] ?? null;
            $fileContents = $parts->get('file')['contents'] ?? null;

            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->isMultipart());
            $this->assertTrue(
                $request->hasFile('file', filename: 'original-notes.txt'),
            );
            $this->assertSame(10, $parts->get('user_id')['contents'] ?? null);
            $this->assertSame(
                20,
                $parts->get('document_id')['contents'] ?? null,
            );
            $this->assertSame(
                30,
                $parts->get('processing_run_id')['contents'] ?? null,
            );
            $this->assertSame(
                'cloud',
                $parts->get('processing_profile')['contents'] ?? null,
            );
            $this->assertSame(
                'txt',
                $parts->get('file_type')['contents'] ?? null,
            );
            $this->assertFalse(is_resource($fileContents));

            return $request->url()
                === 'http://ai-service.test/api/v1/documents/process'
                && $request->hasHeader(
                    'X-Internal-API-Key',
                    'test-secret',
                )
                && is_string($correlationId)
                && Str::isUuid($correlationId);
        });
    }

    public function test_process_document_rejects_missing_file_before_sending_request(): void
    {
        $this->configureAiService();

        Storage::fake('documents');
        Http::fake();

        try {
            app(AiServiceClient::class)->processDocument(
                data: $this->processDocumentRequestData(),
                filePath: '7/missing.txt',
                fileName: 'missing.txt',
            );

            $this->fail('Expected missing document to be rejected.');
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'Document file is missing from private storage.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }

    public function test_process_document_accepts_hybrid_local_response(): void
    {
        $this->configureAiService();

        Storage::fake('documents');
        Storage::disk('documents')->put('7/document.txt', 'content');

        $response = $this->validProcessDocumentResponse();
        $response['profile'] = 'hybrid_local';
        $response['qdrant_collection'] = 'rag_documents_hybrid_local';
        $response['profile_snapshot']['profile'] = 'hybrid_local';
        $response['profile_snapshot']['sparse_representation'] = [
            'provider' => 'fastembed',
            'model' => 'Qdrant/bm25',
            'tokenizer' => null,
            'language' => 'arabic',
            'disable_stemmer' => true,
        ];
        $response['profile_snapshot']['batching'] = null;

        Http::fake([
            'http://ai-service.test/api/v1/documents/process' => Http::response(
                $response,
            ),
        ]);

        $result = app(AiServiceClient::class)->processDocument(
            data: new ProcessDocumentRequestData(
                userId: 10,
                documentId: 20,
                processingRunId: 30,
                processingProfile: ProcessingProfile::HybridLocal,
                fileType: FileType::Txt,
            ),
            filePath: '7/document.txt',
            fileName: 'document.txt',
        );

        $this->assertSame(ProcessingProfile::HybridLocal, $result->profile);
        $this->assertSame(
            'rag_documents_hybrid_local',
            $result->qdrantCollection,
        );
    }

    public function test_process_document_rejects_unreadable_file_before_sending_request(): void
    {
        $this->configureAiService();

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')
            ->once()
            ->with('7/document.txt')
            ->andReturnTrue();
        $disk->shouldReceive('readStream')
            ->once()
            ->with('7/document.txt')
            ->andReturnFalse();

        Storage::shouldReceive('disk')
            ->once()
            ->with('documents')
            ->andReturn($disk);

        Http::fake();

        try {
            app(AiServiceClient::class)->processDocument(
                data: $this->processDocumentRequestData(),
                filePath: '7/document.txt',
                fileName: 'document.txt',
            );

            $this->fail('Expected unreadable document to be rejected.');
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'Unable to read document from private storage.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
    }

    public function test_process_document_rejects_incomplete_or_invalid_response(): void
    {
        $this->configureAiService();

        Storage::fake('documents');
        Storage::disk('documents')->put('7/document.txt', 'content');

        $invalidResponse = $this->validProcessDocumentResponse();
        $invalidResponse['profile'] = 'both';
        unset($invalidResponse['warnings']);

        Http::fake([
            'http://ai-service.test/api/v1/documents/process' => Http::response(
                $invalidResponse,
            ),
        ]);

        $this->expectException(AiServiceException::class);
        $this->expectExceptionMessage(
            'AI service returned an invalid process document response.',
        );

        app(AiServiceClient::class)->processDocument(
            data: $this->processDocumentRequestData(),
            filePath: '7/document.txt',
            fileName: 'document.txt',
        );
    }

    public function test_process_document_rejects_invalid_nested_response_contract(): void
    {
        $this->configureAiService();

        Storage::fake('documents');
        Storage::disk('documents')->put('7/document.txt', 'content');

        $invalidSnapshot = $this->validProcessDocumentResponse();
        $invalidSnapshot['profile_snapshot']['chunking']['chunk_size'] = '800';

        $mismatchedSnapshot = $this->validProcessDocumentResponse();
        $mismatchedSnapshot['profile_snapshot']['profile'] = 'hybrid_local';

        $invalidTiming = $this->validProcessDocumentResponse();
        $invalidTiming['stage_timings_ms'] = ['unknown_stage' => 10];

        $invalidWarning = $this->validProcessDocumentResponse();
        $invalidWarning['warnings'] = [[
            'code' => 'Invalid Code',
            'message' => "Unsafe\nmessage",
            'stage' => 'unknown_stage',
        ]];

        foreach (
            [
                $invalidSnapshot,
                $mismatchedSnapshot,
                $invalidTiming,
                $invalidWarning,
            ] as $invalidResponse
        ) {
            Http::fake([
                'http://ai-service.test/api/v1/documents/process' => Http::response(
                    $invalidResponse,
                ),
            ]);

            try {
                app(AiServiceClient::class)->processDocument(
                    data: $this->processDocumentRequestData(),
                    filePath: '7/document.txt',
                    fileName: 'document.txt',
                );

                $this->fail('Expected invalid nested response to be rejected.');
            } catch (AiServiceException $exception) {
                $this->assertSame(
                    'AI service returned an invalid process document response.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_process_document_wraps_http_failure(): void
    {
        $this->configureAiService();

        Storage::fake('documents');
        Storage::disk('documents')->put('7/document.txt', 'content');

        Http::fake([
            'http://ai-service.test/api/v1/documents/process' => Http::response(
                ['detail' => 'Processing failed.'],
                500,
            ),
        ]);

        try {
            app(AiServiceClient::class)->processDocument(
                data: $this->processDocumentRequestData(),
                filePath: '7/document.txt',
                fileName: 'document.txt',
            );

            $this->fail('Expected HTTP failure to be wrapped.');
        } catch (AiServiceException $exception) {
            $this->assertSame('Processing failed.', $exception->getMessage());
            $this->assertSame(500, $exception->statusCode);
        }
    }

    public function test_process_document_wraps_connection_failure(): void
    {
        $this->configureAiService();

        Storage::fake('documents');
        Storage::disk('documents')->put('7/document.txt', 'content');

        Http::fake([
            'http://ai-service.test/api/v1/documents/process' => Http::failedConnection(),
        ]);

        try {
            app(AiServiceClient::class)->processDocument(
                data: $this->processDocumentRequestData(),
                filePath: '7/document.txt',
                fileName: 'document.txt',
            );

            $this->fail('Expected connection failure to be wrapped.');
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'Unable to connect to the AI service.',
                $exception->getMessage(),
            );
            $this->assertInstanceOf(
                ConnectionException::class,
                $exception->getPrevious(),
            );
        }

        Http::assertSent(function (Request $request): bool {
            $parts = collect($request->data())->keyBy('name');
            $fileContents = $parts->get('file')['contents'] ?? null;

            $this->assertFalse(is_resource($fileContents));

            return true;
        });
    }

    public function test_delete_processing_run_points_sends_the_exact_run_scope(): void
    {
        $this->configureAiService();

        Http::fake([
            'http://ai-service.test/api/v1/documents/processing-runs/points' => Http::response([
                'document_id' => 20,
                'processing_run_id' => 30,
                'status' => 'deleted',
            ]),
        ]);

        app(AiServiceClient::class)->deleteProcessingRunPoints(
            userId: 10,
            documentId: 20,
            processingRunId: 30,
            processingProfile: ProcessingProfile::Cloud,
        );

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && $request->url()
                === 'http://ai-service.test/api/v1/documents/processing-runs/points'
                && $request->data() === [
                    'user_id' => 10,
                    'document_id' => 20,
                    'processing_run_id' => 30,
                    'processing_profile' => 'cloud',
                ]
                && $request->hasHeader(
                    'X-Internal-API-Key',
                    'test-secret',
                );
        });
    }

    public function test_delete_processing_run_points_rejects_a_mismatched_response(): void
    {
        $this->configureAiService();

        Http::fake([
            'http://ai-service.test/api/v1/documents/processing-runs/points' => Http::response([
                'document_id' => 20,
                'processing_run_id' => 999,
                'status' => 'deleted',
            ]),
        ]);

        $this->expectException(AiServiceException::class);
        $this->expectExceptionMessage(
            'AI service returned an invalid processing run cleanup response.',
        );

        app(AiServiceClient::class)->deleteProcessingRunPoints(
            userId: 10,
            documentId: 20,
            processingRunId: 30,
            processingProfile: ProcessingProfile::Cloud,
        );
    }

    private function configureAiService(): void
    {
        config([
            'services.ai_service.base_url' => 'http://ai-service.test',
            'services.ai_service.internal_api_key' => 'test-secret',
            'services.ai_service.connect_timeout' => 10,
            'services.ai_service.timeout' => 30,
            'services.ai_service.process_document_timeout' => 300,
        ]);
    }

    private function processDocumentRequestData(): ProcessDocumentRequestData
    {
        return new ProcessDocumentRequestData(
            userId: 10,
            documentId: 20,
            processingRunId: 30,
            processingProfile: ProcessingProfile::Cloud,
            fileType: FileType::Txt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validProcessDocumentResponse(): array
    {
        return [
            'document_id' => 20,
            'processing_run_id' => 30,
            'profile' => 'cloud',
            'status' => 'indexed',
            'qdrant_collection' => 'rag_documents_cloud',
            'profile_snapshot' => [
                'profile' => 'cloud',
                'chunking' => [
                    'version' => 'markdown-sections-scoped-overlap-v3',
                    'chunk_size' => 800,
                    'chunk_overlap' => 80,
                ],
                'dense_embedding' => [
                    'provider' => 'jina',
                    'model' => 'jina-embeddings-v3',
                    'vector_dimension' => 1024,
                ],
                'sparse_representation' => [
                    'provider' => 'qdrant',
                    'model' => 'bm25',
                    'tokenizer' => 'multilingual',
                    'language' => null,
                    'disable_stemmer' => null,
                ],
                'batching' => [
                    'batch_size' => 16,
                    'wait_between_batches_seconds' => 0.0,
                    'rate_limit_retry_wait_seconds' => 1.0,
                    'max_retries' => 3,
                ],
            ],
            'total_pages' => null,
            'total_chunks' => 4,
            'vector_count' => 4,
            'vector_dimension' => 1024,
            'stage_timings_ms' => [
                'parse' => 5,
                'chunk' => 2,
                'dense_embedding' => 10,
                'sparse_representation' => 3,
                'total' => 25,
            ],
            'warnings' => [],
        ];
    }
}
