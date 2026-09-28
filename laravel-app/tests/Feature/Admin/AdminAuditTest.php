<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class AdminAuditTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    public function test_admin_resource_access_is_audited_without_request_content(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/documents?search=private-secret')->assertOk();
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'admin.page.view', 'outcome' => 'success']);
        $this->assertStringNotContainsString('private-secret', AdminAuditLog::all()->toJson());
    }
}
