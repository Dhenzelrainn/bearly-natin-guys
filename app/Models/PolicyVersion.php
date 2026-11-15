<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolicyVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function policy()
    {
        return $this->belongsTo(Policy::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
