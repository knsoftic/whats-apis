<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('open'); // open | closed
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('last_message_preview')->nullable();
            $table->string('last_message_direction', 10)->nullable();
            $table->timestamp('last_message_at')->nullable();
            // Start of the 24-hour customer service window (last message FROM the customer).
            $table->timestamp('last_inbound_at')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'contact_id']);
            $table->index(['whatsapp_account_id', 'last_message_at']);
            $table->index(['whatsapp_account_id', 'unread_count']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            // "wamid.xxx" from Meta. NULL until Meta accepts an outgoing message.
            $table->string('wa_message_id')->nullable();
            $table->string('direction', 10);          // inbound | outbound
            $table->string('type', 20);               // text | image | video | audio | document | sticker | location | contacts | interactive | button | reaction | template | unsupported
            $table->string('sender', 32);             // wa_id / phone of the sender
            $table->string('recipient', 32);          // wa_id / phone of the recipient
            $table->text('body')->nullable();         // text or caption
            $table->json('content')->nullable();      // structured payload (location, contacts, interactive, template…)

            // Replies
            $table->string('context_wa_message_id')->nullable();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('messages')->nullOnDelete();

            // Delivery status: pending | sent | delivered | read | failed (outbound) — received | read (inbound)
            $table->string('status', 20)->default('pending');
            $table->string('error_code', 20)->nullable();
            $table->text('error_message')->nullable();
            $table->json('api_response')->nullable();

            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('wa_timestamp')->nullable(); // timestamp given by Meta
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'wa_message_id']);
            $table->index(['conversation_id', 'id']);
            $table->index(['whatsapp_account_id', 'direction', 'status']);
            $table->index(['whatsapp_account_id', 'created_at']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('wa_media_id')->nullable();
            $table->string('type', 20);                 // image | video | audio | document | sticker
            $table->string('mime_type', 150)->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256', 128)->nullable();
            $table->string('disk', 30)->default('local');
            $table->string('path')->nullable();          // private storage path
            $table->string('download_status', 20)->default('pending'); // pending | downloaded | failed | not_required
            $table->text('download_error')->nullable();
            $table->timestamps();

            $table->index('wa_media_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
