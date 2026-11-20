<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bot memory per chat: answers saved by Question nodes + the question it is waiting on.
        Schema::table('conversations', function (Blueprint $table) {
            $table->json('bot_state')->nullable()->after('bot_paused_until');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('bot_state'));
    }
};
