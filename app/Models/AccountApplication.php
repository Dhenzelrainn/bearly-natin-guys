<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountApplication extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function requestedRole()
    {
        return $this->belongsTo(Role::class, 'requested_role_id');
    }

    public function documents()
    {
        return $this->hasMany(ApplicationDocument::class, 'application_id');
    }

    public function businessCategory()
    {
        return $this->belongsTo(Category::class, 'business_category_id');
    }

    public function sponsorLogisticsProfile()
    {
        return $this->belongsTo(LogisticsProfile::class, 'sponsor_logistics_profile_id');
    }
}
