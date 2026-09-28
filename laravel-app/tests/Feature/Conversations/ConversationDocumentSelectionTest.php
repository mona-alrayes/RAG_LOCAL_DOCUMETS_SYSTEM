<?php

namespace Tests\Feature\Conversations;

use App\Livewire\Conversations\DocumentSelector;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ConversationDocumentSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_selection_page_and_only_see_own_documents(): void
    {
        $user = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثتي',
        ]);

        $ownedDocument = $this->createDocument($user, 'owned.pdf');
        $otherDocument = $this->createDocument($otherUser, 'foreign.pdf');

        $response = $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation));

        $response
            ->assertOk()
            ->assertSee($ownedDocument->original_name)
            ->assertDontSee($otherDocument->original_name);
    }

    public function test_other_user_cannot_view_or_update_conversation_document_selection(): void
    {
        $owner = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $owner->conversations()->create([
            'title' => 'محادثة المالك',
        ]);

        $otherUsersDocument = $this->createDocument(
            $otherUser,
            'other-user.pdf',
        );

        $this
            ->actingAs($otherUser)
            ->get(route('conversations.show', $conversation))
            ->assertForbidden();

        $this
            ->actingAs($otherUser)
            ->put(
                route('conversations.documents.update', $conversation),
                [
                    'document_ids' => [$otherUsersDocument->id],
                ],
            )
            ->assertForbidden();

        $this->assertDatabaseCount('conversation_document', 0);
    }

    public function test_guest_cannot_view_or_update_conversation_document_selection(): void
    {
        $owner = $this->verifiedUser();

        $conversation = $owner->conversations()->create([
            'title' => 'محادثة خاصة',
        ]);

        $this
            ->get(route('conversations.show', $conversation))
            ->assertRedirect(route('login'));

        $this
            ->put(
                route('conversations.documents.update', $conversation),
                [],
            )
            ->assertRedirect(route('login'));
    }

    public function test_owner_can_select_one_or_multiple_documents_without_duplicate_pivot_rows(): void
    {
        $user = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة الوثائق',
        ]);

        $firstDocument = $this->createDocument($user, 'first.pdf');
        $secondDocument = $this->createDocument($user, 'second.pdf');

        $this
            ->actingAs($user)
            ->put(
                route('conversations.documents.update', $conversation),
                [
                    'document_ids' => [$firstDocument->id],
                ],
            )
            ->assertRedirect(route('conversations.show', $conversation));

        $this->assertDatabaseHas('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $firstDocument->id,
        ]);

        $this->assertDatabaseCount('conversation_document', 1);

        $selection = [
            $firstDocument->id,
            $secondDocument->id,
        ];

        $this
            ->actingAs($user)
            ->put(
                route('conversations.documents.update', $conversation),
                [
                    'document_ids' => $selection,
                ],
            )
            ->assertRedirect(route('conversations.show', $conversation));

        $this->assertDatabaseCount('conversation_document', 2);

        $this
            ->actingAs($user)
            ->put(
                route('conversations.documents.update', $conversation),
                [
                    'document_ids' => $selection,
                ],
            )
            ->assertRedirect(route('conversations.show', $conversation));

        $this->assertDatabaseCount('conversation_document', 2);
    }

    public function test_owner_can_change_and_clear_document_selection(): void
    {
        $user = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'تغيير الوثائق',
        ]);

        $firstDocument = $this->createDocument($user, 'first.pdf');
        $secondDocument = $this->createDocument($user, 'second.pdf');
        $thirdDocument = $this->createDocument($user, 'third.pdf');

        $conversation->documents()->attach([
            $firstDocument->id,
            $secondDocument->id,
        ]);

        $this
            ->actingAs($user)
            ->put(
                route('conversations.documents.update', $conversation),
                [
                    'document_ids' => [
                        $secondDocument->id,
                        $thirdDocument->id,
                    ],
                ],
            )
            ->assertRedirect(route('conversations.show', $conversation));

        $this->assertDatabaseMissing('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $firstDocument->id,
        ]);

        $this->assertDatabaseHas('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $secondDocument->id,
        ]);

        $this->assertDatabaseHas('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $thirdDocument->id,
        ]);

        $this
            ->actingAs($user)
            ->put(
                route('conversations.documents.update', $conversation),
            )
            ->assertRedirect(route('conversations.show', $conversation));

        $this->assertDatabaseCount('conversation_document', 0);
    }

    public function test_owner_cannot_attach_another_users_document(): void
    {
        $user = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة آمنة',
        ]);

        $ownedDocument = $this->createDocument($user, 'owned.pdf');
        $foreignDocument = $this->createDocument($otherUser, 'foreign.pdf');

        $conversation->documents()->attach($ownedDocument);

        $response = $this
            ->actingAs($user)
            ->from(route('conversations.show', $conversation))
            ->put(
                route('conversations.documents.update', $conversation),
                [
                    'document_ids' => [$foreignDocument->id],
                ],
            );

        $response
            ->assertRedirect(route('conversations.show', $conversation))
            ->assertSessionHasErrors('document_ids.0');

        $this->assertDatabaseHas('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $ownedDocument->id,
        ]);

        $this->assertDatabaseMissing('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $foreignDocument->id,
        ]);

        $this->assertDatabaseCount('conversation_document', 1);
    }

    public function test_selected_document_chips_only_show_persisted_owned_selection(): void
    {
        $user = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة chips',
        ]);

        $selectedDocument = $this->createDocument(
            $user,
            'selected.pdf',
        );

        $unselectedDocument = $this->createDocument(
            $user,
            'unselected.pdf',
        );

        $foreignDocument = $this->createDocument(
            $otherUser,
            'foreign.pdf',
        );

        $conversation->documents()->attach([
            $selectedDocument->id,
            $foreignDocument->id,
        ]);

        Livewire::actingAs($user)
            ->test(DocumentSelector::class, [
                'conversationId' => $conversation->id,
            ])
            ->assertSeeHtml(
                'data-selected-document-chip="'.$selectedDocument->id.'"',
            )
            ->assertDontSeeHtml(
                'data-selected-document-chip="'.$unselectedDocument->id.'"',
            )
            ->assertDontSeeHtml(
                'data-selected-document-chip="'.$foreignDocument->id.'"',
            )
            ->assertDontSee($foreignDocument->original_name);
    }

    public function test_selected_document_chips_change_only_after_selection_is_saved(): void
    {
        $user = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'تحديث chips',
        ]);

        $firstDocument = $this->createDocument($user, 'first.pdf');
        $secondDocument = $this->createDocument($user, 'second.pdf');

        $conversation->documents()->attach($firstDocument);

        Livewire::actingAs($user)
            ->test(DocumentSelector::class, [
                'conversationId' => $conversation->id,
            ])
            ->assertSeeHtml(
                'data-selected-document-chip="'.$firstDocument->id.'"',
            )
            ->set('selectedDocumentIds', [
                (string) $secondDocument->id,
            ])
            ->assertSeeHtml(
                'data-selected-document-chip="'.$firstDocument->id.'"',
            )
            ->assertDontSeeHtml(
                'data-selected-document-chip="'.$secondDocument->id.'"',
            )
            ->call('save')
            ->assertHasNoErrors()
            ->assertDontSeeHtml(
                'data-selected-document-chip="'.$firstDocument->id.'"',
            )
            ->assertSeeHtml(
                'data-selected-document-chip="'.$secondDocument->id.'"',
            );

        $this->assertDatabaseMissing('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $firstDocument->id,
        ]);

        $this->assertDatabaseHas('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $secondDocument->id,
        ]);
    }

    public function test_livewire_selector_rejects_another_users_document(): void
    {
        $user = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة آمنة',
        ]);

        $foreignDocument = $this->createDocument(
            $otherUser,
            'foreign-livewire.pdf',
        );

        Livewire::actingAs($user)
            ->test(DocumentSelector::class, [
                'conversationId' => $conversation->id,
            ])
            ->set('selectedDocumentIds', [
                (string) $foreignDocument->id,
            ])
            ->call('save')
            ->assertHasErrors(['document_ids.0']);

        $this->assertDatabaseMissing('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $foreignDocument->id,
        ]);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function createDocument(User $user, string $name): Document
    {
        $unique = uniqid('', true);

        return $user->documents()->create([
            'original_name' => $name,
            'stored_name' => "{$unique}-{$name}",
            'title' => null,
            'file_path' => "documents/{$unique}-{$name}",
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => hash(
                'sha256',
                "{$user->id}-{$unique}-{$name}",
            ),
        ]);
    }
}
