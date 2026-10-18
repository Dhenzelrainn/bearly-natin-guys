<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnouncementMedia extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'position' => 'integer',
        ];
    }

    public function announcement()
    {
        return $this->belongsTo(Announcement::class);
    }
}
