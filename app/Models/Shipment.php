<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Shipment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'destination_address_snapshot' => 'array',
            'return_shipping_quote' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Shipment $shipment): void {
            if (! Schema::hasTable('shipment_seller_orders')) {
                return;
            }

            DB::table('shipment_seller_orders')->insertOrIgnore([
                'shipment_id' => $shipment->id,
                'seller_order_id' => $shipment->seller_order_id,
                'created_at' => $shipment->created_at ?? now(),
                'updated_at' => $shipment->updated_at ?? now(),
            ]);
        });
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function sellerOrders(): BelongsToMany
    {
        return $this->belongsToMany(SellerOrder::class, 'shipment_seller_orders')
            ->withTimestamps()
            ->orderBy('seller_orders.id');
    }

    /**
     * Return every Seller Order represented by this physical shipment.
     * The primary belongsTo remains available for legacy callers and return
     * shipments; consolidated outbound readers should use this membership.
     *
     * @return Collection<int, SellerOrder>
     */
    public function includedSellerOrders(bool $withItems = false): Collection
    {
        if (Schema::hasTable('shipment_seller_orders')) {
            $this->loadMissing($withItems ? 'sellerOrders.items' : 'sellerOrders');

            return $this->sellerOrders;
        }

        $this->loadMissing($withItems ? 'sellerOrder.items' : 'sellerOrder');

        return collect([$this->sellerOrder])->filter()->values();
    }

    /**
     * All immutable order-item snapshots represented by the shipment.
     *
     * @return Collection<int, OrderItem>
     */
    public function includedOrderItems(): Collection
    {
        return $this->includedSellerOrders(withItems: true)
            ->flatMap(fn (SellerOrder $sellerOrder) => $sellerOrder->items)
            ->unique('id')
            ->values();
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function originLogisticsProfile()
    {
        return $this->belongsTo(
            LogisticsProfile::class,
            'origin_logistics_profile_id'
        );
    }

    public function transfer()
    {
        return $this->hasOne(ShipmentTransfer::class);
    }

    public function linehaulRunAssignment()
    {
        return $this->hasOne(LinehaulRunShipment::class);
    }

    public function originAddress()
    {
        return $this->belongsTo(Address::class, 'origin_address_id');
    }

    public function destinationAddress()
    {
        return $this->belongsTo(Address::class, 'destination_address_id');
    }

    public function waybill()
    {
        return $this->hasOne(Waybill::class);
    }

    public function parcels()
    {
        return $this->hasMany(Parcel::class);
    }

    public function events()
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at');
    }

    public function codCashCollection()
    {
        return $this->hasOne(CodCashCollection::class);
    }

    public function shippingSettlement()
    {
        return $this->hasOne(ShippingSettlement::class);
    }

    public function returnRequest()
    {
        return $this->hasOne(ReturnRequest::class, 'return_shipment_id');
    }
}
