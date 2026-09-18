<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outcome of the delivery notification, tracked separately from the message
     * itself: a customer endpoint being down says nothing about whether the
     * email was delivered, so the two must never be confused.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'webhook_status')) {
                $table->enum('webhook_status', ['not_applicable', 'pending', 'delivered', 'failed'])
                    ->default('not_applicable')
                    ->after('error_message')
                    ->index();
            }

            if (! Schema::hasColumn('messages', 'webhook_error')) {
                $table->text('webhook_error')->nullable()->after('webhook_status');
            }

            if (! Schema::hasColumn('messages', 'webhook_attempts')) {
                $table->unsignedSmallInteger('webhook_attempts')->default(0)->after('webhook_error');
            }

            if (! Schema::hasColumn('messages', 'webhook_last_attempt_at')) {
                $table->timestamp('webhook_last_attempt_at')->nullable()->after('webhook_attempts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'webhook_status',
                'webhook_error',
                'webhook_attempts',
                'webhook_last_attempt_at',
            ]);
        });
    }
};
