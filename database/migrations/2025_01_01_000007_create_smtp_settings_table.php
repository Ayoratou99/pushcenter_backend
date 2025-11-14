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
        Schema::create('smtp_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            
            // Configuration name
            $table->string('name')->index();
            $table->text('description')->nullable();
            
            // SMTP Server configuration
            $table->string('host');
            $table->integer('port')->default(587);
            $table->enum('encryption', ['tls', 'ssl', 'none'])->default('tls');
            
            // Authentication
            $table->string('username');
            $table->text('password'); // Encrypted
            
            // From configuration
            $table->string('from_email');
            $table->string('from_name');
            
            // Reply-To configuration
            $table->string('reply_to_email')->nullable();
            $table->string('reply_to_name')->nullable();
            
            // Advanced settings
            $table->integer('timeout')->default(30); // Connection timeout in seconds
            $table->boolean('verify_peer')->default(true); // SSL certificate verification
            $table->json('headers')->nullable(); // Custom headers
            
            // Status and testing
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // Default SMTP for this business
            $table->timestamp('last_tested_at')->nullable();
            $table->enum('test_status', ['not_tested', 'success', 'failed'])->default('not_tested');
            $table->text('test_error')->nullable();
            
            // Usage tracking
            $table->integer('messages_sent')->default(0);
            $table->timestamp('last_used_at')->nullable();
            
            // Rate limiting (optional)
            $table->integer('second_limit')->nullable(); // Max emails per second
            $table->integer('hourly_limit')->nullable(); // Max emails per hour
            $table->integer('daily_limit')->nullable(); // Max emails per day
            
            // Metadata
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'is_default']);
            $table->unique(['business_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smtp_settings');
    }
};


