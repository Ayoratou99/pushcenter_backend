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
        Schema::table('templates', function (Blueprint $table) {
            $table->string('template_identifier')->nullable()->after('name');
            $table->unique(['business_id', 'template_identifier'], 'templates_business_id_template_identifier_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropUnique('templates_business_id_template_identifier_unique');
            $table->dropColumn('template_identifier');
        });
    }
};
