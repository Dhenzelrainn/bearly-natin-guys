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
        return ['birthday' => 'date', 'birth_date' => 'date', 'email_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'approved_at' => 'datetime', 'password' => 'hashed'];
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
}
