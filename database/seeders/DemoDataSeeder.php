<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\WhatsAppAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * OPTIONAL sample data for trying the UI locally:
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Creates one clearly-labelled DEMO account with fake credentials (it can
 * never send real messages). Delete the "Demo Account" on the WhatsApp
 * Accounts page to remove all demo data.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Demo data is not allowed in production.');

            return;
        }

        $account = WhatsAppAccount::firstOrCreate(
            ['phone_number_id' => '100000000000999'],
            [
                'name' => 'Demo Account (sample data)',
                'waba_id' => '200000000000999',
                'access_token' => 'DEMO-'.Str::random(60),
                'webhook_verify_token' => Str::random(32),
                'app_secret' => Str::lower(Str::random(32)),
                'graph_api_version' => config('whatsapp.default_graph_version'),
            ],
        );
        $account->forceFill(['display_phone_number' => '+1 555-010-0999', 'verified_name' => 'KN Softic Demo'])->save();

        $people = [
            ['Ravi Kumar', '919876500001', 2, [
                ['in', 'Hello! Is the WhatsApp API plan still available?', 50],
                ['out', 'Hi Ravi 👋 Yes, the Business plan is available. Shall I share the price list?', 48],
                ['in', 'Yes please, also the setup time.', 47],
                ['out', 'Setup takes about *2 working days*. Price list: https://example.com/pricing', 46],
                ['in', 'Perfect, thank you!', 20],
                ['in', 'Can we schedule a call tomorrow?', 3],
            ]],
            ['Ayesha Khan', '919876500002', 0, [
                ['in', 'Order #4412 has not arrived yet.', 300],
                ['out', 'Sorry for the delay Ayesha. It is out for delivery today.', 290],
                ['in', 'Received, thanks 🙏', 120],
            ]],
            ['Daniel Lopez', '15550100003', 1, [
                ['in', 'Do you support multiple numbers on one dashboard?', 1500],
            ]],
            ['Meera Patel', '919876500004', 0, [
                ['out', 'Hi Meera, your appointment is confirmed for Monday 10 AM.', 3000],
            ]],
        ];

        foreach ($people as [$name, $waId, $unread, $lines]) {
            $contact = Contact::firstOrCreate(
                ['whatsapp_account_id' => $account->id, 'wa_id' => $waId],
                ['phone' => '+'.$waId, 'name' => $name, 'profile_name' => explode(' ', $name)[0]],
            );
            $conversation = Conversation::firstOrCreate(['whatsapp_account_id' => $account->id, 'contact_id' => $contact->id]);

            if ($conversation->messages()->exists()) {
                continue;
            }

            $lastIn = null;

            foreach ($lines as $i => [$dir, $text, $minutesAgo]) {
                $at = now()->subMinutes($minutesAgo);
                Message::create([
                    'whatsapp_account_id' => $account->id,
                    'conversation_id' => $conversation->id,
                    'contact_id' => $contact->id,
                    'wa_message_id' => 'wamid.DEMO'.Str::random(20),
                    'direction' => $dir === 'in' ? Message::IN : Message::OUT,
                    'type' => 'text',
                    'sender' => $dir === 'in' ? $waId : '15550100999',
                    'recipient' => $dir === 'in' ? '15550100999' : $waId,
                    'body' => $text,
                    'status' => $dir === 'in' ? Message::STATUS_RECEIVED : ($i % 2 ? Message::STATUS_READ : Message::STATUS_DELIVERED),
                    'wa_timestamp' => $at,
                    'created_at' => $at,
                ]);
                $lastIn = $dir === 'in' ? $at : $lastIn;
            }

            [$dir, $text, $minutesAgo] = end($lines);
            $conversation->forceFill([
                'unread_count' => $unread,
                'last_message_preview' => Str::limit($text, 120),
                'last_message_direction' => $dir === 'in' ? Message::IN : Message::OUT,
                'last_message_at' => now()->subMinutes($minutesAgo),
                'last_inbound_at' => $lastIn,
            ])->save();
        }

        MessageTemplate::firstOrCreate(
            ['whatsapp_account_id' => $account->id, 'name' => 'order_update', 'language' => 'en_US'],
            [
                'category' => 'UTILITY', 'status' => 'APPROVED', 'parameter_format' => 'POSITIONAL', 'synced_at' => now(),
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order {{1}}'],
                    ['type' => 'BODY', 'text' => 'Hi {{1}}, your order {{2}} has been shipped and will arrive on {{3}}.'],
                    ['type' => 'FOOTER', 'text' => 'KN Softic'],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Track order', 'url' => 'https://example.com/track/{{1}}'], ['type' => 'QUICK_REPLY', 'text' => 'Talk to support']]],
                ],
            ],
        );

        $this->command?->info('Demo data created on "Demo Account (sample data)". Delete that account to remove it.');
    }
}
