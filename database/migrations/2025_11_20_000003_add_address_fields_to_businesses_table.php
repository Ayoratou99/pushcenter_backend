<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Business model, the API filters and the management UI all use these
     * address / opening hours fields, but the original table never created them.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'country_code')) {
                $table->string('country_code', 10)->nullable()->after('phone_number');
            }
            if (! Schema::hasColumn('businesses', 'city')) {
                $table->string('city')->nullable()->after('address');
            }
            if (! Schema::hasColumn('businesses', 'state_province')) {
                $table->string('state_province')->nullable()->after('city');
            }
            if (! Schema::hasColumn('businesses', 'postal_code')) {
                $table->string('postal_code', 20)->nullable()->after('state_province');
            }
            if (! Schema::hasColumn('businesses', 'country')) {
                $table->string('country')->nullable()->after('postal_code');
            }
            if (! Schema::hasColumn('businesses', 'latitude')) {
                $table->decimal('latitude', 10, 8)->nullable()->after('country');
            }
            if (! Schema::hasColumn('businesses', 'longitude')) {
                $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('businesses', 'business_hours')) {
                $table->json('business_hours')->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('businesses', 'is_24_hours')) {
                $table->boolean('is_24_hours')->default(false)->after('business_hours');
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->index('city');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropIndex(['country']);
            $table->dropColumn([
                'country_code', 'city', 'state_province', 'postal_code',
                'country', 'latitude', 'longitude', 'business_hours', 'is_24_hours',
            ]);
        });
    }
};
