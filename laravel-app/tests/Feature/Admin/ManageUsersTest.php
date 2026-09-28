<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Pages\ListUsers;
use App\Models\User;
use App\Services\Admin\ManageUsers;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class ManageUsersTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    private function values(array $overrides = []): array
    {
        return array_merge(['name' => 'New member', 'email' => 'new@example.test', 'password' => 'Temporary password 123!', 'password_confirmation' => 'Temporary password 123!', 'is_admin' => false, 'verified' => true, 'suspended' => false], $overrides);
    }

    public function test_filament_creates_then_edits_account_and_hashes_password(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListUsers::class)->callAction('createAccount', data: $this->values())->assertHasNoActionErrors();
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Temporary password 123!', $user->password));
        Livewire::test(ListUsers::class)->callTableAction('manage', $user, data: $this->values(['name' => 'Updated member', 'password' => '', 'password_confirmation' => '', 'is_admin' => true, 'suspended' => true]))->assertHasNoTableActionErrors();
        $this->assertSame('Updated member', $user->fresh()->name);
        $this->assertNotNull($user->fresh()->suspended_at);
        $this->assertTrue(Hash::check('Temporary password 123!', $user->fresh()->password));
    }

    public function test_self_lockout_is_rejected_and_a_regular_user_cannot_manage_accounts(): void
    {
        $admin = $this->admin();
        try {
            app(ManageUsers::class)->save($admin, $admin->id, $this->values(['email' => $admin->email, 'is_admin' => false]));
            $this->fail('Self lockout accepted');
        } catch (ValidationException) {
            $this->assertTrue($admin->fresh()->is_admin);
        }
        $this->expectException(AuthorizationException::class);
        app(ManageUsers::class)->save(User::factory()->create(), null, $this->values());
    }

    public function test_suspension_blocks_existing_workspace_and_admin_sessions(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['suspended_at' => now()])->save();
        $this->actingAs($user)->get('/documents')->assertForbidden();
        $admin = $this->admin();
        $admin->forceFill(['suspended_at' => now()])->save();
        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }

    public function test_deletion_requires_document_cleanup_and_allows_empty_account(): void
    {
        $admin = $this->admin();
        $doc = $this->document();
        try {
            app(ManageUsers::class)->delete($admin, $doc->user_id);
            $this->fail('Deleted account with document vectors');
        } catch (ValidationException) {
            $this->assertModelExists($doc->user);
        }
        $user = User::factory()->create();
        app(ManageUsers::class)->delete($admin, $user->id);
        $this->assertModelMissing($user);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.delete', 'subject_id' => $user->id]);
    }
}
