<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = ['settings' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->public_id ??= (string) Str::uuid());
    }
}
