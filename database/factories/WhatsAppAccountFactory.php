<?php

namespace Database\Factories;

use App\Models\WhatsAppAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppAccount>
 */
class WhatsAppAccountFactory extends Factory
{
    protected $model = WhatsAppAccount::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Support',
            'meta_business_id' => (string) fake()->numerify('1###############'),
            'waba_id' => (string) fake()->unique()->numerify('2###############'),
            'phone_number_id' => (string) fake()->unique()->numerify('1##############'),
            'graph_api_version' => 'v26.0',
            'access_token' => 'EAAG'.Str::random(180),
            'webhook_verify_token' => Str::random(32),
            'app_secret' => Str::lower(Str::random(32)),
            'is_active' => true,
        ];
    }

    public function connected(): static
    {
        // Factories run unguarded, so non-fillable status columns can be set here.
        return $this->state(fn (array $attributes) => [
            'api_status' => WhatsAppAccount::API_CONNECTED,
            'api_checked_at' => now(),
            'display_phone_number' => '+1 555-0'.fake()->numerify('###'),
            'verified_name' => $attributes['name'] ?? 'Test Business',
            'quality_rating' => 'GREEN',
            'webhook_status' => WhatsAppAccount::WEBHOOK_VERIFIED,
            'webhook_verified_at' => now(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
