<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            
            // WhatsApp Business Account
            $table->string('waba_account_id')->nullable()->index(); // WhatsApp Business Account ID
            
            // Phone number identification
            $table->string('phone_number_id')->unique()->index(); // Meta/Facebook phone number ID
            $table->string('phone_number')->index(); // Actual phone number
            $table->string('display_phone_number')->nullable(); // Formatted display number
            $table->string('verified_name')->nullable(); // Business verified name
            
            // Profile
            $table->string('profile_picture_url')->nullable();
            
            // Quality and status
            $table->string('quality_rating')->nullable(); // GREEN, YELLOW, RED
            $table->string('name_status')->nullable();
            $table->string('new_name_status')->nullable();
            $table->enum('status', ['active', 'inactive', 'restricted', 'disconnected'])->default('active');
            
            // Verification
            $table->string('verification_status')->nullable(); // VERIFIED, NOT_VERIFIED
            $table->string('verification_code')->nullable();
            $table->timestamp('verification_code_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            
            // Certificates and limits
            $table->text('certificate')->nullable();
            $table->json('messaging_limit')->nullable(); // Messaging limit details
            $table->string('messaging_limit_tier')->nullable(); // TIER_50, TIER_250, TIER_1K, etc.
            $table->json('rate_limit')->nullable();
            
            // Business account settings
            $table->boolean('is_official_business_account')->default(false);
            $table->boolean('is_pin_enabled')->default(false);
            
            // API configuration
            $table->string('platform_type')->nullable(); // CLOUD_API, ON_PREMISE
            $table->json('throughput')->nullable();
            $table->json('webhook_configuration')->nullable();
            $table->string('search_visibility')->nullable();
            $table->string('account_mode')->nullable();
            
            // Settings and metadata
            $table->json('settings')->nullable();
            $table->json('metadata')->nullable();
            $table->json('facebook_data')->nullable(); // Full snapshot from Facebook API
            
            // Usage tracking
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_status_change_at')->nullable();
            
            // Webhook
            $table->boolean('webhook_subscribed')->default(false);
            $table->timestamp('webhook_subscribed_at')->nullable();
            $table->text('webhook_error')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['business_id', 'status']);
            $table->index('quality_rating');
            $table->index('messaging_limit_tier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_phone_numbers');
    }
};


