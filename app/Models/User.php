<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
        'valid_id_path',
        'business_permit_path',
        'or_cr_path',
        'driver_license_path',
    ];

    protected function casts(): array
    {
        return ['phone_verified_at' => 'datetime', 'terms_accepted_at' => 'datetime', 'birthday' => 'date', 'birth_date' => 'date', 'email_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'approved_at' => 'datetime', 'password' => 'hashed'];
    }

    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if (
                $user->wasChanged('password')
                || ($user->wasChanged('status') && $user->status !== 'active')
            ) {
                $user->rememberedAccounts()->delete();
            }
        });

        static::deleting(function (User $user): void {
            $user->rememberedAccounts()->delete();
        });
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

    public function rememberedAccounts(): HasMany
    {
        return $this->hasMany(RememberedAccount::class);
    }
}
