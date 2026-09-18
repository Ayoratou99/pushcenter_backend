<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbound delivery notifications: when an application declares a webhook
     * URL, every status change of its messages is POSTed there, signed with the
     * webhook secret so the receiver can verify the payload really comes from us.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'webhook_url')) {
                $table->string('webhook_url')->nullable()->after('app_secret_hint');
            }

            if (! Schema::hasColumn('businesses', 'webhook_secret')) {
                $table->text('webhook_secret')->nullable()->after('webhook_url');
            }

            if (! Schema::hasColumn('businesses', 'webhook_events')) {
                // Null means "every event"; otherwise a whitelist such as
                // ["message.failed"].
                $table->json('webhook_events')->nullable()->after('webhook_secret');
            }
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['webhook_url', 'webhook_secret', 'webhook_events']);
        });
    }
};
