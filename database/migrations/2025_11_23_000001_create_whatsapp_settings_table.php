<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One AyosPush connection per application. The Meta side (WABA, phone
     * numbers, access tokens) lives in AyosPush; only the API credentials and a
     * snapshot of what they give access to are kept here.
     */
    public function up(): void
    {
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('businesses')->cascadeOnDelete();
            $table->string('provider', 32)->default('ayospush');

            $table->string('api_key');
            $table->text('api_secret'); // encrypted cast
            $table->string('api_secret_hint', 8)->nullable();

            // Internal AyosPush WABA id (the one /facebook-config returns as `id`)
            // and the Meta phone number id used as sender.
            $table->unsignedBigInteger('default_waba_account_id')->nullable();
            $table->string('default_phone_number_id', 64)->nullable();

            // Snapshot taken by the last successful connection test.
            $table->json('scopes')->nullable();
            $table->json('waba_accounts')->nullable();
            $table->json('phone_numbers')->nullable();
            $table->string('meta_business_id')->nullable();
            $table->string('connection_status')->nullable();

            $table->enum('test_status', ['not_tested', 'success', 'failed'])->default('not_tested');
            $table->text('test_error')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('templates_synced_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_settings');
    }
};
