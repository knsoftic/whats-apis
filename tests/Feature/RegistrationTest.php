<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
        $this->seed(PlanSeeder::class);
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Asha Verma',
            'company_name' => 'Verma Traders',
            'email' => 'Asha@Example.com',
            'phone' => '+91 90000 12345',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'terms' => '1',
        ], $overrides);
    }

    public function test_business_can_register_and_gets_customer_role_and_default_plan(): void
    {
        $default = Plan::where('is_default', true)->firstOrFail();
        $default->update(['trial_days' => 14]);

        $this->get('/register')->assertOk()->assertSee('Create your account')->assertSee($default->name);

        $this->post('/register', $this->form())->assertRedirect('/dashboard');

        $user = User::where('email', 'asha@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole(Permissions::ROLE_CUSTOMER));
        $this->assertSame($default->id, $user->plan_id);
        $this->assertSame('Verma Traders', $user->company_name);
        $this->assertTrue($user->plan_expires_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertTrue($user->isPlanLimited());
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.registered']);

        // Customer lands on a working dashboard with the plan card.
        $this->get('/dashboard')->assertOk()->assertSee('Your plan');
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->post('/register', $this->form(['terms' => null]))->assertSessionHasErrors('terms');
        $this->assertGuest();
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'asha@example.com']);

        $this->post('/register', $this->form())->assertSessionHasErrors('email');
    }

    public function test_customer_cannot_open_staff_pages(): void
    {
        $this->post('/register', $this->form());

        $this->get('/users')->assertForbidden();
        $this->get('/plans')->assertForbidden();
        $this->get('/settings/meta')->assertForbidden();
        $this->get('/activity-logs')->assertForbidden();
        $this->get('/whatsapp-accounts')->assertOk(); // own numbers only
        $this->get('/bot-flows')->assertOk();
    }
}
