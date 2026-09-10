<?php

namespace App\Services\Ai;

use App\Enums\ProcessingProfile;
use App\Exceptions\AiServiceException;
use App\Services\Ai\Data\ProcessDocumentRequestData;
use App\Services\Ai\Data\ProcessDocumentResult;
use App\Services\Ai\Data\RagQueryRequestData;
use App\Services\Ai\Data\RagStreamEventData;
use App\Services\Ai\Validation\ProcessDocumentResponseValidator;
use App\Services\Ai\Validation\RagStreamEventDecoder;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

class AiServiceClient
{
    public function __construct(
        private readonly ProcessDocumentResponseValidator $processDocumentResponseValidator,
        private readonly RagStreamEventDecoder $ragStreamEventDecoder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->getJson('/api/v1/health');
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return $this->getJson('/api/v1/capabilities');
    }

    public function adminChunks(int $userId, int $documentId, int $processingRunId, ProcessingProfile $profile, ?int $cursor = null): array
    {
        $payload = $this->postAdminJson('/api/v1/admin/chunks', [
            'user_id' => $userId, 'document_id' => $documentId,
            'processing_run_id' => $processingRunId, 'processing_profile' => $profile->value,
            'limit' => 25, 'cursor' => $cursor,
        ]);
        $validator = Validator::make($payload, [
            'document_id' => ['required', 'integer', 'in:'.$documentId],
            'processing_run_id' => ['required', 'integer', 'in:'.$processingRunId],
            'processing_profile' => ['required', 'in:'.$profile->value],
            'total' => ['required', 'integer', 'min:0'],
            'next_cursor' => ['present', 'nullable', 'integer', 'min:0'],
            'chunks' => ['present', 'array', 'max:25'],
            'chunks.*.point_id' => ['required', 'string'],
            'chunks.*.chunk_index' => ['required', 'integer', 'min:0'],
            'chunks.*.text' => ['present', 'string'],
            'chunks.*.source' => ['present', 'string'],
            'chunks.*.page' => ['present', 'nullable', 'integer'],
            'chunks.*.section' => ['present', 'nullable', 'string'],
        ]);
        if ($validator->fails()) {
            throw new AiServiceException('AI service returned invalid admin chunks.');
        }

        return [
            'document_id' => $payload['document_id'], 'processing_run_id' => $payload['processing_run_id'],
            'processing_profile' => $payload['processing_profile'], 'total' => $payload['total'],
            'next_cursor' => $payload['next_cursor'],
            'chunks' => array_map(fn ($chunk) => Arr::only($chunk, ['point_id', 'chunk_index', 'text', 'source', 'page', 'section']), $payload['chunks']),
        ];
    }

    public function evaluateQuestion(array $data): array
    {
        return $this->postAdminJson('/api/v1/admin/evaluate-question', $data);
    }

    private function postAdminJson(string $uri, array $data): array
    {
        $correlationId = (string) Str::uuid();
        try {
            $response = $this->request($correlationId)->timeout(300)->post($uri, $data);
        } catch (ConnectionException $exception) {
            throw new AiServiceException('Unable to connect to the AI service.', correlationId: $correlationId, previous: $exception);
        }
        if ($response->failed()) {
            throw $this->remoteFailure($response, $correlationId);
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new AiServiceException('AI service returned invalid JSON.', correlationId: $correlationId);
        }

        return $payload;
    }

    public function processDocument(
        ProcessDocumentRequestData $data,
        string $filePath,
        string $fileName,
    ): ProcessDocumentResult {
        $correlationId = (string) Str::uuid();
        $stream = $this->openDocumentStream($filePath);

        try {
            try {
                $response = $this->request($correlationId)
                    ->timeout(
                        (int) config(
                            'services.ai_service.process_document_timeout',
                            300,
                        ),
                    )
                    ->attach(
                        'file',
                        $stream,
                        $this->safeFileName($fileName, $data),
                    )
                    ->post(
                        '/api/v1/documents/process',
                        $data->toArray(),
                    );
            } catch (ConnectionException $exception) {
                throw new AiServiceException(
                    message: 'Unable to connect to the AI service.',
                    correlationId: $correlationId,
                    previous: $exception,
                );
            }
        } finally {
            fclose($stream);
        }

        if ($response->failed()) {
            throw $this->remoteFailure(
                response: $response,
                fallbackCorrelationId: $correlationId,
            );
        }

        return $this->processDocumentResult(
            response: $response,
            requestData: $data,
            fallbackCorrelationId: $correlationId,
        );
    }

