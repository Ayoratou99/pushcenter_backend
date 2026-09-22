<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WhatsApp messages are sent through AyosPush with a WhatsApp template.
     *
     * The original columns pointed at the generic `templates` table and made a
     * row in `whatsapp_phone_numbers` (never filled) mandatory: no WhatsApp
     * message could ever be stored. The sender is now the Meta phone number id
     * chosen in the application's WhatsApp settings, and AyosPush's request id
     * is kept to follow the delivery.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropForeign(['whatsapp_phone_number_id']);
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('whatsapp_phone_number_id')->nullable()->change();
            $table->foreign('whatsapp_phone_number_id')
                ->references('id')->on('whatsapp_phone_numbers')->nullOnDelete();

            $table->foreignId('whatsapp_template_id')->nullable()->after('template_id')
                ->constrained('whatsapp_templates')->nullOnDelete();

            $table->string('sender_phone_number_id', 64)->nullable()->after('whatsapp_phone_number_id');
            $table->string('provider_template_name', 512)->nullable()->after('whatsapp_template_id');

            // AyosPush answers 202 with a request id, sends in its own queue,
            // and reports the outcome on GET /v1/messages/{requestId}/status.
            $table->unsignedBigInteger('provider_request_id')->nullable()->index();
            $table->string('provider_status', 32)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->unsignedSmallInteger('status_checks')->default(0);
            $table->timestamp('provider_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropForeign(['whatsapp_template_id']);
            $table->dropForeign(['whatsapp_phone_number_id']);
            $table->dropIndex(['provider_request_id']);
            $table->dropColumn([
                'whatsapp_template_id',
                'sender_phone_number_id',
                'provider_template_name',
                'provider_request_id',
                'provider_status',
                'provider_message_id',
                'status_checks',
                'provider_checked_at',
            ]);
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->foreign('whatsapp_phone_number_id')
                ->references('id')->on('whatsapp_phone_numbers')->restrictOnDelete();
        });
    }
};
