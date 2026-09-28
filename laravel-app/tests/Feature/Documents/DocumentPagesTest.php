<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_only_displays_the_authenticated_users_documents(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $owner->documents()->create([
            'original_name' => 'owner-document.pdf',
            'stored_name' => 'owner-stored.pdf',
            'file_path' => 'documents/owner-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => str_repeat('a', 64),
        ]);

        $otherUser->documents()->create([
            'original_name' => 'other-document.pdf',
            'stored_name' => 'other-stored.pdf',
            'file_path' => 'documents/other-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 2048,
            'sha256' => str_repeat('b', 64),
        ]);

        $response = $this->actingAs($owner)->get('/documents');

        $response
            ->assertOk()
            ->assertSee('owner-document.pdf')
            ->assertDontSee('other-document.pdf');
    }

    public function test_index_can_filter_documents_by_search(): void
    {
        $user = User::factory()->create();

        $user->documents()->create([
            'original_name' => 'annual-report.pdf',
            'stored_name' => 'annual-report-stored.pdf',
            'file_path' => 'documents/annual-report-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => hash('sha256', 'annual-report'),
        ]);

        $user->documents()->create([
            'original_name' => 'meeting-notes.txt',
            'stored_name' => 'meeting-notes-stored.txt',
            'file_path' => 'documents/meeting-notes-stored.txt',
            'file_type' => 'txt',
            'mime_type' => 'text/plain',
            'file_size' => 512,
            'sha256' => hash('sha256', 'meeting-notes'),
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/documents?search=annual');

        $response
            ->assertOk()
            ->assertViewHas(
                'documents',
                fn ($documents): bool => $documents->total() === 1
                    && $documents->first()->originalName === 'annual-report.pdf',
            );
    }

    public function test_index_can_filter_documents_by_status(): void
    {
        $user = User::factory()->create();

        $user->documents()->create([
            'original_name' => 'pending-document.pdf',
            'stored_name' => 'pending-document-stored.pdf',
            'file_path' => 'documents/pending-document-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => hash('sha256', 'pending-document'),
        ]);

        $failedDocument = $user->documents()->create([
            'original_name' => 'failed-document.pdf',
            'stored_name' => 'failed-document-stored.pdf',
            'file_path' => 'documents/failed-document-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => hash('sha256', 'failed-document'),
        ]);

        $failedDocument->status = DocumentStatus::Failed;
        $failedDocument->save();

        $response = $this
            ->actingAs($user)
            ->get('/documents?status=failed');

        $response
            ->assertOk()
            ->assertViewHas(
                'documents',
                fn ($documents): bool => $documents->total() === 1
                    && $documents->first()->originalName === 'failed-document.pdf',
            );
    }

    public function test_index_displays_distinct_empty_state_when_user_has_no_documents(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get('/documents')
            ->assertOk()
            ->assertSee('لا توجد وثائق حتى الآن')
            ->assertSee('pending')
            ->assertDontSee('documents.availability.pending')
            ->assertDontSee('لا توجد نتائج مطابقة');
    }

    public function test_index_displays_filtered_empty_state_when_filters_match_nothing(): void
    {
        $user = User::factory()->create();

        $user->documents()->create([
            'original_name' => 'existing-document.pdf',
            'stored_name' => 'existing-document-stored.pdf',
            'file_path' => 'documents/existing-document-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => hash('sha256', 'existing-document'),
        ]);

        $this
            ->actingAs($user)
            ->get('/documents?search=does-not-exist')
            ->assertOk()
            ->assertSee('لا توجد نتائج مطابقة')
            ->assertSee('مسح جميع الفلاتر')
            ->assertDontSee('لا توجد وثائق حتى الآن');
    }

    public function test_index_pagination_preserves_active_filters(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 11) as $index) {
            $user->documents()->create([
                'original_name' => "document-{$index}.pdf",
                'stored_name' => "document-{$index}-stored.pdf",
                'file_path' => "documents/document-{$index}-stored.pdf",
                'file_type' => 'pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'sha256' => hash('sha256', "document-{$index}"),
            ]);
        }

        $this
            ->actingAs($user)
            ->get('/documents?file_type=pdf')
            ->assertOk()
            ->assertSee('file_type=pdf&amp;page=2', false);
    }

    public function test_index_displays_profiles_and_disables_unavailable_profile_without_fallback(): void
    {
        $user = User::factory()->create();

        Http::fake([
            '*' => Http::response([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                ],
            ]),
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/documents');

        $response
            ->assertOk()
            ->assertViewHas(
                'availableProcessingProfiles',
                fn (array $profiles): bool => $profiles === [
                    ProcessingProfile::Cloud,
                ],
            )
            ->assertSee('سحابي')
            ->assertSee('محلي هجين')
            ->assertSee('غير متاح حاليًا');

        $dom = new \DOMDocument;

        @$dom->loadHTML($response->getContent());

        $xpath = new \DOMXPath($dom);

        $fileInput = $xpath
            ->query('//input[@name="document" and @type="file"]')
            ->item(0);

        $cloudInput = $xpath
            ->query('//input[@name="processing_profile" and @value="cloud"]')
            ->item(0);

        $hybridLocalInput = $xpath
            ->query('//input[@name="processing_profile" and @value="hybrid_local"]')
            ->item(0);

        $this->assertNotNull($fileInput);
        $this->assertFalse($fileInput->hasAttribute('multiple'));
        $this->assertSame(
            '.pdf,.docx,.txt',
            $fileInput->getAttribute('accept'),
        );

        $this->assertNotNull($cloudInput);
        $this->assertFalse(
            $cloudInput->hasAttribute('disabled'),
        );

        $this->assertNotNull($hybridLocalInput);
        $this->assertTrue(
            $hybridLocalInput->hasAttribute('disabled'),
        );
    }

    public function test_index_disables_upload_when_no_processing_profile_is_available(): void
    {
        $user = User::factory()->create();

        Http::fake([
            '*' => Http::response([
                'available_profiles' => [],
            ]),
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/documents');

        $response
            ->assertOk()
            ->assertViewHas(
                'availableProcessingProfiles',
                fn (array $profiles): bool => $profiles === [],
            )
            ->assertSee(
                'لا توجد طريقة معالجة متاحة حاليًا. يرجى المحاولة لاحقًا.',
            );

        $this->assertUploadControlsAreDisabled($response->getContent());
    }

    public function test_index_fails_closed_when_capability_response_is_invalid(): void
    {
        $user = User::factory()->create();

        Http::fake([
            '*' => Http::response([
                'available_profiles' => 'invalid',
            ]),
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/documents');

        $response
            ->assertOk()
            ->assertViewHas(
                'availableProcessingProfiles',
                fn (array $profiles): bool => $profiles === [],
            )
            ->assertSee(
                'لا توجد طريقة معالجة متاحة حاليًا. يرجى المحاولة لاحقًا.',
            );

        $this->assertUploadControlsAreDisabled($response->getContent());
    }

    public function test_owner_can_view_their_document_details(): void
    {
        $owner = User::factory()->create();

        $document = $owner->documents()->create([
            'original_name' => 'owner-details.pdf',
            'stored_name' => 'owner-details-stored.pdf',
            'file_path' => 'documents/owner-details-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => str_repeat('c', 64),
        ]);

        $response = $this
            ->actingAs($owner)
            ->get("/documents/{$document->id}");

        $response
            ->assertOk()
            ->assertSee('owner-details.pdf');
    }

    public function test_user_cannot_view_another_users_document_details(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $document = $owner->documents()->create([
            'original_name' => 'private-document.pdf',
            'stored_name' => 'private-document-stored.pdf',
            'file_path' => 'documents/private-document-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => str_repeat('d', 64),
        ]);

        $this
            ->actingAs($otherUser)
            ->get("/documents/{$document->id}")
            ->assertForbidden();
    }

    private function assertUploadControlsAreDisabled(string $html): void
    {
        $dom = new \DOMDocument;

        @$dom->loadHTML($html);

        $xpath = new \DOMXPath($dom);

        $cloudInput = $xpath
            ->query('//input[@name="processing_profile" and @value="cloud"]')
            ->item(0);

        $hybridLocalInput = $xpath
            ->query('//input[@name="processing_profile" and @value="hybrid_local"]')
            ->item(0);

        $submitButton = $xpath
            ->query(
                '//button[@type="submit" and contains(normalize-space(.), "رفع الوثيقة")]',
            )
            ->item(0);

        $this->assertNotNull($cloudInput);
        $this->assertTrue(
            $cloudInput->hasAttribute('disabled'),
        );

        $this->assertNotNull($hybridLocalInput);
        $this->assertTrue(
            $hybridLocalInput->hasAttribute('disabled'),
        );

        $this->assertNotNull($submitButton);
        $this->assertTrue(
            $submitButton->hasAttribute('disabled'),
        );
    }

    public function test_index_displays_safe_flash_feedback_messages(): void
    {
        $user = User::factory()->create();

        Http::fake([
            '*' => Http::response([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                ],
            ]),
        ]);

        $this
            ->actingAs($user)
            ->withSession([
                'success' => 'تم تنفيذ العملية بنجاح.',
                'warning' => 'تعذر تنفيذ جزء من العملية.',
                'error' => 'تعذر تنفيذ العملية.',
            ])
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('تم تنفيذ العملية بنجاح.')
            ->assertSee('تعذر تنفيذ جزء من العملية.')
            ->assertSee('تعذر تنفيذ العملية.');
    }
}
