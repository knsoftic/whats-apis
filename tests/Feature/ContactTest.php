<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppAccount $account;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = WhatsAppAccount::factory()->create();
        $this->agent = User::factory()->agent()->create();
        $this->agent->whatsappAccounts()->attach($this->account);
    }

    public function test_contact_can_be_added_with_tags_and_consent(): void
    {
        $this->actingAs($this->agent)->post('/contacts', [
            'whatsapp_account_id' => $this->account->id,
            'phone' => '+91 98765-43210',
            'name' => 'Ravi Kumar',
            'email' => 'Ravi@Example.com',
            'tags' => 'VIP, delhi, vip',
            'opt_in_status' => 'opted_in',
            'opt_in_source' => 'website',
        ])->assertRedirect();

        $contact = Contact::firstOrFail();
        $this->assertSame('919876543210', $contact->wa_id);
        $this->assertSame('+919876543210', $contact->phone);
        $this->assertSame('ravi@example.com', $contact->email);
        $this->assertSame('opted_in', $contact->opt_in_status);
        $this->assertSame('website', $contact->opt_in_source);
        $this->assertNotNull($contact->opted_in_at);
        $this->assertEqualsCanonicalizing(['VIP', 'delhi'], $contact->tags()->pluck('name')->all());
    }

    public function test_duplicate_number_in_same_account_is_rejected_but_allowed_in_another(): void
    {
        Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'wa_id' => '919876543210']);

        $this->actingAs($this->agent)->post('/contacts', [
            'whatsapp_account_id' => $this->account->id, 'phone' => '+919876543210', 'opt_in_status' => 'unknown',
        ])->assertSessionHasErrors('wa_id');

        $admin = $this->admin();
        $other = WhatsAppAccount::factory()->create();
        $this->actingAs($admin)->post('/contacts', [
            'whatsapp_account_id' => $other->id, 'phone' => '+919876543210', 'opt_in_status' => 'unknown',
        ])->assertSessionHasNoErrors();
    }

    public function test_agent_cannot_add_contact_to_unassigned_account(): void
    {
        $other = WhatsAppAccount::factory()->create();

        $this->actingAs($this->agent)->post('/contacts', [
            'whatsapp_account_id' => $other->id, 'phone' => '+919876543210', 'opt_in_status' => 'unknown',
        ])->assertSessionHasErrors('whatsapp_account_id');

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_contact_list_search_and_history(): void
    {
        $contact = Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Meera Patel']);
        Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Someone Else']);
        $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'body' => 'History message 123']);

        $this->actingAs($this->agent)->get('/contacts?q=meera')
            ->assertOk()->assertSee('Meera Patel')->assertDontSee('Someone Else');

        $this->actingAs($this->agent)->get("/contacts/{$contact->id}")
            ->assertOk()->assertSee('History message 123');
    }

    public function test_internal_notes_can_be_added_and_deleted(): void
    {
        $contact = Contact::factory()->create(['whatsapp_account_id' => $this->account->id]);

        $this->actingAs($this->agent)->post("/contacts/{$contact->id}/notes", ['body' => 'Prefers calls after 6 PM']);
        $note = $contact->notes()->firstOrFail();
        $this->assertSame($this->agent->id, $note->user_id);

        $this->actingAs($this->agent)->delete("/contacts/{$contact->id}/notes/{$note->id}");
        $this->assertModelMissing($note);
    }

    public function test_csv_import_creates_updates_and_reports_errors(): void
    {
        $admin = $this->admin();
        Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'wa_id' => '919811111111', 'name' => 'Old Name']);

        $csv = "\xEF\xBB\xBFPhone,Name,Email,Tags,Opt_In\n"
            ."+91 98111 11111,New Name,new@example.com,vip;loyal,yes\n"
            ."+15551234567,Jane Doe,,lead,\n"
            ."12,Bad Number,,,\n"
            ."+447700900123,Bad Email,not-an-email,,no\n";

        $this->actingAs($admin)->post('/contacts/import', [
            'whatsapp_account_id' => $this->account->id,
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
        ])->assertRedirect('/contacts/import')->assertSessionHas('importResult', fn ($r) => $r['created'] === 2 && $r['updated'] === 1 && $r['skipped'] === 1);

        $updated = Contact::where('wa_id', '919811111111')->firstOrFail();
        $this->assertSame('New Name', $updated->name);
        $this->assertSame('opted_in', $updated->opt_in_status);
        $this->assertSame('csv_import', $updated->opt_in_source);
        $this->assertEqualsCanonicalizing(['vip', 'loyal'], $updated->tags()->pluck('name')->all());

        $this->assertNull(Contact::where('wa_id', '447700900123')->value('email'));
        $this->assertSame('opted_out', Contact::where('wa_id', '447700900123')->value('opt_in_status'));
    }

    public function test_csv_without_phone_header_is_rejected(): void
    {
        $this->actingAs($this->admin())->post('/contacts/import', [
            'whatsapp_account_id' => $this->account->id,
            'file' => UploadedFile::fake()->createWithContent('c.csv', "name,email\nRavi,r@example.com\n"),
        ])->assertSessionHas('importResult', fn ($r) => str_contains($r['errors'][0], '"phone" column'));

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_agent_cannot_import_or_export(): void
    {
        $this->actingAs($this->agent)->get('/contacts/export')->assertForbidden();
        $this->actingAs($this->agent)->get('/contacts/import')->assertForbidden();
    }

    public function test_export_is_scoped_and_protects_against_formula_injection(): void
    {
        Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => '=HYPERLINK("http://evil")', 'wa_id' => '919800000001']);
        $other = WhatsAppAccount::factory()->create();
        Contact::factory()->create(['whatsapp_account_id' => $other->id, 'name' => 'Hidden Other Account']);

        $admin = $this->admin();
        $csv = $this->actingAs($admin)->get("/contacts/export?account_id={$this->account->id}")->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Hidden Other Account', $csv);
        $this->assertStringContainsString('919800000001', $csv);
    }
}
