<?php

namespace Tests\Unit\Services\Documents;

use App\Exceptions\AiServiceException;
use App\Services\Documents\ProcessingRunFailureClassifier;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Tests\TestCase;

class ProcessingRunFailureClassifierTest extends TestCase
{
    public function test_connection_failure_is_retryable(): void
    {
        $exception = new AiServiceException(
            message: 'Unable to connect to the AI service.',
            previous: new ConnectionException('Connection failed.'),
        );

        $this->assertTrue(
            app(ProcessingRunFailureClassifier::class)
                ->isRetryable($exception),
        );
    }

    public function test_http_429_is_retryable(): void
    {
        $exception = new AiServiceException(
            message: 'Rate limited.',
            statusCode: 429,
        );

        $this->assertTrue(
            app(ProcessingRunFailureClassifier::class)
                ->isRetryable($exception),
        );
    }

    public function test_unknown_http_5xx_is_retryable(): void
    {
        $exception = new AiServiceException(
            message: 'Temporary service failure.',
            statusCode: 503,
        );

        $this->assertTrue(
            app(ProcessingRunFailureClassifier::class)
                ->isRetryable($exception),
        );
    }

    public function test_known_terminal_error_code_wins_over_http_500(): void
    {
        $exception = new AiServiceException(
            message: 'Invalid profile.',
            statusCode: 500,
            errorCode: 'invalid_processing_profile',
        );

        $this->assertFalse(
            app(ProcessingRunFailureClassifier::class)
                ->isRetryable($exception),
        );
    }

    public function test_qdrant_count_mismatch_is_terminal_even_with_http_500(): void
    {
        $exception = new AiServiceException(
            message: 'Count mismatch.',
            statusCode: 500,
            errorCode: 'qdrant_index_count_mismatch',
        );

        $this->assertFalse(
            app(ProcessingRunFailureClassifier::class)
                ->isRetryable($exception),
        );
    }

    public function test_http_401_is_terminal(): void
    {
        $exception = new AiServiceException(
            message: 'Unauthorized.',
            statusCode: 401,
        );

        $classifier = app(ProcessingRunFailureClassifier::class);

        $this->assertFalse(
            $classifier->isRetryable($exception),
        );

        $this->assertSame(
            'ai_service_unauthorized',
            $classifier->terminalErrorCode($exception),
        );

        $this->assertSame(
            'The AI service rejected the processing request.',
            $classifier->terminalFailureReason($exception),
        );
    }

    public function test_http_422_is_terminal_with_safe_failure_metadata(): void
    {
        $exception = new AiServiceException(
            message: 'Sensitive provider validation detail.',
            statusCode: 422,
        );

        $classifier = app(ProcessingRunFailureClassifier::class);

        $this->assertFalse(
            $classifier->isRetryable($exception),
        );

        $this->assertSame(
            'ai_service_validation_failed',
            $classifier->terminalErrorCode($exception),
        );

        $this->assertSame(
            'The document processing request was rejected.',
            $classifier->terminalFailureReason($exception),
        );

        $this->assertNotSame(
            $exception->getMessage(),
            $classifier->terminalFailureReason($exception),
        );
    }

    public function test_structured_terminal_code_is_preserved(): void
    {
        $exception = new AiServiceException(
            message: 'Remote detail.',
            statusCode: 500,
            errorCode: 'document_parser_not_configured',
        );

        $classifier = app(ProcessingRunFailureClassifier::class);

        $this->assertFalse(
            $classifier->isRetryable($exception),
        );

        $this->assertSame(
            'document_parser_not_configured',
            $classifier->terminalErrorCode($exception),
        );
    }

    public function test_non_ai_exception_is_terminal_with_generic_safe_metadata(): void
    {
        $exception = new RuntimeException(
            'Internal technical information that must not be exposed.',
        );

        $classifier = app(ProcessingRunFailureClassifier::class);

        $this->assertFalse(
            $classifier->isRetryable($exception),
        );

        $this->assertSame(
            'processing_terminal_failure',
            $classifier->terminalErrorCode($exception),
        );

        $this->assertSame(
            'Document processing failed and cannot be retried.',
            $classifier->terminalFailureReason($exception),
        );
    }
}
