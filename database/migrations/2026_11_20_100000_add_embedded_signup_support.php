<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide settings (Meta Tech Provider app etc.). Values are encrypted by App\Models\Setting.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            // manual | embedded_signup
            $table->string('onboarding_method', 30)->default('manual')->after('is_active');
            // registered | failed | coexistence | not_required | null (unknown / manual)
            $table->string('registration_status', 20)->nullable()->after('onboarding_method');
            $table->text('registration_message')->nullable()->after('registration_status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['onboarding_method', 'registration_status', 'registration_message']);
        });

        Schema::dropIfExists('settings');
    }
};
