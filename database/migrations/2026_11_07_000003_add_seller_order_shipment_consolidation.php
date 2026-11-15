<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_seller_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['shipment_id', 'seller_order_id'], 'shipment_seller_orders_pair_unique');
            $table->index(['seller_order_id', 'shipment_id'], 'shipment_seller_orders_order_idx');
        });

        DB::table('shipments')
            ->select(['id', 'seller_order_id', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($shipments): void {
                $now = now();
                DB::table('shipment_seller_orders')->insertOrIgnore(
                    $shipments->map(fn ($shipment): array => [
                        'shipment_id' => $shipment->id,
                        'seller_order_id' => $shipment->seller_order_id,
                        'created_at' => $shipment->created_at ?? $now,
                        'updated_at' => $shipment->updated_at ?? $now,
                    ])->all(),
                );
            });

        Schema::create('cod_cash_collection_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cod_cash_collection_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_due_minor');
            $table->unsignedBigInteger('amount_collected_minor')->nullable();
            $table->unsignedBigInteger('amount_reconciled_minor')->nullable();
            $table->timestamps();
            $table->unique(['cod_cash_collection_id', 'seller_order_id'], 'cod_cash_allocations_collection_order_unique');
            $table->index(['payment_id', 'seller_order_id'], 'cod_cash_allocations_payment_order_idx');
            $table->index(['order_id', 'payment_id'], 'cod_cash_allocations_marketplace_order_idx');
        });

        DB::table('cod_cash_collections')
            ->select(['id', 'payment_id', 'order_id', 'seller_order_id', 'amount_due_minor', 'amount_collected_minor', 'amount_reconciled_minor', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($collections): void {
                DB::table('cod_cash_collection_allocations')->insertOrIgnore(
                    $collections->map(fn ($collection): array => [
                        'cod_cash_collection_id' => $collection->id,
                        'payment_id' => $collection->payment_id,
                        'order_id' => $collection->order_id,
                        'seller_order_id' => $collection->seller_order_id,
                        'amount_due_minor' => $collection->amount_due_minor,
                        'amount_collected_minor' => (int) $collection->amount_collected_minor === (int) $collection->amount_due_minor
                            ? $collection->amount_due_minor
                            : null,
                        'amount_reconciled_minor' => (int) ($collection->amount_reconciled_minor ?? 0) === (int) $collection->amount_due_minor
                            ? $collection->amount_reconciled_minor
                            : null,
                        'created_at' => $collection->created_at ?? now(),
                        'updated_at' => $collection->updated_at ?? now(),
                    ])->all(),
                );
            });

        Schema::create('shipping_settlement_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('original_shipping_fee_minor');
            $table->unsignedBigInteger('buyer_paid_shipping_fee_minor');
            $table->unsignedBigInteger('platform_shipping_subsidy_minor');
            $table->string('shipping_funding_source', 40);
            $table->unsignedTinyInteger('zone_level');
            $table->unsignedBigInteger('origin_logistics_share_minor')->default(0);
            $table->unsignedBigInteger('logistics_share_minor');
            $table->unsignedBigInteger('rider_share_minor');
            $table->timestamps();
            $table->unique(['shipping_settlement_id', 'seller_order_id'], 'shipping_settlement_allocations_pair_unique');
            $table->index(['seller_order_id', 'shipping_settlement_id'], 'shipping_settlement_allocations_order_idx');
        });

        DB::table('shipping_settlements')
            ->where('settlement_type', 'outbound')
            ->select([
                'id', 'seller_order_id', 'original_shipping_fee_minor', 'buyer_paid_shipping_fee_minor',
                'platform_shipping_subsidy_minor', 'shipping_funding_source', 'zone_level',
                'origin_logistics_share_minor', 'logistics_share_minor', 'rider_share_minor',
                'created_at', 'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($settlements): void {
                DB::table('shipping_settlement_allocations')->insertOrIgnore(
                    $settlements->map(fn ($settlement): array => [
                        'shipping_settlement_id' => $settlement->id,
                        'seller_order_id' => $settlement->seller_order_id,
                        'original_shipping_fee_minor' => $settlement->original_shipping_fee_minor,
                        'buyer_paid_shipping_fee_minor' => $settlement->buyer_paid_shipping_fee_minor,
                        'platform_shipping_subsidy_minor' => $settlement->platform_shipping_subsidy_minor,
                        'shipping_funding_source' => $settlement->shipping_funding_source,
                        'zone_level' => $settlement->zone_level,
                        'origin_logistics_share_minor' => $settlement->origin_logistics_share_minor ?? 0,
                        'logistics_share_minor' => $settlement->logistics_share_minor,
                        'rider_share_minor' => $settlement->rider_share_minor,
                        'created_at' => $settlement->created_at ?? now(),
                        'updated_at' => $settlement->updated_at ?? now(),
                    ])->all(),
                );
            });
    }

    public function down(): void
    {
        $hasConsolidatedShipment = DB::table('shipment_seller_orders')
            ->join('shipments', 'shipments.id', '=', 'shipment_seller_orders.shipment_id')
            ->whereColumn('shipment_seller_orders.seller_order_id', '!=', 'shipments.seller_order_id')
            ->exists();
        $hasMultiOrderCodAllocation = DB::table('cod_cash_collection_allocations')
            ->select('cod_cash_collection_id')
            ->groupBy('cod_cash_collection_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $hasMultiOrderShippingAllocation = DB::table('shipping_settlement_allocations')
            ->select('shipping_settlement_id')
            ->groupBy('shipping_settlement_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasConsolidatedShipment || $hasMultiOrderCodAllocation || $hasMultiOrderShippingAllocation) {
            throw new RuntimeException('Cannot roll back Seller Order consolidation while multi-order shipment or Finance allocation history exists.');
        }

        Schema::dropIfExists('shipping_settlement_allocations');
        Schema::dropIfExists('cod_cash_collection_allocations');
        Schema::dropIfExists('shipment_seller_orders');
    }
};
