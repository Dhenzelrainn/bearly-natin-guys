<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationDocument extends Model
{
    protected $guarded = [];

    public function application()
    {
        return $this->belongsTo(AccountApplication::class);
    }
}
