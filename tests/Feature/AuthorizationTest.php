<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/users')->assertRedirect('/login');
    }

    public function test_public_registration_can_be_switched_off(): void
    {
        $this->get('/register')->assertOk();

        \App\Models\Setting::put(\App\Http\Controllers\Auth\RegisteredUserController::SETTING, '0');

        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'password'])->assertNotFound();
        \App\Models\Setting::flushCache();
    }

    public function test_every_role_can_view_the_dashboard(): void
    {
        foreach ([$this->superAdmin(), $this->admin(), $this->agent()] as $user) {
            $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Dashboard');
        }
    }

    public function test_user_without_a_role_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    public function test_only_super_admin_can_manage_users(): void
    {
        $this->actingAs($this->agent())->get('/users')->assertForbidden();
        $this->actingAs($this->admin())->get('/users')->assertForbidden();
        $this->actingAs($this->superAdmin())->get('/users')->assertOk();
    }

    public function test_agent_cannot_view_activity_logs(): void
    {
        $this->actingAs($this->agent())->get('/activity-logs')->assertForbidden();
        $this->actingAs($this->admin())->get('/activity-logs')->assertOk();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->agent()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_deactivated_during_session_is_logged_out(): void
    {
        $user = $this->agent();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_is_recorded_in_activity_log_and_updates_last_login(): void
    {
        $user = $this->agent();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'auth.login']);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_failed_login_is_logged_without_the_password(): void
    {
        $user = $this->agent();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-secret-123']);

        $log = ActivityLog::where('action', 'auth.failed')->firstOrFail();
        $this->assertStringNotContainsString('wrong-secret-123', json_encode($log->properties));
    }

    public function test_responses_include_security_headers(): void
    {
        $this->get('/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
