<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * App secrets become write-only: only their hash is kept, exactly like a
     * password. Existing plaintext secrets are hashed in place so integrations
     * already in production keep working; the clear value simply stops being
     * readable from the database or the API.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'app_secret_hash')) {
                $table->string('app_secret_hash')->nullable()->after('app_id');
            }

            // Last characters of the secret, so the UI can tell two keys apart
            // without ever exposing the secret itself.
            if (! Schema::hasColumn('businesses', 'app_secret_hint')) {
                $table->string('app_secret_hint', 8)->nullable()->after('app_secret_hash');
            }
        });

        if (Schema::hasColumn('businesses', 'app_secret')) {
            DB::table('businesses')
                ->whereNotNull('app_secret')
                ->orderBy('id')
                ->chunkById(100, function ($businesses) {
                    foreach ($businesses as $business) {
                        DB::table('businesses')->where('id', $business->id)->update([
                            'app_secret_hash' => Hash::make($business->app_secret),
                            'app_secret_hint' => substr((string) $business->app_secret, -4),
                        ]);
                    }
                });

            Schema::table('businesses', function (Blueprint $table) {
                $table->dropColumn('app_secret');
            });
        }
    }

    /**
     * A hash cannot be reversed, so rolling back restores the column but leaves
     * it empty: every application has to be issued a new secret.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'app_secret')) {
                $table->string('app_secret')->nullable()->after('app_id');
            }

            $table->dropColumn(['app_secret_hash', 'app_secret_hint']);
        });
    }
};
