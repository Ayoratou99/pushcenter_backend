<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `email_messages.template_id` points at the generic `templates` registry,
     * but the actual email content (subject, html, variables) lives in
     * `email_templates`. Record which email template produced the message so it
     * can be rendered and traced.
     */
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('email_messages', 'email_template_id')) {
                $table->foreignId('email_template_id')
                    ->nullable()
                    ->after('template_id')
                    ->constrained('email_templates')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_template_id');
        });
    }
};
