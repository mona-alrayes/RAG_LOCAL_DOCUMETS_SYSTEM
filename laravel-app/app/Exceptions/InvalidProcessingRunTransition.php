<?php

namespace App\Exceptions;

use DomainException;

class InvalidProcessingRunTransition extends DomainException
{
    public static function toProcessing(): self
    {
        return new self(
            'Processing run cannot enter the processing stage from its current state.',
        );
    }

    public static function toIndexing(): self
    {
        return new self(
            'Processing run cannot accept the indexing_started event from its current state.',
        );
    }
}
