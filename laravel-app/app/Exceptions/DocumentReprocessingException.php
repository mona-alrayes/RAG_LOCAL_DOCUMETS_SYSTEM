<?php

namespace App\Exceptions;

use LogicException;

class DocumentReprocessingException extends LogicException
{
    public const NO_ACTIVE_RUN = 'no_active_run';

    public const INVALID_ACTIVE_RUN = 'invalid_active_run';

    public const ALREADY_IN_PROGRESS = 'already_in_progress';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function noActiveRun(): self
    {
        return new self(
            self::NO_ACTIVE_RUN,
            'Document must have an active processing run before reprocessing.',
        );
    }

    public static function invalidActiveRun(): self
    {
        return new self(
            self::INVALID_ACTIVE_RUN,
            'Document active processing run is invalid for reprocessing.',
        );
    }

    public static function alreadyInProgress(): self
    {
        return new self(
            self::ALREADY_IN_PROGRESS,
            'Document reprocessing is already in progress.',
        );
    }
}
