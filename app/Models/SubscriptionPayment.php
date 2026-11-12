<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionPayment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['reference_fingerprint'];

    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date:Y-m-d', 'expires_at' => 'datetime', 'applied_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->public_id ??= (string) Str::uuid());
    }
}
