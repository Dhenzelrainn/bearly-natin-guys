<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SortingZone extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['destination_rules' => 'array'];
    }
}
