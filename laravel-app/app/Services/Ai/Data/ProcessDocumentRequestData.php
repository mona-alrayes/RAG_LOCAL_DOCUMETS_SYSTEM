<?php

namespace App\Services\Ai\Data;

use App\Enums\FileType;
use App\Enums\ProcessingProfile;

final readonly class ProcessDocumentRequestData
{
    public function __construct(
        public int $userId,
        public int $documentId,
        public int $processingRunId,
        public ProcessingProfile $processingProfile,
        public FileType $fileType,
    ) {}

    /**
     * @return array{
     *     user_id: int,
     *     document_id: int,
     *     processing_run_id: int,
     *     processing_profile: string,
     *     file_type: string
     * }
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'document_id' => $this->documentId,
            'processing_run_id' => $this->processingRunId,
            'processing_profile' => $this->processingProfile->value,
            'file_type' => $this->fileType->value,
        ];
    }
}
