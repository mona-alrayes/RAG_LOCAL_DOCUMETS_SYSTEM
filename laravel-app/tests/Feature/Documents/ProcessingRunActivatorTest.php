<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Models\User;
use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\ProcessingRunActivator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class ProcessingRunActivatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_delayed_activation_never_replaces_a_newer_active_run(): void
    {
        Storage::fake('documents');
        $document = app(DocumentStorageService::class)->storePermanent(User::factory()->create(), UploadedFile::fake()->createWithContent('notes.txt', 'Notes'));
        $old = $document->processingRuns()->create(['profile' => ProcessingProfile::Cloud, 'status' => ProcessingRunStatus::Indexed, 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $new = $document->processingRuns()->create(['profile' => ProcessingProfile::Cloud, 'status' => ProcessingRunStatus::Indexed, 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $document->forceFill(['active_processing_run_id' => $new->id, 'status' => DocumentStatus::Ready])->save();
        try {
            app(ProcessingRunActivator::class)->activate($old);
            $this->fail('Expected stale activation to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('A newer processing run is already active.', $exception->getMessage());
        }
        $this->assertSame($new->id, $document->fresh()->active_processing_run_id);
    }

    public function test_it_activates_an_indexed_processing_run(): void
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
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Indexed,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        app(ProcessingRunActivator::class)->activate($processingRun);

        $this->assertSame(
            $processingRun->id,
            $document->fresh()->active_processing_run_id,
        );

        $this->assertSame(
            DocumentStatus::Ready,
            $document->fresh()->status,
        );
    }

    public function test_it_rejects_a_processing_run_that_is_not_indexed(): void
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
        ]);

        try {
            app(ProcessingRunActivator::class)->activate($processingRun);

            $this->fail('Expected activation failure was not thrown.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Only an indexed processing run may be activated.',
                $exception->getMessage(),
            );
        }

        $this->assertNull(
            $document->fresh()->active_processing_run_id,
        );
    }

    public function test_it_replaces_an_existing_active_processing_run(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'trusted-notes.txt',
                "Trusted document content.\n",
            ),
        );

        $activeRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $newRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Indexed,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $document->active_processing_run_id = $activeRun->id;
        $document->save();

        $previousRun = app(ProcessingRunActivator::class)
            ->activate($newRun);

        $this->assertNotNull($previousRun);
        $this->assertSame($activeRun->id, $previousRun->id);

        $this->assertSame(
            $newRun->id,
            $document->fresh()->active_processing_run_id,
        );

        $this->assertSame(
            DocumentStatus::Ready,
            $document->fresh()->status,
        );
    }

    public function test_failed_replacement_keeps_the_existing_active_run(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'trusted-notes.txt',
                "Trusted document content.\n",
            ),
        );

        $activeRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $newRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Processing,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $document->active_processing_run_id = $activeRun->id;
        $document->save();

        try {
            app(ProcessingRunActivator::class)->activate($newRun);

            $this->fail('Expected replacement failure was not thrown.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Only an indexed processing run may be activated.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            $activeRun->id,
            $document->fresh()->active_processing_run_id,
        );
    }
}
