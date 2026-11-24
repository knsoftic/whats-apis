<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Automatic follow-up before the 24-hour customer service window closes (per WhatsApp number).
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->boolean('followup_enabled')->default(false);
            $table->unsignedSmallInteger('followup_after_minutes')->default(1380); // 23 h
            $table->text('followup_message')->nullable();
            $table->boolean('followup_skip_closed')->default(true);
        });

        // One follow-up per customer message window.
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('followup_sent_at')->nullable();
            $table->timestamp('followup_for_inbound_at')->nullable();
            $table->index(['whatsapp_account_id', 'last_inbound_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_account_id', 'last_inbound_at']);
            $table->dropColumn(['followup_sent_at', 'followup_for_inbound_at']);
        });
        Schema::table('whatsapp_accounts', fn (Blueprint $t) => $t->dropColumn(['followup_enabled', 'followup_after_minutes', 'followup_message', 'followup_skip_closed']));
    }
};
