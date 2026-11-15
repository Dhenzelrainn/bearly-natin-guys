<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnEvidence extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
