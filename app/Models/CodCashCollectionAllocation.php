<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodCashCollectionAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_due_minor' => 'integer',
            'amount_collected_minor' => 'integer',
            'amount_reconciled_minor' => 'integer',
        ];
    }

    public function collection()
    {
        return $this->belongsTo(CodCashCollection::class, 'cod_cash_collection_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }
}
