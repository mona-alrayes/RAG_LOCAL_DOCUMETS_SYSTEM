<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentPollingEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_poll_document_presentation_while_processing(): void
    {
        $user = User::factory()->create();

        $document = $this->createDocument(
            $user,
            DocumentStatus::Queued,
        );

        $run = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Pending,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson(route('documents.poll', $document));

        $response
            ->assertOk()
            ->assertJsonPath('data.summary.id', $document->id)
            ->assertJsonPath('data.summary.poll_required', true)
            ->assertJsonPath(
                'data.summary.latest_attempt.id',
                $run->id,
            )
            ->assertJsonPath(
                'data.summary.latest_attempt.status.code',
                ProcessingRunStatus::Pending->value,
            )
            ->assertJsonMissingPath(
                'data.summary.latest_attempt.failure_reason',
            )
            ->assertJsonMissingPath(
                'data.summary.latest_attempt.qdrant_collection',
            )
            ->assertJsonMissingPath(
                'data.summary.latest_attempt.profile_snapshot',
            );
    }

    public function test_terminal_document_presentation_does_not_require_polling(): void
    {
        $user = User::factory()->create();

        $document = $this->createDocument(
            $user,
            DocumentStatus::Ready,
        );

        $run = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'indexing_started_at' => now()->subMinute(),
            'indexed_at' => now(),
        ]);

        $document->forceFill([
            'active_processing_run_id' => $run->id,
        ])->save();

        $this
            ->actingAs($user)
            ->getJson(route('documents.poll', $document))
            ->assertOk()
            ->assertJsonPath(
                'data.summary.poll_required',
                false,
            )
            ->assertJsonPath(
                'data.summary.latest_attempt.status.code',
                ProcessingRunStatus::Indexed->value,
            );
    }

    public function test_document_polling_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $document = $this->createDocument(
            $owner,
            DocumentStatus::Queued,
        );

        $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Pending,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $this
            ->actingAs($otherUser)
            ->getJson(route('documents.poll', $document))
            ->assertForbidden();
    }

    private function createDocument(
        User $user,
        DocumentStatus $status,
    ): Document {
        $document = $user->documents()->create([
            'original_name' => 'polling-document.pdf',
            'stored_name' => 'polling-document-stored.pdf',
            'title' => 'Polling Document',
            'file_path' => $user->id.'/polling-document-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 4096,
            'sha256' => hash(
                'sha256',
                'polling-document-'.$user->id,
            ),
        ]);

        $document->forceFill([
            'status' => $status,
        ])->save();

        return $document;
    }
}
