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
        Schema::create('sms_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            
            // Phone number identification
            $table->string('phone_number')->index(); // Actual phone number
            $table->string('display_phone_number')->nullable(); // Formatted display number
            $table->string('sender_id')->nullable()->index(); // SMS Sender ID/Name
            
            // Provider information
            $table->string('provider')->nullable(); // twilio, nexmo, etc.
            $table->string('provider_phone_number_id')->nullable(); // Provider's phone number ID
            
            // Status
            $table->enum('status', ['active', 'inactive', 'restricted', 'disconnected'])->default('active');
            
            // Verification
            $table->string('verification_status')->nullable(); // VERIFIED, NOT_VERIFIED
            $table->timestamp('verified_at')->nullable();
            
            // Capabilities
            $table->boolean('can_send_sms')->default(true);
            $table->boolean('can_receive_sms')->default(false);
            $table->boolean('can_send_mms')->default(false);
            
            // Limits and pricing
            $table->integer('daily_limit')->nullable();
            $table->integer('monthly_limit')->nullable();
            $table->decimal('cost_per_sms', 8, 4)->nullable();
            $table->string('currency', 3)->default('XAF');
            
            // Settings and metadata
            $table->json('settings')->nullable();
            $table->json('metadata')->nullable();
            $table->json('provider_data')->nullable(); // Full snapshot from provider API
            
            // Usage tracking
            $table->integer('messages_sent_today')->default(0);
            $table->integer('messages_sent_this_month')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_status_change_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['business_id', 'status']);
            $table->unique(['business_id', 'phone_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_phone_numbers');
    }
};

