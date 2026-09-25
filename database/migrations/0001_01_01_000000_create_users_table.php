<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Transitional compatibility fields used by the existing controllers.
            // New code writes normalized profile/application/address records as well.
            $table->string('name')->nullable();
            $table->string('first_name', 80);
            $table->string('middle_initial', 5)->nullable();
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->string('sex', 30)->nullable();
            $table->date('birthday')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('email')->unique();
            $table->string('phone', 30)->nullable()->index();
            $table->string('contact_number', 30)->nullable();
            $table->string('role', 32)->nullable()->index();
            $table->string('province', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('barangay', 120)->nullable();
            $table->string('street_address', 255)->nullable();
            $table->string('business_name', 160)->nullable();
            $table->string('business_category', 120)->nullable();
            $table->string('vehicle_type', 80)->nullable();
            $table->string('plate_number', 30)->nullable();
            $table->string('valid_id_path', 500)->nullable();
            $table->string('business_permit_path', 500)->nullable();
            $table->string('or_cr_path', 500)->nullable();
            $table->string('driver_license_path', 500)->nullable();
            $table->foreignId('logistics_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('password');
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
