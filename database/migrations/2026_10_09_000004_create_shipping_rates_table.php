<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_key', 50)->default('bearly_logistics');
            $table->string('route_tier', 40);
            $table->unsignedBigInteger('first_kg_fee_minor');
            $table->unsignedBigInteger('additional_kg_surcharge_minor')->nullable();
            $table->timestamps();
            $table->unique(['provider_key', 'route_tier']);
        });

        $now = now();
        DB::table('shipping_rates')->insert([
            ['provider_key' => 'bearly_logistics', 'route_tier' => 'nearby_delivery', 'first_kg_fee_minor' => 3400, 'additional_kg_surcharge_minor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['provider_key' => 'bearly_logistics', 'route_tier' => 'same_province', 'first_kg_fee_minor' => 4000, 'additional_kg_surcharge_minor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['provider_key' => 'bearly_logistics', 'route_tier' => 'nearby_provinces', 'first_kg_fee_minor' => 6000, 'additional_kg_surcharge_minor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['provider_key' => 'bearly_logistics', 'route_tier' => 'inter_island', 'first_kg_fee_minor' => 10000, 'additional_kg_surcharge_minor' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
