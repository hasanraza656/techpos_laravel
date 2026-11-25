<?php

namespace App\Domain\Operations;

use Illuminate\Validation\ValidationException;

final class Decimal
{
    public static function input(mixed $value, int $scale = 2, bool $signed = false): string
    {
        if (! is_string($value) || ! preg_match($signed ? '/^-?\d{1,10}(?:\.\d{1,'.$scale.'})?$/' : '/^\d{1,10}(?:\.\d{1,'.$scale.'})?$/', $value)) {
            throw ValidationException::withMessages(['decimal' => 'Use a decimal string with at most '.$scale.' decimal places.']);
        }

        return bcadd($value, '0', $scale);
    }

    public static function round(string $value, int $scale = 2): string
    {
        return bcadd($value, bccomp($value, '0', 12) < 0 ? '-0.'.str_repeat('0', $scale).'5' : '0.'.str_repeat('0', $scale).'5', $scale);
    }

    public static function sum(iterable $values, int $scale = 2): string
    {
        $sum = bcadd('0', '0', $scale);
        foreach ($values as $value) {
            $sum = bcadd($sum, (string) $value, $scale);
        }

        return $sum;
    }

    public static function multiply(string $a, string $b, int $scale = 2): string
    {
        return self::round(bcmul($a, $b, 12), $scale);
    }

    public static function min(string $a, string $b, int $scale = 2): string
    {
        return bcadd(bccomp($a, $b, $scale) <= 0 ? $a : $b, '0', $scale);
    }

    public static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
