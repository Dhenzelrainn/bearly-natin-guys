<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinehaulRunEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function run()
    {
        return $this->belongsTo(LinehaulRun::class, 'linehaul_run_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
