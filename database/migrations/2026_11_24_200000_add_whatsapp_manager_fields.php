<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meta App ID of the account's app — needed for uploads (profile photo, template media samples).
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->string('meta_app_id', 32)->nullable()->after('meta_business_id');
        });

        // Customer blocked on WhatsApp (Block Users API).
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', fn (Blueprint $t) => $t->dropColumn('meta_app_id'));
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('blocked_at'));
    }
};
