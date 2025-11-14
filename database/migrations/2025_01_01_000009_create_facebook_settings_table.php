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
        Schema::create('facebook_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            
            // Facebook/Meta Business identifiers
            $table->string('meta_business_id')->nullable()->index(); // Meta Business Manager ID
            $table->string('app_id')->index(); // Facebook App ID
            $table->string('waba_id')->nullable()->index(); // WhatsApp Business Account ID
            
            // Authentication tokens (encrypted)
            $table->text('access_token'); // Long-lived user access token
            $table->string('token_type')->default('user'); // user, system, page
            $table->timestamp('token_expires_at')->nullable();
            
            // App credentials (encrypted)
            $table->text('app_secret'); // Facebook App Secret
            $table->text('webhook_verify_token')->nullable(); // Webhook verification token
            
            // Connection status
            $table->enum('status', ['active', 'expired', 'revoked', 'suspended'])->default('active');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            
            // Webhook configuration
            $table->string('webhook_url')->nullable();
            $table->json('webhook_fields')->nullable(); // Subscribed webhook fields
            $table->boolean('webhook_subscribed')->default(false);
            $table->timestamp('webhook_subscribed_at')->nullable();
            
            // Permissions and scopes
            $table->json('granted_permissions')->nullable(); // Permissions granted by user
            $table->json('required_permissions')->nullable(); // Permissions needed by app
            
            // Rate limiting tracking
            $table->integer('api_call_count')->default(0);
            $table->decimal('usage_percentage', 5, 2)->default(0);
            $table->boolean('is_throttled')->default(false);
            $table->timestamp('throttle_until')->nullable();
            $table->timestamp('rate_limit_reset_at')->nullable();
            
            // Error tracking
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->integer('error_count')->default(0);
            
            // User information
            $table->json('user_info')->nullable(); // User who connected the account
            
            // Metadata and configuration
            $table->json('metadata')->nullable(); // Additional info from Facebook
            $table->json('settings')->nullable(); // Custom business settings
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['business_id', 'status']);
            $table->index('status');
            $table->index('connected_at');
            $table->index('is_throttled');
            $table->unique(['business_id', 'app_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_settings');
    }
};


