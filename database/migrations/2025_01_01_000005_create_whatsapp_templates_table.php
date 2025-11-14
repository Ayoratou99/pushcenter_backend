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
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            
            // Template identification
            $table->string('name')->index();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('language', 10)->default('fr');
            $table->string('category')->default('MARKETING'); // MARKETING, UTILITY, AUTHENTICATION
            
            // Template content structure
            $table->json('header')->nullable(); // Header component (text, image, video, document)
            $table->text('body'); // Body text with variables {{1}}, {{2}}, etc.
            $table->json('footer')->nullable(); // Footer component
            $table->json('buttons')->nullable(); // Call-to-action buttons
            $table->json('components')->nullable(); // Full components structure for Meta API
            
            // Template variables
            $table->json('variables')->nullable(); // Variable definitions
            $table->json('sample_data')->nullable(); // Sample data for approval
            
            // WhatsApp/Facebook API fields
            $table->string('template_id')->nullable()->index(); // Meta template ID after approval
            $table->string('facebook_template_id')->nullable(); // Facebook template ID
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'disabled'])->default('draft');
            $table->string('facebook_status')->nullable(); // Status from Meta API
            $table->text('rejection_reason')->nullable();
            
            // Timestamps for template lifecycle
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            // Template metadata
            $table->json('quality_score')->nullable(); // Template quality metrics from Meta
            $table->json('metadata')->nullable();
            
            // Usage and cost
            $table->decimal('cost_per_message', 8, 2)->default(20.00);
            $table->integer('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            
            // Settings
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_variables')->default(true);
            $table->integer('max_variables')->default(10);
            
            // Media tracking
            $table->bigInteger('media_file_size')->nullable(); // Size in bytes
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['business_id', 'status']);
            $table->index(['status', 'is_active']);
            $table->index(['language', 'category']);
            $table->unique(['business_id', 'name', 'language']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};


