<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dispute extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'response_due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function sellerOrder()
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function participants()
    {
        return $this->belongsToMany(
            User::class,
            'dispute_participants'
        )
            ->withPivot([
                'participant_role',
                'joined_at',
            ]);
    }

    public function evidence()
    {
        return $this->hasMany(DisputeEvidence::class);
    }

    public function events()
    {
        return $this->hasMany(DisputeEvent::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}
