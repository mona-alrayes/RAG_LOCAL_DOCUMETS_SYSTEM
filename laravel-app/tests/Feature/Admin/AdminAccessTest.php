<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Policies\DocumentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_regular_users_cannot_open_any_admin_resource(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['', '/users', '/documents', '/conversations', '/messages', '/processing-runs', '/audit-logs', '/chunks', '/evaluations', '/evaluation-runs'] as $path) {
            $this->get('/admin'.$path)->assertForbidden();
        }
    }

    public function test_verified_admin_can_open_core_resources(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);
        foreach (['', '/users', '/documents', '/conversations', '/messages', '/processing-runs', '/audit-logs', '/chunks', '/evaluations', '/evaluation-runs'] as $path) {
            $this->get('/admin'.$path)->assertOk();
        }
    }

    public function test_admin_flag_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();
        $user->fill(['is_admin' => true])->save();
        $this->assertFalse((bool) $user->fresh()->is_admin);
    }

    public function test_unverified_admin_cannot_enter_panel(): void
    {
        $admin = User::factory()->unverified()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }

    public function test_admin_role_does_not_bypass_document_ownership_in_user_workspace(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $owner = User::factory()->create();
        $document = $owner->documents()->make();
        $this->assertFalse((new DocumentPolicy)->view($admin, $document));
    }
}
