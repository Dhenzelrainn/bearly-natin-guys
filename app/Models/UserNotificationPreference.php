<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotificationPreference extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'event_key', 'in_app', 'email'];

    protected function casts(): array
    {
        return [
            'in_app' => 'boolean',
            'email' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
