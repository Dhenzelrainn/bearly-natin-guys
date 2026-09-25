<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default_shipping' => 'boolean', 'is_default_pickup' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
