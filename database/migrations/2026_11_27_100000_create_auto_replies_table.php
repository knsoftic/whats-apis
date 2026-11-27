<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto Replies (always reply / auto reply / quick action buttons): rules decided
 * inside this app from the chat history — separate from Bot Flows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('trigger', 20);                       // first_message | returning | keyword | always
            $table->string('match_type', 20)->default('contains');
            $table->string('keywords', 1000)->nullable();
            $table->unsignedSmallInteger('gap_hours')->default(0); // returning: only after N quiet hours (0 = any)
            $table->json('message_types')->nullable();            // empty = any type
            $table->boolean('while_chatting')->default(false);    // also while an agent is chatting
            $table->string('hours_mode', 10)->default('always');  // always | inside | outside
            $table->json('days')->nullable();
            $table->string('from_time', 5)->nullable();
            $table->string('to_time', 5)->nullable();
            $table->unsignedInteger('cooldown_minutes')->default(0);
            $table->text('reply_text');
            $table->json('buttons')->nullable();                  // [{id, title, reply}] max 3
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('runs_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_account_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_replies');
    }
};
