<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quarantine_reviewed_at' => 'datetime',
        ];
    }

    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function quarantineReviewer()
    {
        return $this->belongsTo(User::class, 'quarantine_reviewed_by');
    }
}
