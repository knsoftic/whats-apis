<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            // Public, non-guessable identifier used in the webhook URL.
            $table->uuid('uuid')->unique();
            $table->string('name');

            // Meta identifiers
            $table->string('meta_business_id')->nullable();
            $table->string('waba_id');
            $table->string('phone_number_id')->unique();
            $table->string('graph_api_version', 10)->default('v26.0');

            // Secrets — encrypted at rest through Eloquent "encrypted" casts.
            $table->text('access_token');
            $table->text('webhook_verify_token');
            $table->text('app_secret');

            // Phone number details fetched from Meta (never typed by hand).
            $table->string('display_phone_number')->nullable();
            $table->string('verified_name')->nullable();
            $table->string('quality_rating')->nullable();
            $table->json('phone_meta')->nullable();

            $table->boolean('is_active')->default(true)->index();

            // Separate health statuses — saving credentials is NOT proof of a working number.
            $table->string('api_status', 20)->default('unknown');          // unknown | connected | failed
            $table->text('api_status_message')->nullable();
            $table->timestamp('api_checked_at')->nullable();

            $table->string('webhook_status', 20)->default('not_verified'); // not_verified | verified
            $table->timestamp('webhook_verified_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();

            $table->string('subscription_status', 20)->default('unknown'); // unknown | subscribed | not_subscribed | failed
            $table->text('subscription_status_message')->nullable();
            $table->timestamp('subscription_checked_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('user_whatsapp_account', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'whatsapp_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_whatsapp_account');
        Schema::dropIfExists('whatsapp_accounts');
    }
};
