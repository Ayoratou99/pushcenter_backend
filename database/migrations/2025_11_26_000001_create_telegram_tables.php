<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Telegram channel: one bot per application, the people who started it,
     * the invitation links that tie them to the application's own users,
     * templates and sent messages.
     *
     * A bot can only write to someone who pressed "Start" in its chat, hence
     * subscribers and invitations.
     */
    public function up(): void
    {
        Schema::create('telegram_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('businesses')->cascadeOnDelete();
            $table->text('bot_token'); // encrypted cast
            $table->string('bot_token_hint', 8)->nullable();
            $table->unsignedBigInteger('bot_id')->nullable();
            $table->string('bot_username')->nullable();
            $table->string('bot_name')->nullable();
            $table->text('welcome_message')->nullable();
            // Last getUpdates update_id handled.
            $table->unsignedBigInteger('update_offset')->default(0);
            $table->enum('test_status', ['not_tested', 'success', 'failed'])->default('not_tested');
            $table->text('test_error')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->text('poll_error')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->bigInteger('chat_id');
            // The application's own reference for this person (user id, email,
            // phone...), set by the invitation link they opened.
            $table->string('external_ref')->nullable();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('language_code', 16)->nullable();
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'chat_id']);
            $table->index(['business_id', 'external_ref']);
        });

        Schema::create('telegram_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            // The /start payload: random, so nobody can claim someone else's
            // reference by typing it.
            $table->string('token', 64)->unique();
            $table->string('external_ref')->nullable();
            $table->string('label')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('telegram_subscriber_id')->nullable()->constrained('telegram_subscribers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('telegram_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->default('notification');
            // Text with {{ variable }} placeholders, like email templates.
            $table->text('body');
            $table->enum('parse_mode', ['HTML', 'MarkdownV2', 'plain'])->default('HTML');
            $table->json('buttons')->nullable(); // [{text, url}], shown under the message
            $table->boolean('disable_web_page_preview')->default(false);
            $table->json('variables')->nullable();
            $table->json('sample_data')->nullable();
            $table->enum('status', ['draft', 'active'])->default('active');
            $table->boolean('is_active')->default(true);
            $table->integer('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('telegram_template_id')->nullable()->constrained('telegram_templates')->nullOnDelete();
            $table->foreignId('telegram_subscriber_id')->nullable()->constrained('telegram_subscribers')->nullOnDelete();
            $table->bigInteger('chat_id');
            $table->string('recipient_label')->nullable();
            $table->string('external_ref')->nullable();
            $table->text('text');
            $table->string('parse_mode', 16)->nullable();
            $table->json('reply_markup')->nullable();
            $table->boolean('disable_link_preview')->default(false);
            $table->json('template_variables')->nullable();
            // Telegram's message_id once sent.
            $table->unsignedBigInteger('provider_message_id')->nullable();
            $table->timestamps();

            $table->index('chat_id');
        });

        // messages.message_type gains `telegram`. On PostgreSQL Laravel's enum
        // is a varchar with a CHECK constraint, which change() does not rewrite.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_message_type_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_message_type_check CHECK (message_type IN ('email', 'sms', 'whatsapp', 'telegram'))");
        } else {
            Schema::table('messages', function (Blueprint $table) {
                $table->enum('message_type', ['email', 'sms', 'whatsapp', 'telegram'])->change();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('telegram_templates');
        Schema::dropIfExists('telegram_invitations');
        Schema::dropIfExists('telegram_subscribers');
        Schema::dropIfExists('telegram_settings');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_message_type_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_message_type_check CHECK (message_type IN ('email', 'sms', 'whatsapp'))");
        } else {
            Schema::table('messages', function (Blueprint $table) {
                $table->enum('message_type', ['email', 'sms', 'whatsapp'])->change();
            });
        }
    }
};
