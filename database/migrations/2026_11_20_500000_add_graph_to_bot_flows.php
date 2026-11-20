<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Visual (node + connection) bot flows. NULL = older linear "steps" flow.
        Schema::table('bot_flows', function (Blueprint $table) {
            $table->json('graph')->nullable()->after('steps');
        });
    }

    public function down(): void
    {
        Schema::table('bot_flows', fn (Blueprint $table) => $table->dropColumn('graph'));
    }
};
