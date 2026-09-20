<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'middle_initial',
        'sex',
        'birthday',
        'email',
        'contact_number',
        'role',
        'status',
        'logistics_id',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'province',
        'city',
        'barangay',
        'street_address',
        'business_name',
        'business_category',
        'vehicle_type',
        'plate_number',
        'valid_id_path',
        'business_permit_path',
        'or_cr_path',
        'driver_license_path',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'birthday' => 'date',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function logisticsCenter()
    {
        return $this->belongsTo(self::class, 'logistics_id');
    }

    public function riderApplicants()
    {
        return $this->hasMany(self::class, 'logistics_id');
    }

    public function approver()
    {
        return $this->belongsTo(self::class, 'approved_by');
    }
}
