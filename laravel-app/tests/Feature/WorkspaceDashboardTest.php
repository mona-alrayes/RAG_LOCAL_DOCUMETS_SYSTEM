<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_shows_empty_state_when_user_has_no_documents(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this
            ->actingAs($user)
            ->get(route('workspace'))
            ->assertOk()
            ->assertSee('لا توجد لديك وثائق بعد')
            ->assertViewHas('dashboard', function (array $dashboard): bool {
                return $dashboard['totalDocuments'] === 0
                    && $dashboard['readyDocuments'] === 0
                    && $dashboard['processingDocuments'] === 0
                    && $dashboard['failedDocuments'] === 0;
            });
    }

    public function test_workspace_dashboard_is_scoped_to_authenticated_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->createDocument(
            user: $user,
            name: 'processing-document.pdf',
            status: DocumentStatus::Processing,
            sha: 'a',
        );

        $this->createReadyDocument(
            user: $user,
            name: 'ready-document.pdf',
            sha: 'b',
        );

        $this->createDocument(
            user: $user,
            name: 'failed-document.pdf',
            status: DocumentStatus::Failed,
            sha: 'c',
        );

        $this->createDocument(
            user: $otherUser,
            name: 'other-user-document.pdf',
            status: DocumentStatus::Processing,
            sha: 'd',
        );

        $response = $this
            ->actingAs($user)
            ->get(route('workspace'));

        $response
            ->assertOk()
            ->assertSee('processing-document.pdf')
            ->assertSee('ready-document.pdf')
            ->assertSee('failed-document.pdf')
            ->assertDontSee('other-user-document.pdf')
            ->assertViewHas('dashboard', function (array $dashboard): bool {
                return $dashboard['totalDocuments'] === 3
                    && $dashboard['readyDocuments'] === 1
                    && $dashboard['processingDocuments'] === 1
                    && $dashboard['failedDocuments'] === 1;
            });
    }

    private function createDocument(
        User $user,
        string $name,
        DocumentStatus $status,
        string $sha,
    ): Document {
        $document = $user->documents()->create([
            'original_name' => $name,
            'stored_name' => 'stored-'.$name,
            'file_path' => 'documents/stored-'.$name,
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => str_repeat($sha, 64),
        ]);

        $document->forceFill([
            'status' => $status->value,
        ])->save();

        return $document;
    }

    private function createReadyDocument(
        User $user,
        string $name,
        string $sha,
    ): Document {
        $document = $this->createDocument(
            user: $user,
            name: $name,
            status: DocumentStatus::Pending,
            sha: $sha,
        );

        $run = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'indexed_at' => now(),
        ]);
        $document->forceFill([
            'status' => DocumentStatus::Ready->value,
            'active_processing_run_id' => $run->id,
        ])->save();

        return $document;
    }

    public function test_workspace_does_not_expose_raw_processing_failure_reason(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $document = $this->createDocument(
            user: $user,
            name: 'failed-processing-document.pdf',
            status: DocumentStatus::Failed,
            sha: 'e',
        );

        $rawFailure = 'Internal provider error: secret-token-12345';

        $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Failed,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'failure_reason' => $rawFailure,
            'failed_at' => now(),
        ]);

        $this
            ->actingAs($user)
            ->get(route('workspace'))
            ->assertOk()
            ->assertSee(__('documents.failure.processing_failed'))
            ->assertDontSee($rawFailure);
    }
}
