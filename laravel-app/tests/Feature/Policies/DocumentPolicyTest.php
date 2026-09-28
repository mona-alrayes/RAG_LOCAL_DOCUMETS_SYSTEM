<?php

namespace Tests\Feature\Policies;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Verifies document ownership authorization boundaries.
 *
 * التحقق من حدود تفويض ملكية الوثائق.
 */
class DocumentPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_is_allowed_to_manage_their_document(): void
    {
        $owner = User::factory()->create();

        $document = $owner->documents()->create([
            'original_name' => 'document.pdf',
            'stored_name' => 'stored-document.pdf',
            'file_path' => 'documents/stored-document.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => str_repeat('a', 64),
        ]);

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(
                Gate::forUser($owner)->allows($ability, $document),
                "Expected the owner to be authorized to {$ability} the document.",
            );
        }
    }

    public function test_other_user_is_denied_from_managing_the_document(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $document = $owner->documents()->create([
            'original_name' => 'document.pdf',
            'stored_name' => 'stored-document.pdf',
            'file_path' => 'documents/stored-document.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => str_repeat('b', 64),
        ]);

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertFalse(
                Gate::forUser($otherUser)->allows($ability, $document),
                "Expected another user to be denied from {$ability} the document.",
            );
        }
    }
}
