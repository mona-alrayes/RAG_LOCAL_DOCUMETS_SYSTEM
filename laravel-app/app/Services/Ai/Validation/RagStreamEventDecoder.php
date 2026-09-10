<?php

namespace App\Services\Ai\Validation;

use App\Enums\ProcessingProfile;
use App\Exceptions\AiServiceException;
use App\Services\Ai\Data\RagCompletedEventData;
use App\Services\Ai\Data\RagSourceData;
use App\Services\Ai\Data\RagStreamEventData;
use App\Services\Ai\Data\RagTimingsData;
use App\Services\Ai\Data\RagTokenEventData;
use JsonException;
use ValueError;

final class RagStreamEventDecoder
{
    private const TIMING_KEYS = [
        'query_embedding',
        'retrieval',
        'fusion',
        'reranking',
        'context_building',
        'generation',
        'total',
    ];

    public function decode(
        string $line,
        string $correlationId,
    ): RagStreamEventData {
        try {
            $event = json_decode(
                $line,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw $this->invalid(
                correlationId: $correlationId,
                previous: $exception,
            );
        }

        if (! is_array($event)) {
            throw $this->invalid($correlationId);
        }

        return match ($event['type'] ?? null) {
            'token' => $this->token(
                $event,
                $correlationId,
            ),
            'completed' => $this->completed(
                $event,
                $correlationId,
            ),
            'error' => $this->remoteError(
                $event,
                $correlationId,
            ),
            default => throw $this->invalid(
                $correlationId,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function token(
        array $event,
        string $correlationId,
    ): RagTokenEventData {
        $this->assertExactKeys(
            $event,
            ['type', 'content'],
            $correlationId,
        );

        if (! is_string($event['content'] ?? null)) {
            throw $this->invalid($correlationId);
        }

        return new RagTokenEventData(
            content: $event['content'],
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function completed(
        array $event,
        string $correlationId,
    ): RagCompletedEventData {
        $this->assertExactKeys(
            $event,
            [
                'type',
                'answer',
                'sources',
                'timings_ms',
            ],
            $correlationId,
        );

        $answer = $event['answer'] ?? null;
        $sources = $event['sources'] ?? null;
        $timings = $event['timings_ms'] ?? null;

        if (
            ! is_string($answer)
            || trim($answer) === ''
            || ! is_array($sources)
            || ! array_is_list($sources)
            || ! is_array($timings)
        ) {
            throw $this->invalid($correlationId);
        }

        $normalizedSources = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                throw $this->invalid($correlationId);
            }

            $normalizedSources[] = $this->source(
                $source,
                $correlationId,
            );
        }

        return new RagCompletedEventData(
            answer: $answer,
            sources: $normalizedSources,
            timings: $this->timings(
                $timings,
                $correlationId,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function source(
        array $source,
        string $correlationId,
    ): RagSourceData {
        $this->assertExactKeys(
            $source,
            [
                'point_id',
                'retrieval_score',
                'reranker_score',
                'document_id',
                'processing_run_id',
                'processing_profile',
                'chunk_index',
                'text',
                'page',
                'section',
                'source',
            ],
            $correlationId,
        );

        $pointId = $source['point_id'] ?? null;
        $retrievalScore =
            $source['retrieval_score'] ?? null;
        $rerankerScore =
            $source['reranker_score'] ?? null;
        $documentId =
            $source['document_id'] ?? null;
        $processingRunId =
            $source['processing_run_id'] ?? null;
        $processingProfile =
            $source['processing_profile'] ?? null;
        $chunkIndex =
            $source['chunk_index'] ?? null;
        $text = $source['text'] ?? null;
        $page = $source['page'] ?? null;
        $section = $source['section'] ?? null;
        $sourceName = $source['source'] ?? null;

        if (
            ! is_string($pointId)
            || trim($pointId) === ''
            || strlen($pointId) > 64
            || ! $this->validNumber(
                $retrievalScore,
            )
            || (
                $rerankerScore !== null
                && ! $this->validNumber(
                    $rerankerScore,
                )
            )
            || ! is_int($documentId)
            || $documentId < 1
            || ! is_int($processingRunId)
            || $processingRunId < 1
            || ! is_string($processingProfile)
            || ! is_int($chunkIndex)
            || $chunkIndex < 0
            || ! is_string($text)
            || trim($text) === ''
            || (
                $page !== null
                && (
                    ! is_int($page)
                    || $page < 1
                )
            )
            || (
                $section !== null
                && ! is_string($section)
            )
            || ! is_string($sourceName)
            || trim($sourceName) === ''
        ) {
            throw $this->invalid($correlationId);
        }

        try {
            $profile = ProcessingProfile::from(
                $processingProfile,
            );
        } catch (ValueError) {
            throw $this->invalid($correlationId);
        }

        return new RagSourceData(
            pointId: $pointId,
            retrievalScore: (float) $retrievalScore,
            rerankerScore: $rerankerScore === null
                ? null
                : (float) $rerankerScore,
            documentId: $documentId,
            processingRunId: $processingRunId,
            processingProfile: $profile,
            chunkIndex: $chunkIndex,
            text: $text,
            page: $page,
            section: $section,
            source: $sourceName,
        );
    }

    /**
     * @param  array<string, mixed>  $timings
     */
    private function timings(
        array $timings,
        string $correlationId,
    ): RagTimingsData {
        $this->assertExactKeys(
            $timings,
            self::TIMING_KEYS,
            $correlationId,
        );

        $values = [];

        foreach (self::TIMING_KEYS as $key) {
            $value = $timings[$key] ?? null;

            if (
                $value !== null
                && (
                    ! $this->validNumber($value)
                    || (float) $value < 0
                )
            ) {
                throw $this->invalid(
                    $correlationId,
                );
            }

            $values[$key] = $value === null
                ? null
                : (float) $value;
        }

        if ($values['total'] === null) {
            throw $this->invalid($correlationId);
        }

        return new RagTimingsData(
            queryEmbedding: $values['query_embedding'],
            retrieval: $values['retrieval'],
            fusion: $values['fusion'],
            reranking: $values['reranking'],
            contextBuilding: $values['context_building'],
            generation: $values['generation'],
            total: $values['total'],
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function remoteError(
        array $event,
        string $correlationId,
    ): never {
        $this->assertExactKeys(
            $event,
            ['type', 'code', 'message'],
            $correlationId,
        );

        $errorCode = $event['code'] ?? null;

        throw new AiServiceException(
            message: 'AI service failed while generating the answer.',
            correlationId: $correlationId,
            errorCode: is_string($errorCode)
                && trim($errorCode) !== ''
                    ? $errorCode
                    : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $expected
     */
    private function assertExactKeys(
        array $payload,
        array $expected,
        string $correlationId,
    ): void {
        $actual = array_keys($payload);

        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw $this->invalid($correlationId);
        }
    }

    private function validNumber(mixed $value): bool
    {
        return (
            is_int($value)
            || is_float($value)
        )
            && ! is_bool($value)
            && is_finite((float) $value);
    }

    private function invalid(
        string $correlationId,
        ?\Throwable $previous = null,
    ): AiServiceException {
        return new AiServiceException(
            message: 'AI service returned an invalid answer stream.',
            correlationId: $correlationId,
            previous: $previous,
        );
    }
}
