<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_app_pages_render_the_shared_navigation_shell(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        foreach ([
            route('workspace'),
            route('documents.index'),
            route('settings.account'),
        ] as $url) {
            $this
                ->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertSee('مساحة العمل')
                ->assertSee('الوثائق')
                ->assertSee('إعدادات الحساب')
                ->assertSee('تسجيل الخروج')
                ->assertSee($user->name);
        }
    }

    public function test_sidebar_shows_only_the_authenticated_users_five_most_recent_documents(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        for ($index = 1; $index <= 6; $index++) {
            $document = $user->documents()->create([
                'original_name' => "user-document-{$index}.pdf",
                'stored_name' => "stored-user-document-{$index}.pdf",
                'file_path' => "documents/stored-user-document-{$index}.pdf",
                'file_type' => 'pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'sha256' => hash('sha256', "user-document-{$index}"),
            ]);

            $document->forceFill([
                'created_at' => now()->addSeconds($index),
                'updated_at' => now()->addSeconds($index),
            ])->save();
        }

        $otherUser->documents()->create([
            'original_name' => 'other-user-private-document.pdf',
            'stored_name' => 'other-user-private-document-stored.pdf',
            'file_path' => 'documents/other-user-private-document-stored.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => hash('sha256', 'other-user-private-document'),
        ]);

        $this
            ->actingAs($user)
            ->get(route('settings.account'))
            ->assertOk()
            ->assertSee('user-document-6.pdf')
            ->assertSee('user-document-5.pdf')
            ->assertSee('user-document-4.pdf')
            ->assertSee('user-document-3.pdf')
            ->assertSee('user-document-2.pdf')
            ->assertDontSee('user-document-1.pdf')
            ->assertDontSee('other-user-private-document.pdf')
            ->assertSee('عرض كل الوثائق');
    }
}
