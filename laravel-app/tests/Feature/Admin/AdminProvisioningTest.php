<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_verified_existing_users_can_be_granted_and_revoked(): void
    {
        $user = User::factory()->unverified()->create();
        $this->artisan('rag:admin', ['email' => $user->email])->assertFailed();
        $this->assertFalse($user->fresh()->is_admin);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->artisan('rag:admin', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'admin.grant.cli', 'subject_id' => $user->id]);
        $this->artisan('rag:admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);
    }
}
