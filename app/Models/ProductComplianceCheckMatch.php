<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductComplianceCheckMatch extends Model
{
    protected $guarded = [];

    public function check()
    {
        return $this->belongsTo(ProductComplianceCheck::class);
    }

    public function rule()
    {
        return $this->belongsTo(ComplianceRule::class);
    }
}
