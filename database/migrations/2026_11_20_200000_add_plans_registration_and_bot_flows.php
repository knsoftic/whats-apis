<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('billing_period', 20)->default('monthly'); // free | monthly | yearly | lifetime
            $table->unsignedSmallInteger('trial_days')->nullable();

            // What the plan allows (NULL limit = unlimited)
            $table->boolean('allow_manual')->default(true);
            $table->boolean('allow_embedded_signup')->default(true);
            $table->unsignedInteger('max_whatsapp_accounts')->nullable();
            $table->unsignedInteger('daily_message_limit')->nullable();
            $table->unsignedInteger('max_contacts')->nullable();
            $table->boolean('allow_bot_flows')->default(false);
            $table->unsignedInteger('max_bot_flows')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // given to new registrations
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('company_name', 150)->nullable()->after('name');
            $table->string('phone', 32)->nullable()->after('email');
            $table->foreignId('plan_id')->nullable()->after('is_active')->constrained('plans')->nullOnDelete();
            $table->timestamp('plan_expires_at')->nullable()->after('plan_id');
        });

        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            // The customer whose plan limits apply (NULL = platform-owned, unlimited).
            $table->foreignId('owner_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('bot_paused_until')->nullable()->after('assigned_user_id');
        });

        Schema::create('bot_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('trigger_type', 20);                 // keyword | welcome | fallback | menu
            $table->string('match_type', 20)->default('contains'); // exact | contains | starts_with
            $table->text('keywords')->nullable();               // comma separated
            $table->integer('priority')->default(0);
            $table->unsignedInteger('cooldown_minutes')->default(0);
            $table->json('steps');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('runs_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_account_id', 'is_active', 'trigger_type']);
        });

        Schema::create('bot_flow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_flow_id')->constrained('bot_flows')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('trigger_message_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['bot_flow_id', 'conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_flow_runs');
        Schema::dropIfExists('bot_flows');

        Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('bot_paused_until'));
        Schema::table('whatsapp_accounts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('owner_id');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('plan_id');
            $t->dropColumn(['company_name', 'phone', 'plan_expires_at']);
        });

        Schema::dropIfExists('plans');
    }
};
