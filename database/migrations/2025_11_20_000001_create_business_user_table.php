<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Managers assigned to a specific application (business).
     *
     * A user whose `scope` is "global" works on every business and does not need
     * any row here. A "restricted" manager only sees the businesses listed here.
     */
    public function up(): void
    {
        Schema::create('business_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'business_id']);
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_user');
    }
};
