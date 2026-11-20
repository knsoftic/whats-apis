<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('plan_name', 80)->nullable();          // snapshot
            $table->string('gateway', 20);                         // pkpay | manual | free
            $table->string('reference', 64)->unique();             // our order id (sent as order_id)
            $table->string('gateway_payment_id', 100)->nullable()->index(); // pay_…
            $table->string('gateway_transaction_id', 100)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending');     // pending | paid | failed | expired | cancelled | rejected
            $table->text('checkout_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('customer_note')->nullable();             // manual payment reference
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();                      // gateway responses / callbacks (no secrets)
            $table->timestamps();

            $table->index(['status', 'gateway']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
