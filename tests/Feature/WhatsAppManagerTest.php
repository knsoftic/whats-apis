<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

/** Leads sheet, WhatsApp Manager (health, profile, PIN, block, charges) and template creation. */
class WhatsAppManagerTest extends TestCase
{
    use FakesMeta, RefreshDatabase, SendsWebhooks;

    private WhatsAppAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = WhatsAppAccount::factory()->connected()->create(['name' => 'Shop']);
    }

    private function phone(string $suffix = ''): string
    {
        return $this->graph($this->account->phone_number_id.$suffix);
    }

    private function waba(string $suffix = ''): string
    {
        return $this->graph($this->account->waba_id.$suffix);
    }

    // ------------------------------------------------------------------ leads

    public function test_first_message_of_a_new_number_is_saved_as_a_lead_once_and_can_be_exported(): void
    {
        Http::fake();
        $this->postWebhook($this->account, $this->incomingPayload($this->account, ['id' => 'wamid.L1', 'type' => 'text', 'text' => ['body' => 'Price of plan?']], '923001112233', 'Ali Khan'))->assertOk();
        $this->postWebhook($this->account, $this->incomingPayload($this->account, ['id' => 'wamid.L2', 'type' => 'text', 'text' => ['body' => 'Hello again']], '923001112233', 'Ali Khan'))->assertOk();
        $this->postWebhook($this->account, $this->incomingPayload($this->account, ['id' => 'wamid.L3', 'type' => 'text', 'text' => ['body' => '=HYPERLINK("x")']], '923009998877', 'Sara'))->assertOk();

        $this->assertSame(2, Lead::count());
        $lead = Lead::where('wa_id', '923001112233')->firstOrFail();
        $this->assertSame('Ali Khan', $lead->profile_name);
        $this->assertSame('Price of plan?', $lead->first_message);

        $admin = $this->admin();
        $this->actingAs($admin)->get('/leads')->assertOk()->assertSee('Ali Khan')->assertSee('Price of plan?')->assertDontSee('Hello again');
        $this->actingAs($admin)->get('/leads?q=sara')->assertSee('Sara')->assertDontSee('Ali Khan');

        $csv = $this->actingAs($admin)->get('/leads/export')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"Ali Khan",+923001112233,923001112233,', $csv); // phone kept as-is (no quote mark)
        $this->assertStringContainsString("'=HYPERLINK", $csv); // no spreadsheet formulas

        // Agents can see leads only for numbers they're assigned to, and cannot export.
        $agent = $this->agent();
        $this->actingAs($agent)->get('/leads')->assertOk()->assertDontSee('Ali Khan');
        $this->actingAs($agent)->get('/leads/export')->assertForbidden();
    }

    // ------------------------------------------------------------------ health

    public function test_number_health_is_loaded_from_meta(): void
    {
        Http::fake([
            $this->phone('*fields=messaging_limit_tier*') => Http::response(['messaging_limit_tier' => 'TIER_1K', 'health_status' => ['can_send_message' => 'LIMITED', 'entities' => [['errors' => [['error_code' => 141010, 'error_description' => 'Business is not verified.', 'possible_solution' => 'Verify your business.']]]]]]),
            $this->phone('*') => Http::response(['id' => $this->account->phone_number_id, 'display_phone_number' => '+92 300 0000000', 'verified_name' => 'Shop PK', 'quality_rating' => 'GREEN', 'name_status' => 'APPROVED', 'code_verification_status' => 'VERIFIED', 'platform_type' => 'CLOUD_API', 'throughput' => ['level' => 'STANDARD']]),
            $this->waba('*fields=health_status*') => $this->metaError(100, 'Tried accessing nonexisting field (health_status)'),
            $this->waba('*') => Http::response(['id' => $this->account->waba_id, 'name' => 'Shop WABA', 'currency' => 'USD', 'account_review_status' => 'APPROVED']),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->get("/whatsapp-accounts/{$this->account->id}/manage")->assertOk()->assertSee('Not checked yet');
        $this->actingAs($admin)->post("/whatsapp-accounts/{$this->account->id}/manage/health")->assertRedirect()->assertSessionHas('success');

        $this->actingAs($admin)->get("/whatsapp-accounts/{$this->account->id}/manage/health")
            ->assertSee('High')->assertSee('1,000')->assertSee('Shop PK')->assertSee('Sending is limited.')->assertSee('Business is not verified.')->assertSee('Shop WABA');
        $this->assertSame('GREEN', $this->account->fresh()->quality_rating);
    }

    // ------------------------------------------------------------------ business profile + PIN

    public function test_business_profile_is_shown_and_saved_with_a_new_photo(): void
    {
        $this->account->forceFill(['meta_app_id' => '555000111'])->save();

        Http::fake([
            $this->phone('/whatsapp_business_profile*fields=*') => Http::response(['data' => [['about' => 'We sell shoes', 'email' => 'hi@shop.test', 'websites' => ['https://shop.test'], 'vertical' => 'RETAIL']]]),
            $this->phone('/whatsapp_business_profile*') => Http::response(['success' => true]),
            $this->graph('555000111/uploads*') => Http::response(['id' => 'upload:ABC']),
            $this->graph('upload:ABC*') => Http::response(['h' => 'HANDLE123']),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->get("/whatsapp-accounts/{$this->account->id}/manage/profile")->assertOk()->assertSee('We sell shoes')->assertSee('hi@shop.test');

        $this->actingAs($admin)->put("/whatsapp-accounts/{$this->account->id}/manage/profile", [
            'about' => 'Best shoes in Lahore', 'description' => 'Hand-made', 'email' => 'sales@shop.test', 'website_1' => 'https://shop.test', 'vertical' => 'RETAIL',
            'photo' => UploadedFile::fake()->image('logo.png', 640, 640),
        ])->assertRedirect()->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/whatsapp_business_profile')
            && $r['about'] === 'Best shoes in Lahore' && $r['profile_picture_handle'] === 'HANDLE123' && $r['websites'] === ['https://shop.test'] && $r['messaging_product'] === 'whatsapp');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'upload:ABC') && $r->header('Authorization')[0] === 'OAuth '.$this->account->access_token);
    }

    public function test_two_step_pin_can_be_changed(): void
    {
        Http::fake([$this->phone('*') => Http::response(['success' => true])]);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/whatsapp-accounts/{$this->account->id}/manage/pin", ['pin' => '482915', 'pin_confirmation' => '482916'])->assertSessionHasErrors('pin');
        $this->actingAs($admin)->post("/whatsapp-accounts/{$this->account->id}/manage/pin", ['pin' => '123456', 'pin_confirmation' => '123456'])->assertSessionHasErrors('pin');
        Http::assertNothingSent();

        $this->actingAs($admin)->post("/whatsapp-accounts/{$this->account->id}/manage/pin", ['pin' => '482915', 'pin_confirmation' => '482915'])->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['pin'] === '482915');

        // Agents can't manage numbers.
        $this->actingAs($this->agent())->get("/whatsapp-accounts/{$this->account->id}/manage/security")->assertForbidden();
    }

    // ------------------------------------------------------------------ block / unblock

    public function test_customer_can_be_blocked_and_unblocked_on_whatsapp(): void
    {
        $contact = Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'wa_id' => '923001112233', 'phone' => '+92 300 1112233']);

        Http::fakeSequence($this->phone('/block_users*'))
            ->push(['messaging_product' => 'whatsapp', 'block_users' => ['added_users' => [['input' => '923001112233', 'wa_id' => '923001112233']]]])
            ->push(['messaging_product' => 'whatsapp', 'block_users' => ['removed_users' => [['input' => '923001112233', 'wa_id' => '923001112233']]]])
            ->push(['messaging_product' => 'whatsapp', 'block_users' => ['failed_users' => [['input' => '923001112233', 'errors' => [['message' => 'Failed to block', 'error_data' => ['details' => 'User has not messaged in the last 24 hours']]]]]]]);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson("/contacts/{$contact->id}/block")->assertOk()->assertJson(['blocked' => true]);
        $this->assertNotNull($contact->fresh()->blocked_at);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['block_users'] === [['user' => '923001112233']]);

        $this->actingAs($admin)->get("/whatsapp-accounts/{$this->account->id}/manage/blocked")->assertSee('+92 300 1112233');
        $this->actingAs($admin)->get("/contacts/{$contact->id}")->assertSee('Unblock on WhatsApp');

        $this->actingAs($admin)->delete("/contacts/{$contact->id}/block")->assertSessionHas('success');
        $this->assertNull($contact->fresh()->blocked_at);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/block_users'));

        $this->actingAs($admin)->postJson("/contacts/{$contact->id}/block")->assertStatus(422)->assertJsonFragment(['blocked' => false]);
        $this->assertNull($contact->fresh()->blocked_at);
    }

    // ------------------------------------------------------------------ templates

    public function test_template_is_built_and_submitted_to_meta(): void
    {
        Http::fake([$this->waba('/message_templates*') => Http::response(['id' => '999001', 'status' => 'PENDING', 'category' => 'UTILITY'])]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/templates/create?account_id='.$this->account->id)->assertOk()->assertSee('Submit to Meta for review');

        $this->actingAs($admin)->post('/templates', [
            'whatsapp_account_id' => $this->account->id, 'name' => 'order_update', 'language' => 'en', 'category' => 'UTILITY',
            'header_type' => 'TEXT', 'header_text' => 'Order {{1}}', 'header_example' => '#1042',
            'body' => 'Hi {{1}}, your order will arrive {{2}}. Thank you!', 'body_examples' => [1 => 'Ali', 2 => 'tomorrow'],
            'footer' => 'Shop PK',
            'buttons' => [
                ['type' => 'URL', 'text' => 'Track order', 'url' => 'https://shop.test/track/{{1}}', 'example' => 'https://shop.test/track/1042'],
                ['type' => 'QUICK_REPLY', 'text' => 'Talk to us'],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        Http::assertSent(function (Request $r) {
            $c = collect($r['components'])->keyBy('type');

            return $r['name'] === 'order_update' && $r['category'] === 'UTILITY'
                && $c['HEADER']['example']['header_text'] === ['#1042']
                && $c['BODY']['example']['body_text'] === [['Ali', 'tomorrow']]
                && $c['FOOTER']['text'] === 'Shop PK'
                && $c['BUTTONS']['buttons'][0]['example'] === ['https://shop.test/track/1042']
                && $c['BUTTONS']['buttons'][1] === ['type' => 'QUICK_REPLY', 'text' => 'Talk to us'];
        });

        $template = MessageTemplate::where('name', 'order_update')->firstOrFail();
        $this->assertSame('PENDING', $template->status);
        $this->assertSame('999001', $template->meta_template_id);

        // Delete → Meta + local.
        Http::fake([$this->waba('/message_templates*') => Http::response(['success' => true])]);
        $this->actingAs($admin)->delete("/templates/{$template->id}")->assertRedirect();
        $this->assertModelMissing($template);
    }

    public function test_template_rules_are_checked_before_sending_to_meta(): void
    {
        Http::fake();
        $admin = $this->admin();
        $base = ['whatsapp_account_id' => $this->account->id, 'name' => 'promo', 'language' => 'en', 'category' => 'MARKETING'];

        $this->actingAs($admin)->post('/templates', array_merge($base, ['name' => 'Bad Name', 'body' => 'Hi']))->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/templates', array_merge($base, ['body' => '{{1}} sale today']))->assertSessionHasErrors('body');
        $this->actingAs($admin)->post('/templates', array_merge($base, ['body' => 'Hi {{1}} and {{3}} friends']))->assertSessionHasErrors('body');
        $this->actingAs($admin)->post('/templates', array_merge($base, ['body' => 'Hi {{1}}, sale today!', 'body_examples' => [1 => '']]))->assertSessionHasErrors('body_examples.1');
        $this->actingAs($admin)->post('/templates', array_merge($base, ['body' => 'Sale!', 'buttons' => [['type' => 'URL', 'text' => 'Go', 'url' => 'https://x.test/{{1}}/page']]]))->assertSessionHasErrors('buttons.0.url');
        Http::assertNothingSent();

        // OTP template: Meta's fixed format.
        Http::fake([$this->waba('/message_templates*') => Http::response(['id' => '1', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION'])]);
        $this->actingAs($admin)->post('/templates', array_merge($base, ['name' => 'login_code', 'category' => 'AUTHENTICATION', 'auth_security' => '1', 'auth_expiry' => 5]))->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r['components'][2]['buttons'][0]['otp_type'] === 'COPY_CODE' && $r['components'][1]['code_expiration_minutes'] === 5);
    }

    // ------------------------------------------------------------------ charges

    public function test_meta_charges_are_estimated_from_sent_messages(): void
    {
        $contact = Contact::factory()->create(['whatsapp_account_id' => $this->account->id]);
        $conversation = Conversation::create(['whatsapp_account_id' => $this->account->id, 'contact_id' => $contact->id, 'status' => 'open']);
        $make = fn (array $attrs) => Message::factory()->create(array_merge([
            'whatsapp_account_id' => $this->account->id, 'conversation_id' => $conversation->id, 'contact_id' => $contact->id,
            'direction' => Message::OUT, 'status' => Message::STATUS_DELIVERED, 'type' => 'text', 'created_at' => now(),
        ], $attrs));

        $make(['type' => 'template', 'content' => ['name' => 'promo', 'category' => 'MARKETING']]);
        $make(['type' => 'template', 'content' => ['name' => 'promo', 'category' => 'MARKETING']]);
        $make(['type' => 'template', 'content' => ['name' => 'order', 'category' => 'UTILITY']]);
        $make(['type' => 'text']);
        $make(['type' => 'template', 'status' => Message::STATUS_FAILED, 'content' => ['category' => 'MARKETING']]);

        $admin = $this->admin();
        $this->actingAs($admin)->post("/whatsapp-accounts/{$this->account->id}/manage/rates", ['currency' => 'pkr', 'marketing' => '10', 'utility' => '2.5', 'authentication' => '2'])->assertSessionHas('success');

        $this->actingAs($admin)->get("/whatsapp-accounts/{$this->account->id}/manage/charges")->assertOk()
            ->assertSee('PKR 22.50'); // 2 × 10 + 1 × 2.5; service + failed are free

        Http::fake([$this->waba('*') => Http::response(['pricing_analytics' => ['data' => [['data_points' => [['pricing_category' => 'MARKETING', 'pricing_type' => 'REGULAR', 'volume' => 2, 'cost' => 0.42]]]]]])]);
        $this->actingAs($admin)->get("/whatsapp-accounts/{$this->account->id}/manage/charges?meta=1")->assertSee("Meta's figures")->assertSee('0.42')->assertSee('Marketing');
    }
}
