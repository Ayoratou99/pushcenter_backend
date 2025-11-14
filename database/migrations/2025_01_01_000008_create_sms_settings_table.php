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
        Schema::create('sms_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            
            // Configuration name
            $table->string('name')->index();
            $table->text('description')->nullable();
            
            // SMS Provider
            $table->enum('provider', ['twilio', 'nexmo', 'africastalking', 'orange', 'mtn', 'custom'])->default('twilio');
            
            // API Configuration
            $table->string('api_key')->nullable(); // Encrypted
            $table->text('api_secret')->nullable(); // Encrypted
            $table->string('account_sid')->nullable(); // For Twilio
            $table->string('api_url')->nullable(); // For custom providers
            
            // Sender configuration
            $table->string('sender_id'); // Default sender ID/name
            $table->string('sender_phone')->nullable(); // Sender phone number if required
            
            // Credentials and tokens
            $table->json('credentials')->nullable(); // Additional provider-specific credentials (encrypted)
            
            // Status and testing
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // Default SMS provider for this business
            $table->timestamp('last_tested_at')->nullable();
            $table->enum('test_status', ['not_tested', 'success', 'failed'])->default('not_tested');
            $table->text('test_error')->nullable();
            
            // Usage tracking
            $table->integer('messages_sent')->default(0);
            $table->decimal('total_cost', 10, 2)->default(0); // Total cost spent
            $table->timestamp('last_used_at')->nullable();
            
            // Rate limiting and quotas
            $table->integer('hourly_limit')->nullable(); // Max SMS per hour
            $table->integer('daily_limit')->nullable(); // Max SMS per day
            $table->decimal('balance', 10, 2)->nullable(); // Current balance (if applicable)
            $table->timestamp('balance_updated_at')->nullable();
            
            // Cost configuration
            $table->decimal('cost_per_sms', 8, 2)->default(25.00); // Cost per SMS in XAF
            $table->string('currency', 3)->default('XAF');
            
            // Webhook configuration
            $table->string('webhook_url')->nullable(); // Delivery status webhook
            $table->string('webhook_secret')->nullable(); // Webhook verification secret
            
            // Metadata
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'is_default']);
            $table->index('provider');
            $table->unique(['business_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_settings');
    }
};


