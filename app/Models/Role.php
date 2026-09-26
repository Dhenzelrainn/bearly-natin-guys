<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $guarded = [];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function announcements()
    {
        return $this->belongsToMany(Announcement::class, 'announcement_roles');
    }
}
