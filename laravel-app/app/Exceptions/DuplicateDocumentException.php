<?php

namespace App\Exceptions;

use App\Models\Document;
use RuntimeException;

class DuplicateDocumentException extends RuntimeException
{
    public function __construct(
        public readonly Document $document,
    ) {
        parent::__construct('Duplicate document content.');
    }
}
