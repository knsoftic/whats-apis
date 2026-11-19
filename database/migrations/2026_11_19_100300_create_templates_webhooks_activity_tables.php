<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->string('meta_template_id')->nullable();
            $table->string('name');
            $table->string('language', 20);
            $table->string('category', 30)->nullable();       // MARKETING | UTILITY | AUTHENTICATION
            $table->string('status', 30)->nullable();         // APPROVED | PENDING | REJECTED | PAUSED | DISABLED
            $table->string('parameter_format', 20)->default('POSITIONAL'); // POSITIONAL | NAMED
            $table->json('components')->nullable();
            $table->string('rejected_reason')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'name', 'language']);
            $table->index(['whatsapp_account_id', 'status']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->char('payload_hash', 64);                 // sha256 of the raw body (duplicate guard)
            $table->string('event_type', 30)->nullable();     // messages | statuses | mixed | other
            $table->longText('payload');
            $table->string('status', 20)->default('received'); // received | processing | processed | failed | duplicate
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'payload_hash']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained('whatsapp_accounts')->nullOnDelete();
            $table->string('action', 60);
            $table->string('description');
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('message_templates');
    }
};
