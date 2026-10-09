<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    protected $guarded = [];

    public function versions()
    {
        return $this->hasMany(PolicyVersion::class);
    }

    public function latestVersion()
    {
        return $this->hasOne(PolicyVersion::class)->latestOfMany();
    }
}
