<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Leads sheet: one row per new number that messages a WhatsApp account (first message only).
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wa_id', 32);
            $table->string('phone', 40);
            $table->string('profile_name', 250)->nullable();
            $table->string('first_message', 500)->nullable();
            $table->string('first_message_type', 30)->nullable();
            $table->timestamp('first_message_at')->index();
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'wa_id']);
        });

        // Backfill: everyone who already messaged before this feature existed.
        $first = DB::table('messages')
            ->selectRaw('contact_id, MIN(id) as first_id')
            ->where('direction', 'inbound')
            ->groupBy('contact_id');

        DB::table('contacts')
            ->joinSub($first, 'f', 'f.contact_id', '=', 'contacts.id')
            ->join('messages', 'messages.id', '=', 'f.first_id')
            ->select('contacts.id', 'contacts.whatsapp_account_id', 'contacts.wa_id', 'contacts.phone', 'contacts.profile_name', 'contacts.name',
                'messages.body', 'messages.type', 'messages.wa_timestamp', 'messages.created_at')
            ->orderBy('contacts.id')
            ->chunk(500, function ($rows) {
                DB::table('leads')->insertOrIgnore($rows->map(fn ($r) => [
                    'whatsapp_account_id' => $r->whatsapp_account_id,
                    'contact_id' => $r->id,
                    'wa_id' => $r->wa_id,
                    'phone' => $r->phone ?: '+'.$r->wa_id,
                    'profile_name' => $r->profile_name ?: $r->name,
                    'first_message' => $r->body !== null ? mb_substr($r->body, 0, 500) : null,
                    'first_message_type' => $r->type,
                    'first_message_at' => $r->wa_timestamp ?? $r->created_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
