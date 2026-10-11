<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodCashCollectionEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function collection()
    {
        return $this->belongsTo(CodCashCollection::class, 'cod_cash_collection_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
