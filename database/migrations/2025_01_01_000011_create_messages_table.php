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
        // Main messages table - simplified
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            
            // Message identification
            $table->string('message_id')->unique()->index();
            $table->string('external_id')->nullable(); // ID from external service
            
            // Message type and status
            $table->enum('message_type', ['email', 'sms', 'whatsapp'])->index();
            $table->enum('status', ['pending', 'queued', 'sending', 'sent', 'delivered', 'read', 'failed', 'cancelled'])->default('pending')->index();
            
            // Basic tracking
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            
            // Timestamps
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            
            // Campaign tracking
            $table->string('campaign_id')->nullable()->index();
            
            // Cost tracking
            $table->decimal('cost', 8, 2)->nullable();
            $table->string('currency', 3)->default('XAF');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'message_type']);
            $table->index('created_at');
        });

        // WhatsApp messages table
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->onDelete('restrict');
            
            // Template or custom
            $table->foreignId('template_id')->nullable()->constrained('templates')->onDelete('restrict');
            
            $table->boolean('is_template')->default(false)->index();
            
            $table->foreignId('whatsapp_phone_number_id')->index()->constrained('whatsapp_phone_numbers')->onDelete('restrict'); // Sender ID/name
            
            // Recipient
            $table->string('recipient_number')->index();
            $table->string('recipient_name')->nullable();
            
            // Content
            $table->text('content')->nullable(); // Custom message content
            $table->text('media_url')->nullable(); // Media URL
            $table->text('button_url')->nullable(); // Button URL
            $table->text('button_text')->nullable(); // Button Text
            $table->json('template_variables')->nullable(); // Variables for template
            $table->json('metadata')->nullable(); // Media, buttons, etc.
            
            $table->timestamps();
            $table->softDeletes();
        });

        // SMS messages table
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->onDelete('cascade');
            
            // Template or custom
            $table->foreignId('template_id')->nullable()->constrained('templates')->onDelete('set null');
            $table->boolean('is_template')->default(false)->index();
            
            // Recipient
            $table->string('recipient_number')->index();
            $table->string('recipient_name')->nullable();
            
            // Sender
            $table->foreignId('sms_phone_number_id')->index()->constrained('sms_phone_numbers')->onDelete('restrict'); // Sender ID/name
            
            // Content
            $table->text('content')->nullable(); // Custom message content
            $table->json('template_variables')->nullable(); // Variables for template
            
            // SMS specific
            $table->integer('message_count')->default(1); // Number of SMS segments
            
            $table->timestamps();
            $table->softDeletes();
        });

        // Email messages table
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->onDelete('cascade');
            
            // Template or custom
            $table->foreignId('template_id')->nullable()->constrained('templates')->onDelete('set null');
            $table->boolean('is_template')->default(false)->index();
            
            // Recipient
            $table->string('recipient_email')->index();
            $table->string('recipient_name')->nullable();
            
            // Sender
            $table->string('sender_email')->nullable();
            $table->string('sender_name')->nullable();
            
            // Content
            $table->string('subject')->nullable();
            $table->text('content')->nullable(); // Custom message content (HTML/text)
            $table->json('template_variables')->nullable(); // Variables for template
            
            // Email specific
            $table->json('attachments')->nullable(); // Attachment information
            $table->json('cc')->nullable(); // CC recipients
            $table->json('bcc')->nullable(); // BCC recipients
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('messages');
    }
};


