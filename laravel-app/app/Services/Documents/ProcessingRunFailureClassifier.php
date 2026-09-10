<?php

namespace App\Services\Documents;

use App\Exceptions\AiServiceException;
use App\Exceptions\LocalHeavyResourceBusyException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class ProcessingRunFailureClassifier
{
    /**
     * أخطاء معروفة بأنها دائمة ولن تتغير بإعادة المحاولة.
     *
     * @var list<string>
     */
    private const TERMINAL_ERROR_CODES = [
        'invalid_processing_profile',
        'processing_profile_not_registered',
        'document_loader_not_registered',
        'document_parser_not_configured',
        'cloud_embedding_result_invalid',
        'qdrant_index_dense_count_mismatch',
        'qdrant_index_sparse_count_mismatch',
        'qdrant_index_count_mismatch',
    ];

    public function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof LocalHeavyResourceBusyException) {
            return true;
        }

        if (! $exception instanceof AiServiceException) {
            return false;
        }

        // فشل الاتصال أو timeout مع FastAPI يعتبر مؤقتًا.
        if ($exception->getPrevious() instanceof ConnectionException) {
            return true;
        }

        // الخطأ البنيوي المعروف يتغلب على HTTP 500 القادم من FastAPI.
        if (
            $exception->errorCode !== null
            && in_array(
                $exception->errorCode,
                self::TERMINAL_ERROR_CODES,
                true,
            )
        ) {
            return false;
        }

        // Too Many Requests: ننتظر ثم نعيد المحاولة.
        if ($exception->statusCode === 429) {
            return true;
        }

        // أخطاء الخدمة 5xx قد تكون مؤقتة.
        if (
            $exception->statusCode !== null
            && $exception->statusCode >= 500
        ) {
            return true;
        }

        // باقي الأخطاء تعتبر Terminal بشكل آمن.
        return false;
    }

    public function terminalErrorCode(Throwable $exception): string
    {
        if (
            $exception instanceof AiServiceException
            && $exception->errorCode !== null
            && $exception->errorCode !== ''
        ) {
            return $exception->errorCode;
        }

        if ($exception instanceof AiServiceException) {
            return match ($exception->statusCode) {
                400 => 'ai_service_bad_request',
                401 => 'ai_service_unauthorized',
                403 => 'ai_service_forbidden',
                404 => 'ai_service_not_found',
                409 => 'ai_service_conflict',
                422 => 'ai_service_validation_failed',
                default => 'processing_terminal_failure',
            };
        }

        return 'processing_terminal_failure';
    }

    public function terminalFailureReason(Throwable $exception): string
    {
        if ($exception instanceof AiServiceException) {
            return match ($exception->statusCode) {
                400, 422 => 'The document processing request was rejected.',
                401, 403 => 'The AI service rejected the processing request.',
                404 => 'The requested AI processing resource was not found.',
                409 => 'The document processing request conflicted with the current state.',
                default => 'Document processing failed and cannot be retried.',
            };
        }

        return 'Document processing failed and cannot be retried.';
    }
}
