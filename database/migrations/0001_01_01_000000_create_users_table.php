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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_initial')->nullable();
            $table->enum('sex', ['female', 'male', 'prefer_not_to_say']);
            $table->date('birthday');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('contact_number');
            $table->enum('role', ['buyer', 'seller', 'courier', 'admin']);
            $table->enum('status', ['active', 'pending', 'needs_revision', 'rejected', 'suspended', 'deactivated', 'banned'])->default('pending');
            $table->string('province');
            $table->string('city');
            $table->string('barangay');
            $table->string('street_address');
            $table->string('business_name')->nullable();
            $table->string('business_category')->nullable();
            $table->string('vehicle_type')->nullable();
            $table->string('plate_number')->nullable();
            $table->string('valid_id_path')->nullable();
            $table->string('business_permit_path')->nullable();
            $table->string('courier_documents_path')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
