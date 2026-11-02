<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationDraft extends Model
{
    protected $fillable = [
        'flow',
        'browser_token_hash',
        'payload',
        'step',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'step' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
