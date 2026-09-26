<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory,Notifiable,SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'birth_date' => 'date',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'banned_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class)->withPivot(['assigned_by', 'assigned_at']);
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role || $this->roles()->where('name', $role)->exists();
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function applications()
    {
        return $this->hasMany(AccountApplication::class);
    }

    public function sellerProfile()
    {
        return $this->hasOne(SellerProfile::class);
    }

    public function logisticsProfile()
    {
        return $this->hasOne(LogisticsProfile::class);
    }

    public function riderProfile()
    {
        return $this->hasOne(RiderProfile::class);
    }

    public function buyerOrders()
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    public function returnRequests()
    {
        return $this->hasMany(ReturnRequest::class, 'buyer_id');
    }

    public function reviewedReturnRequests()
    {
        return $this->hasMany(ReturnRequest::class, 'reviewed_by');
    }

    public function returnEvidence()
    {
        return $this->hasMany(ReturnEvidence::class, 'uploaded_by');
    }

    public function approvedRefunds()
    {
        return $this->hasMany(Refund::class, 'approved_by');
    }

    public function openedDisputes()
    {
        return $this->hasMany(
            Dispute::class,
            'opened_by'
        );
    }

    public function assignedDisputes()
    {
        return $this->hasMany(
            Dispute::class,
            'assigned_to'
        );
    }

    public function disputes()
    {
        return $this->belongsToMany(
            Dispute::class,
            'dispute_participants'
        )
            ->withPivot([
                'participant_role',
                'joined_at',
            ]);
    }

    public function disputeEvidence()
    {
        return $this->hasMany(
            DisputeEvidence::class,
            'uploaded_by'
        );
    }

    public function disputeEvents()
    {
        return $this->hasMany(
            DisputeEvent::class,
            'actor_user_id'
        );
    }

    public function createdConversations()
    {
        return $this->hasMany(
            Conversation::class,
            'created_by'
        );
    }

    public function conversations()
    {
        return $this->belongsToMany(
            Conversation::class,
            'conversation_participants'
        )
            ->withPivot([
                'participant_role',
                'last_read_at',
                'joined_at',
            ]);
    }

    public function sentMessages()
    {
        return $this->hasMany(
            Message::class,
            'sender_id'
        );
    }
}
