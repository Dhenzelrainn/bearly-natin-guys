<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageReceipt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
