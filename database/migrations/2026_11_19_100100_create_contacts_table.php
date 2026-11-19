<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            // WhatsApp ID as sent by Meta (digits only, e.g. 919876543210).
            $table->string('wa_id', 32);
            $table->string('phone', 32);              // E.164 for display, e.g. +919876543210
            $table->string('name')->nullable();       // name set by staff
            $table->string('profile_name')->nullable(); // WhatsApp profile name from webhooks
            $table->string('email')->nullable();

            // Consent / opt-in tracking
            $table->string('opt_in_status', 20)->default('unknown'); // unknown | opted_in | opted_out
            $table->string('opt_in_source', 50)->nullable();         // whatsapp_inbound | manual | csv_import | website | other
            $table->timestamp('opted_in_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();

            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'wa_id']);
            $table->index(['whatsapp_account_id', 'name']);
            $table->index(['whatsapp_account_id', 'phone']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 20)->default('emerald');
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'name']);
        });

        Schema::create('contact_tag', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['contact_id', 'tag_id']);
        });

        Schema::create('contact_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_notes');
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('contacts');
    }
};
