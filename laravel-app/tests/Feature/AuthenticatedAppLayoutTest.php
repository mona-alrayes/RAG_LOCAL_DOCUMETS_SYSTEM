<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticatedAppLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_layout_exposes_fluid_shell_and_sidebar_hooks(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('workspace'));

        $response
            ->assertOk()
            ->assertSee('data-authenticated-shell', false)
            ->assertSee('data-authenticated-sidebar', false);
    }

    public function test_admin_navigation_is_visible_only_to_verified_administrators(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('workspace'))->assertDontSee('لوحة الإدارة والتقييم');
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user)->get(route('workspace'))->assertSee('لوحة الإدارة والتقييم')->assertSee('/admin');
    }
}
