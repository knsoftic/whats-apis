<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'whatsapp_account_id' => fn (array $a) => Conversation::find($a['conversation_id'])->whatsapp_account_id,
            'contact_id' => fn (array $a) => Conversation::find($a['conversation_id'])->contact_id,
            'wa_message_id' => 'wamid.'.Str::random(40),
            'direction' => Message::IN,
            'type' => 'text',
            'sender' => fn (array $a) => Conversation::find($a['conversation_id'])->contact->wa_id,
            'recipient' => '15550001111',
            'body' => fake()->sentence(),
            'status' => Message::STATUS_RECEIVED,
            'wa_timestamp' => now(),
        ];
    }

    public function outbound(): static
    {
        return $this->state(fn () => [
            'direction' => Message::OUT,
            'sender' => '15550001111',
            // Lazy: evaluated after conversation_id has been resolved.
            'recipient' => fn (array $a) => Conversation::find($a['conversation_id'])->contact->wa_id,
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->outbound()->state(fn () => [
            'status' => Message::STATUS_FAILED,
            'failed_at' => now(),
            'error_code' => '131047',
            'error_message' => 'Re-engagement message',
        ]);
    }
}
