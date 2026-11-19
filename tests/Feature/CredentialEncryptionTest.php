<?php

namespace Tests\Feature;

use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CredentialEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_secrets_are_encrypted_at_rest(): void
    {
        $account = WhatsAppAccount::factory()->create([
            'access_token' => 'EAAG-plain-access-token-value-1234567890',
            'app_secret' => 'plainappsecret0123456789abcdef00',
            'webhook_verify_token' => 'plain-verify-token',
        ]);

        $raw = DB::table('whatsapp_accounts')->where('id', $account->id)->first();

        foreach (['access_token', 'app_secret', 'webhook_verify_token'] as $column) {
            $this->assertStringNotContainsString('plain', $raw->{$column}, "{$column} is stored in plaintext");
        }

        $this->assertSame('EAAG-plain-access-token-value-1234567890', Crypt::decryptString($raw->access_token));
        $this->assertSame('plainappsecret0123456789abcdef00', Crypt::decryptString($raw->app_secret));
        $this->assertSame('plain-verify-token', Crypt::decryptString($raw->webhook_verify_token));

        // Model transparently decrypts.
        $this->assertSame('EAAG-plain-access-token-value-1234567890', $account->fresh()->access_token);
    }

    public function test_secrets_are_hidden_from_serialization(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $array = $account->toArray();
        $json = $account->toJson();

        foreach (['access_token', 'app_secret', 'webhook_verify_token'] as $column) {
            $this->assertArrayNotHasKey($column, $array);
        }

        $this->assertStringNotContainsString($account->access_token, $json);
        $this->assertStringNotContainsString($account->app_secret, $json);
    }

    public function test_masking_never_reveals_more_than_eight_characters(): void
    {
        $token = 'EAAG'.str_repeat('a', 200).'WXYZ';

        $masked = WhatsAppAccount::mask($token);

        $this->assertSame('EAAG••••••••••••WXYZ', $masked);
        $this->assertSame('••••••••', WhatsAppAccount::mask('short'));
    }
}
