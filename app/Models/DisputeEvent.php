<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisputeEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function dispute()
    {
        return $this->belongsTo(Dispute::class);
    }

    public function actor()
    {
        return $this->belongsTo(
            User::class,
            'actor_user_id'
        );
    }
}
