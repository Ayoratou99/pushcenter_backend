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
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            
            // Template identification
            $table->string('name')->index();
            $table->text('description')->nullable();
            
            // Template type
            $table->enum('type', ['email', 'sms', 'whatsapp'])->index();
            
            // Template settings
            $table->enum('category', ['marketing', 'transactional', 'notification'])->default('marketing');
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->boolean('is_active')->default(true);
            
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
            $table->index(['business_id', 'type']);
            $table->unique(['business_id', 'name', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};

