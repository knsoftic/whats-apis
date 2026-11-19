<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_an_agent_with_assigned_numbers(): void
    {
        $admin = $this->superAdmin();
        $accounts = WhatsAppAccount::factory()->count(2)->create();

        $this->actingAs($admin)->post('/users', [
            'name' => 'Agent One',
            'email' => 'Agent1@Example.com',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'role' => Permissions::ROLE_AGENT,
            'is_active' => '1',
            'account_ids' => [$accounts[0]->id],
        ])->assertRedirect('/users');

        $agent = User::where('email', 'agent1@example.com')->firstOrFail();
        $this->assertTrue($agent->hasRole(Permissions::ROLE_AGENT));
        $this->assertTrue($agent->canAccessAccount($accounts[0]));
        $this->assertFalse($agent->canAccessAccount($accounts[1]));
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.created', 'user_id' => $admin->id]);
    }

    public function test_update_without_password_keeps_existing_password(): void
    {
        $admin = $this->superAdmin();
        $agent = $this->agent();
        $oldHash = $agent->password;

        $this->actingAs($admin)->put("/users/{$agent->id}", [
            'name' => 'Renamed',
            'email' => $agent->email,
            'password' => '',
            'password_confirmation' => '',
            'role' => Permissions::ROLE_AGENT,
            'is_active' => '1',
        ])->assertRedirect('/users');

        $agent->refresh();
        $this->assertSame('Renamed', $agent->name);
        $this->assertSame($oldHash, $agent->password);
        $this->assertTrue(Hash::check('password', $agent->password));
    }

    public function test_super_admin_cannot_delete_themselves(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->delete("/users/{$admin->id}")->assertSessionHas('error');
        $this->assertModelExists($admin);
    }

    public function test_last_super_admin_cannot_be_demoted(): void
    {
        $first = $this->superAdmin();
        $second = $this->superAdmin();

        // Second demotes first: allowed, because second is still a super admin.
        $this->actingAs($second)->put("/users/{$first->id}", [
            'name' => $first->name, 'email' => $first->email,
            'role' => Permissions::ROLE_ADMIN, 'is_active' => '1',
        ])->assertRedirect('/users');
        $this->assertTrue($first->fresh()->hasRole(Permissions::ROLE_ADMIN));

        // Deleting the only remaining super admin through another super admin is impossible
        // (there is none), and a super admin cannot demote themselves.
        $this->actingAs($second)->put("/users/{$second->id}", [
            'name' => $second->name, 'email' => $second->email,
            'role' => Permissions::ROLE_AGENT, 'is_active' => '1',
        ])->assertSessionHasErrors('role');
        $this->assertTrue($second->fresh()->hasRole(Permissions::ROLE_SUPER_ADMIN));
    }

    public function test_email_must_be_unique(): void
    {
        $admin = $this->superAdmin();
        $existing = $this->agent();

        $this->actingAs($admin)->post('/users', [
            'name' => 'Dup',
            'email' => $existing->email,
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'role' => Permissions::ROLE_AGENT,
        ])->assertSessionHasErrors('email');
    }
}
