<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\WhatsAppAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $waId = '91'.fake()->unique()->numerify('9#########');

        return [
            'whatsapp_account_id' => WhatsAppAccount::factory(),
            'wa_id' => $waId,
            'phone' => '+'.$waId,
            'name' => fake()->name(),
            'profile_name' => fake()->firstName(),
            'opt_in_status' => Contact::OPT_UNKNOWN,
        ];
    }
}
