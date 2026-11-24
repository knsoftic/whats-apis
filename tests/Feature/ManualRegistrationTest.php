<?php

namespace Tests\Feature;

use App\Actions\WhatsApp\CheckAccountConnection;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

/**
 * Manually added numbers: Meta registers numbers on the Cloud API only through the
 * API (POST /{phone-id}/register), so the account page offers "Register number"
 * whenever Meta reports the number is not on the Cloud API yet.
 */
class ManualRegistrationTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    private function fakePhone(WhatsAppAccount $account, string $platform, bool $registerOk = true): void
    {
        Http::fake([
            $this->graph("{$account->phone_number_id}/register*") => $registerOk
                ? Http::response(['success' => true])
                : $this->metaError(133016, 'Too many registration attempts'),
            $this->graph("{$account->waba_id}/phone_numbers*") => Http::response(['data' => [['id' => $account->phone_number_id]]]),
            $this->graph("{$account->waba_id}/subscribed_apps*") => Http::response(['data' => [['whatsapp_business_api_data' => ['id' => '1', 'name' => 'App']]]]),
            $this->graph("{$account->phone_number_id}*") => Http::response([
                'id' => $account->phone_number_id, 'display_phone_number' => '+92 300 1234567', 'verified_name' => 'Shop',
                'code_verification_status' => 'VERIFIED', 'platform_type' => $platform, 'name_status' => 'APPROVED',
            ]),
        ]);
    }

    public function test_unregistered_manual_number_gets_a_warning_and_a_register_button(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $this->fakePhone($account, 'NOT_APPLICABLE');

        $result = app(CheckAccountConnection::class)->run($account);
        $this->assertTrue($result->ok);
        $this->assertStringContainsString('not registered on the Cloud API yet', $account->fresh()->api_status_message);

        $this->actingAs($this->admin())->get("/whatsapp-accounts/{$account->id}")->assertOk()
            ->assertSee('Manual')->assertSee('not registered')->assertSee('Register number')
            ->assertSee('Max 10 tries per 72 hours');
    }

    public function test_manual_number_can_be_registered_from_the_account_page(): void
    {
        $account = WhatsAppAccount::factory()->create(['phone_meta' => ['platform_type' => 'NOT_APPLICABLE']]);
        $this->fakePhone($account, 'NOT_APPLICABLE');

        $this->actingAs($this->admin())->post("/whatsapp-accounts/{$account->id}/register", ['pin' => '123456'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('registered', $account->fresh()->registration_status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), "{$account->phone_number_id}/register") && $r['pin'] === '123456' && $r['messaging_product'] === 'whatsapp');
        $this->actingAs($this->admin())->get("/whatsapp-accounts/{$account->id}")->assertDontSee('Register number');
    }

    public function test_registered_manual_number_shows_no_registration_card(): void
    {
        $account = WhatsAppAccount::factory()->create(['phone_meta' => ['platform_type' => 'CLOUD_API']]);
        $this->actingAs($this->admin())->get("/whatsapp-accounts/{$account->id}")->assertOk()->assertDontSee('Register number')->assertDontSee('Cloud API registration');

        $legacy = WhatsAppAccount::factory()->create(['phone_meta' => null]); // never checked yet
        $this->actingAs($this->admin())->get("/whatsapp-accounts/{$legacy->id}")->assertOk()->assertDontSee('Register number');
    }

    public function test_meta_register_error_is_shown(): void
    {
        $account = WhatsAppAccount::factory()->create(['phone_meta' => ['platform_type' => 'NOT_APPLICABLE']]);
        $this->fakePhone($account, 'NOT_APPLICABLE', registerOk: false);

        $this->actingAs($this->admin())->post("/whatsapp-accounts/{$account->id}/register", ['pin' => '654321'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame('failed', $account->fresh()->registration_status);
        $this->actingAs($this->admin())->get("/whatsapp-accounts/{$account->id}")->assertSee('Register number');
    }
}
