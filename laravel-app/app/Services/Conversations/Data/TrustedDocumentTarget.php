<?php

namespace App\Services\Conversations\Data;

use App\Enums\ProcessingProfile;

final readonly class TrustedDocumentTarget
{
    public function __construct(
        public int $documentId,
        public int $processingRunId,
        public ProcessingProfile $processingProfile,
    ) {}
}
