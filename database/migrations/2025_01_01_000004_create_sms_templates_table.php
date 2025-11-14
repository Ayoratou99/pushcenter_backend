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
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            
            // Template identification
            $table->string('name')->index();
            $table->text('description')->nullable();
            
            // Message content
            $table->text('message'); // SMS body with variables {{name}}, {{code}}, etc.
            $table->integer('message_length')->default(0); // Character count
            $table->integer('segments_count')->default(1); // Number of SMS segments (160 chars per segment)
            
            // Template variables
            $table->json('variables')->nullable(); // Available variables
            $table->json('sample_data')->nullable(); // Sample data for preview
            
            // Settings
            $table->enum('category', ['marketing', 'transactional', 'otp', 'notification'])->default('transactional');
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->boolean('is_active')->default(true);
            
            // Cost estimation
            $table->decimal('cost_per_message', 8, 2)->default(25.00); // Cost in XAF per SMS
            
            // Usage tracking
            $table->integer('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'is_active']);
            $table->unique(['business_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};


