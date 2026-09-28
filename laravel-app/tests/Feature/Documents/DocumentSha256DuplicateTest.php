<?php

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentSha256DuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_stores_sha256_calculated_from_stored_content(): void
    {
        Storage::fake('document_quarantine');

        $user = User::factory()->create();
        $content = "SHA-256 document content.\n";

        $response = $this->actingAs($user)
            ->post('/documents', [
                'document' => UploadedFile::fake()->createWithContent(
                    'report.txt',
                    $content,
                ),
                'processing_profile' => 'cloud',
            ]);

        $document = Document::query()->sole();

        $response
            ->assertRedirectToRoute('documents.show', $document)
            ->assertSessionHas(
                'success',
                __('documents.commands.upload.success'),
            );

        $this->assertSame(
            hash('sha256', $content),
            $document->sha256,
        );

        Storage::disk('document_quarantine')
            ->assertExists($document->file_path);
    }

    public function test_duplicate_content_for_same_user_redirects_to_existing_document_and_new_file_is_removed(): void
    {
        Storage::fake('document_quarantine');

        $user = User::factory()->create();
        $content = "Same document content.\n";

        $firstResponse = $this->actingAs($user)
            ->post('/documents', [
                'document' => UploadedFile::fake()->createWithContent(
                    'original.txt',
                    $content,
                ),
                'processing_profile' => 'cloud',
            ]);

        $original = Document::query()->sole();

        $firstResponse
            ->assertRedirectToRoute('documents.show', $original)
            ->assertSessionHas(
                'success',
                __('documents.commands.upload.success'),
            );

        $this->actingAs($user)
            ->post('/documents', [
                'document' => UploadedFile::fake()->createWithContent(
                    'renamed-copy.txt',
                    $content,
                ),
                'processing_profile' => 'cloud',
            ])
            ->assertRedirectToRoute('documents.show', $original)
            ->assertSessionHas(
                'warning',
                __('documents.commands.upload.duplicate'),
            );

        $this->assertDatabaseCount('documents', 1);

        $this->assertCount(
            1,
            Storage::disk('document_quarantine')->allFiles(),
        );

        Storage::disk('document_quarantine')
            ->assertExists($original->file_path);
    }
}
