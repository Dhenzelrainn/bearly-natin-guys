<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function dispute()
    {
        return $this->belongsTo(Dispute::class);
    }

    public function creator()
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function participants()
    {
        return $this->belongsToMany(
            User::class,
            'conversation_participants'
        )
            ->withPivot([
                'participant_role',
                'last_read_at',
                'joined_at',
            ]);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)
            ->latestOfMany('sent_at');
    }
}
