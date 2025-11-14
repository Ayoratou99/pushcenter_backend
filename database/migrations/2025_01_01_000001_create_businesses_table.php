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
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone_number')->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            
            // Address fields
            $table->string('address')->nullable();
            
            // Settings as JSON
            $table->json('settings')->nullable();
            
            // Status and verification
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            // Business hours
            $table->string('timezone')->default('UTC');
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['status', 'verification_status']);
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};