    public function deleteProcessingRunPoints(
        int $userId,
        int $documentId,
        int $processingRunId,
        ProcessingProfile $processingProfile,
    ): void {
        $correlationId = (string) Str::uuid();

        try {
            $response = $this->request($correlationId)
                ->delete(
                    '/api/v1/documents/processing-runs/points',
                    [
                        'user_id' => $userId,
                        'document_id' => $documentId,
                        'processing_run_id' => $processingRunId,
                        'processing_profile' => $processingProfile->value,
                    ],
                );
        } catch (ConnectionException $exception) {
            throw new AiServiceException(
                message: 'Unable to connect to the AI service.',
                correlationId: $correlationId,
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw $this->remoteFailure(
                response: $response,
                fallbackCorrelationId: $correlationId,
            );
        }

        $payload = $response->json();

        if (
            ! is_array($payload)
            || data_get($payload, 'status') !== 'deleted'
            || (int) data_get($payload, 'document_id') !== $documentId
            || (int) data_get($payload, 'processing_run_id')
            !== $processingRunId
        ) {
            throw new AiServiceException(
                message: 'AI service returned an invalid processing run cleanup response.',
                statusCode: $response->status(),
                correlationId: $this->correlationId(
                    response: $response,
                    fallback: $correlationId,
                ),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $uri): array
    {
        $correlationId = (string) Str::uuid();

        try {
            $response = $this->request($correlationId)->get($uri);
        } catch (ConnectionException $exception) {
            throw new AiServiceException(
                message: 'Unable to connect to the AI service.',
                correlationId: $correlationId,
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw $this->remoteFailure(
                response: $response,
                fallbackCorrelationId: $correlationId,
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new AiServiceException(
                message: 'AI service returned an invalid JSON response.',
                statusCode: $response->status(),
                correlationId: $this->correlationId(
                    response: $response,
                    fallback: $correlationId,
                ),
            );
        }

        return $payload;
    }

    private function request(string $correlationId): PendingRequest
    {
        return Http::baseUrl(
            rtrim(
                $this->requiredConfigString('services.ai_service.base_url'),
                '/',
            ),
        )
            ->acceptJson()
            ->withHeaders([
                'X-Internal-API-Key' => $this->requiredConfigString(
                    'services.ai_service.internal_api_key',
                ),
                'X-Correlation-ID' => $correlationId,
            ])
            ->connectTimeout(
                (int) config('services.ai_service.connect_timeout', 10),
            )
            ->timeout(
                (int) config('services.ai_service.timeout', 600),
            );
    }

    /**
     * @return resource
     */
    private function openDocumentStream(string $filePath)
    {
        try {
            $disk = Storage::disk('documents');

            if (! $disk->exists($filePath)) {
                throw new AiServiceException(
                    message: 'Document file is missing from private storage.',
                );
            }

            $stream = $disk->readStream($filePath);
        } catch (AiServiceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AiServiceException(
                message: 'Unable to read document from private storage.',
                previous: $exception,
            );
        }

        if (! is_resource($stream) || ! $this->isReadableStream($stream)) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new AiServiceException(
                message: 'Unable to read document from private storage.',
            );
        }

        return $stream;
    }

    /**
     * @param  resource  $stream
     */
    private function isReadableStream($stream): bool
    {
        $mode = stream_get_meta_data($stream)['mode'] ?? null;

        return is_string($mode)
            && (str_contains($mode, 'r') || str_contains($mode, '+'));
    }

    private function safeFileName(
        string $fileName,
        ProcessDocumentRequestData $data,
    ): string {
        $normalized = str_replace('\\', '/', $fileName);
        $basename = trim(basename($normalized));

        return $basename !== ''
            ? $basename
            : 'document.'.$data->fileType->value;
    }

    private function processDocumentResult(
        Response $response,
        ProcessDocumentRequestData $requestData,
        string $fallbackCorrelationId,
    ): ProcessDocumentResult {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw $this->invalidProcessDocumentResponse(
                response: $response,
                fallbackCorrelationId: $fallbackCorrelationId,
            );
        }

        try {
            $validated = $this->processDocumentResponseValidator->validate(
                payload: $payload,
                requestData: $requestData,
            );
        } catch (UnexpectedValueException) {
            throw $this->invalidProcessDocumentResponse(
                response: $response,
                fallbackCorrelationId: $fallbackCorrelationId,
            );
        }

        return ProcessDocumentResult::fromValidatedResponse($validated);
    }

    private function invalidProcessDocumentResponse(
        Response $response,
        string $fallbackCorrelationId,
    ): AiServiceException {
        return new AiServiceException(
            message: 'AI service returned an invalid process document response.',
            statusCode: $response->status(),
            correlationId: $this->correlationId(
                response: $response,
                fallback: $fallbackCorrelationId,
            ),
        );
    }

    private function remoteFailure(
        Response $response,
        string $fallbackCorrelationId,
    ): AiServiceException {
        $payload = $response->json();

        $message = is_array($payload)
            ? data_get($payload, 'error.message')
            ?? data_get($payload, 'detail')
            ?? 'AI service request failed.'
            : 'AI service request failed.';

        $errorCode = is_array($payload)
            ? data_get($payload, 'error.code')
            : null;

        return new AiServiceException(
            message: (string) $message,
            statusCode: $response->status(),
            correlationId: $this->correlationId(
                response: $response,
                fallback: $fallbackCorrelationId,
            ),
            errorCode: is_string($errorCode) && $errorCode !== ''
                ? $errorCode
                : null,
        );
    }

    private function correlationId(
        Response $response,
        string $fallback,
    ): string {
        $header = $response->header('X-Correlation-ID');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $payload = $response->json();
        $correlationId = is_array($payload)
            ? data_get($payload, 'correlation_id')
            : null;

        return is_string($correlationId) && $correlationId !== ''
            ? $correlationId
            : $fallback;
    }

    private function requiredConfigString(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || trim($value) === '') {
            throw new AiServiceException(
                message: "Missing required AI service configuration: {$key}.",
            );
        }

        return $value;
    }

    /**
     * @return Generator<int, RagStreamEventData>
     */
    public function streamRagQuery(
        RagQueryRequestData $data,
    ): Generator {
        $correlationId = (string) Str::uuid();

        try {
            $response = $this->request($correlationId)
                ->withOptions([
                    'stream' => true,
                ])
                ->withHeaders([
                    'Accept' => 'application/x-ndjson',
                ])
                ->post(
                    '/api/v1/rag/query',
                    $data->toArray(),
                );
        } catch (ConnectionException $exception) {
            throw new AiServiceException(
                message: 'Unable to connect to the AI service.',
                correlationId: $correlationId,
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw $this->remoteFailure(
                response: $response,
                fallbackCorrelationId: $correlationId,
            );
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        try {
            while (! $body->eof()) {
                // PHP's keep-alive HTTP stream can wait to fill a large read.
                // Consume available bytes so each NDJSON event is yielded immediately.
                $buffer .= $body->read(1);

                while (($position = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $position));
                    $buffer = substr($buffer, $position + 1);

                    if ($line === '') {
                        continue;
                    }

                    yield $this->decodeRagStreamEvent(
                        line: $line,
                        correlationId: $correlationId,
                    );
                }
            }

            $line = trim($buffer);

            if ($line !== '') {
                yield $this->decodeRagStreamEvent(
                    line: $line,
                    correlationId: $correlationId,
                );
            }
        } catch (AiServiceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AiServiceException(
                message: 'AI service answer stream failed.',
                correlationId: $correlationId,
                previous: $exception,
            );
        }
    }

    private function decodeRagStreamEvent(
        string $line,
        string $correlationId,
    ): RagStreamEventData {
        return $this->ragStreamEventDecoder
            ->decode(
                line: $line,
                correlationId: $correlationId,
            );
    }
}
