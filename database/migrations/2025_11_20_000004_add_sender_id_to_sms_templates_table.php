<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The SmsTemplate model and the API accept a per-template sender id
     * (alphanumeric sender name), but the column was never created.
     */
    public function up(): void
    {
        Schema::table('sms_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_templates', 'sender_id')) {
                $table->string('sender_id')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_templates', function (Blueprint $table) {
            $table->dropColumn('sender_id');
        });
    }
};
