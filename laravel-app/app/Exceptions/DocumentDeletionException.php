<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class DocumentDeletionException extends RuntimeException
{
    public const PROCESSING_IN_PROGRESS = 'processing_in_progress';

    public const STORAGE_CLEANUP_FAILED = 'storage_cleanup_failed';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function processingInProgress(): self
    {
        return new self(
            self::PROCESSING_IN_PROGRESS,
            'Document cannot be deleted while processing is in progress.',
        );
    }

    public static function storageCleanupFailed(
        Throwable $previous,
    ): self {
        return new self(
            self::STORAGE_CLEANUP_FAILED,
            'Unable to remove the document from private storage.',
            $previous,
        );
    }
}
