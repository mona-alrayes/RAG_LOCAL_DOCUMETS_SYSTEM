<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Documents\DocumentStatusProjector;
use App\Services\Documents\DocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class DocumentStatusProjectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_projects_the_initial_processing_lifecycle(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunStatus::Pending,
        );
        $projector = app(DocumentStatusProjector::class);

        $expectedStatuses = [
            ProcessingRunStatus::Pending->value => DocumentStatus::Queued,
            ProcessingRunStatus::Processing->value => DocumentStatus::Processing,
            ProcessingRunStatus::Indexing->value => DocumentStatus::Indexing,
            ProcessingRunStatus::Failed->value => DocumentStatus::Failed,
        ];

        foreach ($expectedStatuses as $runStatus => $documentStatus) {
            $processingRun->status = ProcessingRunStatus::from($runStatus);
            $processingRun->save();

            $projector->project($document, $processingRun);

            $this->assertSame($documentStatus, $document->fresh()->status);
        }
    }

    public function test_reprocessing_progress_and_failure_keep_a_valid_active_run_ready(): void
    {
        $document = $this->createDocument();
        $activeRun = $this->createRun(
            $document,
            ProcessingRunStatus::Indexed,
        );
        $replacementRun = $this->createRun(
            $document,
            ProcessingRunStatus::Pending,
        );

        $document->active_processing_run_id = $activeRun->getKey();
        $document->status = DocumentStatus::Ready;
        $document->save();

        $projector = app(DocumentStatusProjector::class);

        foreach ([
            ProcessingRunStatus::Pending,
            ProcessingRunStatus::Processing,
            ProcessingRunStatus::Indexing,
            ProcessingRunStatus::Failed,
        ] as $status) {
            $replacementRun->status = $status;
            $replacementRun->save();

            $projector->project($document, $replacementRun);

            $freshDocument = $document->fresh();

            $this->assertSame(DocumentStatus::Ready, $freshDocument->status);
            $this->assertSame(
                $activeRun->getKey(),
                $freshDocument->active_processing_run_id,
            );
        }
    }

    public function test_it_projects_activation_as_ready_with_the_new_active_run(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunStatus::Indexed,
        );

        app(DocumentStatusProjector::class)->projectActivation(
            $document,
            $processingRun,
        );

        $freshDocument = $document->fresh();

        $this->assertSame(DocumentStatus::Ready, $freshDocument->status);
        $this->assertSame(
            $processingRun->getKey(),
            $freshDocument->active_processing_run_id,
        );
    }

    public function test_it_projects_the_explicit_run_instead_of_the_latest_run(): void
    {
        $document = $this->createDocument();
        $intendedRun = $this->createRun(
            $document,
            ProcessingRunStatus::Processing,
        );
        $this->createRun($document, ProcessingRunStatus::Failed);

        app(DocumentStatusProjector::class)->project(
            $document,
            $intendedRun,
        );

        $this->assertSame(
            DocumentStatus::Processing,
            $document->fresh()->status,
        );
    }

    public function test_it_rejects_a_run_owned_by_another_document(): void
    {
        $document = $this->createDocument();
        $otherRun = $this->createRun(
            $this->createDocument(),
            ProcessingRunStatus::Processing,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Processing run does not belong to the document being projected.',
        );

        app(DocumentStatusProjector::class)->project($document, $otherRun);
    }

    public function test_it_rejects_an_invalid_active_run_pointer(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunStatus::Processing,
        );

        $document->active_processing_run_id = 999999;
        $document->save();

        try {
            app(DocumentStatusProjector::class)->project(
                $document,
                $processingRun,
            );

            $this->fail('Expected invalid active run pointer to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Document active processing run is invalid for status projection.',
                $exception->getMessage(),
            );
        }

        $this->assertNotSame(
            DocumentStatus::Ready,
            $document->fresh()->status,
        );
    }

    public function test_an_unactivated_indexed_run_cannot_make_a_document_ready(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunStatus::Indexed,
        );

        try {
            app(DocumentStatusProjector::class)->project(
                $document,
                $processingRun,
            );

            $this->fail('Expected unactivated indexed run to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Indexed processing run must be activated before the document becomes ready.',
                $exception->getMessage(),
            );
        }

        $this->assertNull($document->fresh()->active_processing_run_id);
        $this->assertNotSame(
            DocumentStatus::Ready,
            $document->fresh()->status,
        );
    }

    private function createDocument(): Document
    {
        Storage::fake('documents');

        return app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'trusted-notes.txt',
                "Trusted document content.\n",
            ),
        );
    }

    private function createRun(
        Document $document,
        ProcessingRunStatus $status,
    ): ProcessingRun {
        return $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => $status,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);
    }
}
