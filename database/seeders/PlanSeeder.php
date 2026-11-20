<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Starter plans — created only when the plans table is empty.
 * Edit prices and limits on the Plans page (Super Admin).
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        if (Plan::query()->exists()) {
            return;
        }

        Plan::create([
            'name' => 'Free', 'slug' => 'free', 'description' => 'Try the platform with one number.',
            'price' => 0, 'currency' => 'INR', 'billing_period' => 'free', 'trial_days' => null,
            'allow_manual' => true, 'allow_embedded_signup' => true,
            'max_whatsapp_accounts' => 1, 'daily_message_limit' => 50, 'max_contacts' => 500,
            'allow_bot_flows' => false, 'max_bot_flows' => 0,
            'is_active' => true, 'is_default' => true, 'sort_order' => 1,
        ]);

        Plan::create([
            'name' => 'Starter', 'slug' => 'starter', 'description' => 'Small teams with bots.',
            'price' => 999, 'currency' => 'INR', 'billing_period' => 'monthly', 'trial_days' => 7,
            'allow_manual' => true, 'allow_embedded_signup' => true,
            'max_whatsapp_accounts' => 3, 'daily_message_limit' => 1000, 'max_contacts' => 10000,
            'allow_bot_flows' => true, 'max_bot_flows' => 10,
            'is_active' => true, 'is_default' => false, 'sort_order' => 2,
        ]);

        Plan::create([
            'name' => 'Business', 'slug' => 'business', 'description' => 'Growing businesses, unlimited bots.',
            'price' => 2999, 'currency' => 'INR', 'billing_period' => 'monthly', 'trial_days' => 7,
            'allow_manual' => true, 'allow_embedded_signup' => true,
            'max_whatsapp_accounts' => 10, 'daily_message_limit' => 10000, 'max_contacts' => null,
            'allow_bot_flows' => true, 'max_bot_flows' => null,
            'is_active' => true, 'is_default' => false, 'sort_order' => 3,
        ]);
    }
}
