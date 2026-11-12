<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

final class TwoFactor
{
    public function totp(): Google2FA
    {
        return new Google2FA;
    }

    // Call only while holding this user's DB row lock, including recovery code use.
    public function consume(User $user, string $code): void
    {
        $normalized = str_replace(['-', ' '], '', trim($code));
        if (preg_match('/^[0-9]{6}$/', $normalized)) {
            $counter = $this->totp()->verifyKeyNewer($user->two_factor_secret, $normalized, $user->two_factor_last_counter ?? 0, 1, (int) floor(now()->timestamp / 30));
            if ($counter !== false) {
                $user->forceFill(['two_factor_last_counter' => $counter])->save();

                return;
            }
        } else {
            $hash = hash('sha256', strtolower($normalized));
            $codes = $user->two_factor_recovery_codes ?? [];
            foreach ($codes as $index => $stored) {
                if (hash_equals($stored, $hash)) {
                    unset($codes[$index]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                    return;
                }
            }
        }
        throw ValidationException::withMessages(['code' => 'The code is invalid or has already been used. Wait for a new authenticator code, or use an unused recovery code.']);
    }

    public function recoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = implode('-', str_split(bin2hex(random_bytes(16)), 8));
        }
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($code) => hash('sha256', str_replace('-', '', $code)), $codes)])->save();

        return $codes;
    }
}
