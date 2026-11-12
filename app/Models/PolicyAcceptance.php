<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PolicyAcceptance extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Policy acknowledgments are immutable.'));
        static::deleting(fn () => throw new LogicException('Policy acknowledgments are immutable.'));
    }

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function policyVersion()
    {
        return $this->belongsTo(PolicyVersion::class);
    }
}
