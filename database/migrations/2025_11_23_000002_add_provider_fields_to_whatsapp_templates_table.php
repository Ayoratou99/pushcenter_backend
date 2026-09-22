<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AyosPush renames every template it creates (`<name>_biz<id>_<timestamp>`)
     * and sends by that name, so the provider identity has to be kept next to
     * our own name.
     */
    public function up(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->string('provider', 32)->nullable()->after('facebook_status');
            $table->unsignedBigInteger('provider_template_id')->nullable()->after('provider');
            $table->string('provider_template_name', 512)->nullable()->after('provider_template_id');
            $table->text('provider_error')->nullable()->after('provider_template_name');
            $table->timestamp('provider_synced_at')->nullable()->after('provider_error');

            $table->index(['business_id', 'provider_template_id']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'provider_template_id']);
            $table->dropColumn([
                'provider',
                'provider_template_id',
                'provider_template_name',
                'provider_error',
                'provider_synced_at',
            ]);
        });
    }
};
