<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['limits' => 'array', 'active' => 'boolean'];

    public function prices()
    {
        return $this->hasMany(PlanPrice::class);
    }
}
