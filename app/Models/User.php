<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
    protected $attributes = ['status' => 'active'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_counter', 'two_factor_setup_at'];

    protected $casts = ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_superadmin' => 'boolean', 'two_factor_secret' => 'encrypted', 'two_factor_recovery_codes' => 'encrypted:array', 'two_factor_confirmed_at' => 'datetime', 'two_factor_setup_at' => 'datetime', 'two_factor_last_counter' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->public_id ??= (string) Str::uuid());
    }
}
