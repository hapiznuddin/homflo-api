<?php

namespace App\Services\Finance;

/**
 * Exact decimal arithmetic for money in integer cents.
 *
 * Database NUMERIC values and validated 2dp strings convert losslessly;
 * PHP floats are never involved, so ledger math cannot drift.
 */
final class LedgerMath
{
    /**
     * Parse an exact decimal string (e.g. "150000.50", "-10.5", "7")
     * into integer cents.
     */
    public static function toCents(string $decimal): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', trim($decimal), $matches)) {
            throw new \InvalidArgumentException("Invalid decimal string: {$decimal}");
        }

        $cents = ((int) $matches[2]) * 100 + (int) str_pad($matches[3] ?? '', 2, '0');

        return $matches[1] === '-' ? -$cents : $cents;
    }

    /**
     * Format integer cents back to an exact 2dp decimal string.
     */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);
    }

    public static function add(string $a, string $b): string
    {
        return self::fromCents(self::toCents($a) + self::toCents($b));
    }

    public static function sub(string $a, string $b): string
    {
        return self::fromCents(self::toCents($a) - self::toCents($b));
    }

    /**
     * @return -1|0|1
     */
    public static function compare(string $a, string $b): int
    {
        return self::toCents($a) <=> self::toCents($b);
    }

    public static function isNegative(string $decimal): bool
    {
        return self::toCents($decimal) < 0;
    }

    public static function isZero(string $decimal): bool
    {
        return self::toCents($decimal) === 0;
    }
}
