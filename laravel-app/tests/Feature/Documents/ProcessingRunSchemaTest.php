<?php

namespace Tests\Feature\Documents;

use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Documents\DocumentStorageService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessingRunSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_columns_defaults_and_model_casts_are_available(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'trusted-notes.txt',
                "Trusted document content.\n",
            ),
        );

        $processingRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Processing,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'started_at' => now(),
            'indexing_started_at' => now(),
            'failed_at' => now(),
        ])->fresh();

        $this->assertTrue(Schema::hasColumns('document_processing_runs', [
            'kind',
            'started_at',
            'indexing_started_at',
            'failed_at',
        ]));

        $this->assertSame(
            ProcessingRunKind::Initial,
            $processingRun->kind,
        );

        $this->assertInstanceOf(
            CarbonInterface::class,
            $processingRun->started_at,
        );

        $this->assertInstanceOf(
            CarbonInterface::class,
            $processingRun->indexing_started_at,
        );

        $this->assertInstanceOf(
            CarbonInterface::class,
            $processingRun->failed_at,
        );

        $this->assertInstanceOf(
            ProcessingRun::class,
            $processingRun,
        );
    }
}
