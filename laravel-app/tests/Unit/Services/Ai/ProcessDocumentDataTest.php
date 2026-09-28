<?php

namespace Tests\Unit\Services\Ai;

use App\Enums\FileType;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Services\Ai\Data\ProcessDocumentRequestData;
use App\Services\Ai\Data\ProcessDocumentResult;
use Tests\TestCase;

class ProcessDocumentDataTest extends TestCase
{
    public function test_request_data_serializes_to_fastapi_contract(): void
    {
        $request = new ProcessDocumentRequestData(
            userId: 7,
            documentId: 152,
            processingRunId: 901,
            processingProfile: ProcessingProfile::Cloud,
            fileType: FileType::Pdf,
        );

        $this->assertSame([
            'user_id' => 7,
            'document_id' => 152,
            'processing_run_id' => 901,
            'processing_profile' => 'cloud',
            'file_type' => 'pdf',
        ], $request->toArray());
    }

    public function test_result_data_preserves_typed_processing_contract(): void
    {
        $result = new ProcessDocumentResult(
            documentId: 152,
            processingRunId: 901,
            profile: ProcessingProfile::Cloud,
            status: ProcessingRunStatus::Indexed,
            qdrantCollection: 'rag_documents_cloud',
            profileSnapshot: [
                'profile' => 'cloud',
            ],
            totalPages: null,
            totalChunks: 184,
            vectorCount: 184,
            vectorDimension: 1024,
            stageTimingsMs: [
                'total' => 215,
            ],
            warnings: [],
        );

        $this->assertSame(152, $result->documentId);
        $this->assertSame(901, $result->processingRunId);
        $this->assertSame(ProcessingProfile::Cloud, $result->profile);
        $this->assertSame(ProcessingRunStatus::Indexed, $result->status);
        $this->assertSame('rag_documents_cloud', $result->qdrantCollection);
        $this->assertSame(184, $result->vectorCount);
        $this->assertSame(1024, $result->vectorDimension);
    }
}
