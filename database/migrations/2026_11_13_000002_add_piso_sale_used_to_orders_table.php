<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('piso_sale_used')->default(false);
            $table->index(['buyer_id', 'status', 'piso_sale_used'], 'orders_piso_sale_reservation_index');
        });

        DB::table('orders')
            ->whereIn('status', ['pending_payment', 'paid', 'processing', 'partially_fulfilled'])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('seller_orders')
                    ->join('order_items', 'order_items.seller_order_id', '=', 'seller_orders.id')
                    ->join('platform_campaign_participation_items as campaign_items', 'campaign_items.id', '=', 'order_items.platform_campaign_participation_item_id')
                    ->join('platform_campaign_participations as participations', 'participations.id', '=', 'campaign_items.platform_campaign_participation_id')
                    ->join('platform_promotions as campaigns', 'campaigns.id', '=', 'participations.platform_promotion_id')
                    ->whereColumn('seller_orders.order_id', 'orders.id')
                    ->where('campaigns.campaign_type', 'piso_sale');
            })
            ->update(['piso_sale_used' => true]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_piso_sale_reservation_index');
            $table->dropColumn('piso_sale_used');
        });
    }
};
