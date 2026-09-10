<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class AiServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
