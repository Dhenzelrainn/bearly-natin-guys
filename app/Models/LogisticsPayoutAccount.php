<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsPayoutAccount extends Model
{
    protected $guarded = [];

    protected $hidden = ['destination_value'];

    protected function casts(): array
    {
        return ['destination_value' => 'encrypted'];
    }

    public function logisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function shippingSettlements(): HasMany
    {
        return $this->hasMany(ShippingSettlement::class);
    }

    public function maskedDestination(): string
    {
        return '•••• '.$this->destination_last4;
    }
}
