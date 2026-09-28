<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentAvailability;
use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentDetailsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_keep_active_run_separate_from_latest_reprocessing_attempt(): void
    {
        Http::fake([
            '*' => Http::response([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                    ProcessingProfile::HybridLocal->value,
                ],
            ]),
        ]);

        $user = User::factory()->create();

        $document = $this->createReadyDocument($user);

        $activeRun = $this->createProcessingRun(
            $document,
            [
                'profile' => ProcessingProfile::Cloud,
                'status' => ProcessingRunStatus::Indexed,
                'kind' => ProcessingRunKind::Initial,
                'total_pages' => 12,
                'total_chunks' => 48,
                'started_at' => Carbon::parse('2026-09-04 08:01:00'),
                'indexing_started_at' => Carbon::parse('2026-09-04 08:02:00'),
                'indexed_at' => Carbon::parse('2026-09-04 08:03:00'),
            ],
        );

        $document->forceFill([
            'active_processing_run_id' => $activeRun->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        $latestAttempt = $this->createProcessingRun(
            $document,
            [
                'profile' => ProcessingProfile::HybridLocal,
                'status' => ProcessingRunStatus::Processing,
                'kind' => ProcessingRunKind::Reprocessing,
                'started_at' => Carbon::parse('2026-09-04 09:01:00'),
            ],
        );

        $response = $this
            ->actingAs($user)
            ->get(route('documents.show', $document));

        $response
            ->assertOk()
            ->assertViewHas(
                'documentDetail',
                function ($detail) use ($activeRun, $latestAttempt): bool {
                    return $detail->summary->availability === DocumentAvailability::Ready
                        && $detail->summary->activeRun?->id === $activeRun->id
                        && $detail->summary->latestAttempt?->id === $latestAttempt->id
                        && $detail->summary->reprocessingInProgress === true;
                },
            )
            ->assertSee('إعادة معالجة جديدة جارية')
            ->assertSee('النسخة المعالجة السابقة ما زالت هي النسخة الفعالة حاليًا')
            ->assertSee('المعالجة الأولى')
            ->assertSee('إعادة المعالجة')
            ->assertSee('بدأت المعالجة')
            ->assertSee('2026-09-04 09:01:00');
    }

    public function test_failed_reprocessing_does_not_make_ready_document_appear_failed_or_leak_raw_failure(): void
    {
        Http::fake([
            '*' => Http::response([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                ],
            ]),
        ]);

        $user = User::factory()->create();

        $document = $this->createReadyDocument($user);

        $activeRun = $this->createProcessingRun(
            $document,
            [
                'profile' => ProcessingProfile::Cloud,
                'status' => ProcessingRunStatus::Indexed,
                'kind' => ProcessingRunKind::Initial,
                'started_at' => Carbon::parse('2026-09-04 08:01:00'),
                'indexing_started_at' => Carbon::parse('2026-09-04 08:02:00'),
                'indexed_at' => Carbon::parse('2026-09-04 08:03:00'),
            ],
        );

        $document->forceFill([
            'active_processing_run_id' => $activeRun->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        $rawFailure = 'Provider secret failure: internal-token-12345';

        $failedAttempt = $this->createProcessingRun(
            $document,
            [
                'profile' => ProcessingProfile::Cloud,
                'status' => ProcessingRunStatus::Failed,
                'kind' => ProcessingRunKind::Reprocessing,
                'error_code' => 'provider_internal_error',
                'failure_reason' => $rawFailure,
                'started_at' => Carbon::parse('2026-09-04 09:01:00'),
                'failed_at' => Carbon::parse('2026-09-04 09:02:00'),
            ],
        );

        $response = $this
            ->actingAs($user)
            ->get(route('documents.show', $document));

        $response
            ->assertOk()
            ->assertViewHas(
                'documentDetail',
                function ($detail) use ($activeRun, $failedAttempt): bool {
                    return $detail->summary->availability === DocumentAvailability::Ready
                        && $detail->summary->activeRun?->id === $activeRun->id
                        && $detail->summary->latestAttempt?->id === $failedAttempt->id;
                },
            )
            ->assertSee('فشلت آخر محاولة لإعادة المعالجة')
            ->assertSee(__('documents.failure.processing_failed'))
            ->assertSee('النسخة السابقة ما زالت فعالة ومتاحة للاستخدام.')
            ->assertDontSee($rawFailure);
    }

    public function test_reprocess_action_is_disabled_when_active_profile_is_currently_unavailable(): void
    {
        Http::fake([
            '*' => Http::response([
                'available_profiles' => [
                    ProcessingProfile::HybridLocal->value,
                ],
            ]),
        ]);

        $user = User::factory()->create();

        $document = $this->createReadyDocument($user);

        $activeRun = $this->createProcessingRun(
            $document,
            [
                'profile' => ProcessingProfile::Cloud,
                'status' => ProcessingRunStatus::Indexed,
                'kind' => ProcessingRunKind::Initial,
                'started_at' => now()->subMinutes(3),
                'indexing_started_at' => now()->subMinutes(2),
                'indexed_at' => now()->subMinute(),
            ],
        );

        $document->forceFill([
            'active_processing_run_id' => $activeRun->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        $response = $this
            ->actingAs($user)
            ->get(route('documents.show', $document));

        $response
            ->assertOk()
            ->assertSee('إعادة المعالجة غير متاحة')
            ->assertDontSee('بدء إعادة المعالجة');
    }

    public function test_terminal_document_details_do_not_enable_polling(): void
    {
        Http::fake([
            '*' => Http::response([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                ],
            ]),
        ]);

        $user = User::factory()->create();

        $document = $this->createReadyDocument($user);

        $document->forceFill([
            'status' => DocumentStatus::Failed,
        ])->save();

        $this
            ->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertDontSee('data-document-poll-url', false);
    }

    public function test_processing_document_details_use_livewire_polling_without_legacy_reload_contract(): void
    {
        Http::fake([
            '*' => Http::response([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                ],
            ]),
        ]);

        $user = User::factory()->create();

        $document = $this->createReadyDocument($user);

        $document->forceFill([
            'status' => DocumentStatus::Queued,
        ])->save();

        $this
            ->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSeeHtml('wire:poll.5s="poll"')
            ->assertDontSee('data-document-poll-url', false)
            ->assertDontSee('data-document-poll-error', false);
    }

    private function createReadyDocument(User $user): Document
    {
        return $user->documents()->create([
            'original_name' => 'details-document.pdf',
            'stored_name' => 'details-document-stored.pdf',
            'title' => 'وثيقة الاختبار',
            'file_path' => $user->id.'/details-document-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 4096,
            'sha256' => hash('sha256', 'details-document'),
        ]);
    }

    /**
     * Create a valid processing run fixture using the current database contract.
     *
     * Required JSON fields are always populated so individual tests only need
     * to specify the state that is relevant to the scenario being verified.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createProcessingRun(
        Document $document,
        array $attributes = [],
    ): ProcessingRun {
        return $document->processingRuns()->create(
            array_merge(
                [
                    'profile' => ProcessingProfile::Cloud,
                    'status' => ProcessingRunStatus::Pending,
                    'kind' => ProcessingRunKind::Initial,
                    'profile_snapshot' => [],
                    'stage_timings_ms' => [],
                ],
                $attributes,
            ),
        );
    }
}
