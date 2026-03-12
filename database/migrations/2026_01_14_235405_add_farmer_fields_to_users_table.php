<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add phone field after email
            $table->string('phone')->nullable()->after('email');
            
            // Add location field after phone
            $table->string('location')->nullable()->after('phone');
            
            // Add verification fields after role
            $table->boolean('is_verified')->default(false)->after('role');
            $table->enum('verification_status', ['pending', 'under_review', 'verified'])->default('pending')->after('is_verified');
            
            // For farmers only
            $table->string('farm_name')->nullable()->after('verification_status');
            $table->text('farm_description')->nullable()->after('farm_name');
            $table->string('farm_size')->nullable()->after('farm_description');
            $table->string('specialty')->nullable()->after('farm_size');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'location',
                'is_verified',
                'verification_status',
                'farm_name',
                'farm_description',
                'farm_size',
                'specialty'
            ]);
        });
    }
};