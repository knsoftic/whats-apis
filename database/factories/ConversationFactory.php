<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'whatsapp_account_id' => fn (array $attributes) => Contact::find($attributes['contact_id'])->whatsapp_account_id,
            'status' => Conversation::STATUS_OPEN,
            'unread_count' => 0,
            'last_message_at' => now(),
        ];
    }

    /** Customer wrote recently — free-form replies allowed. */
    public function withinWindow(): static
    {
        return $this->state(fn () => ['last_inbound_at' => now()->subHour()]);
    }

    /** Customer's last message is older than 24h — template required. */
    public function windowExpired(): static
    {
        return $this->state(fn () => ['last_inbound_at' => now()->subHours(30)]);
    }
}
