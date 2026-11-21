<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Number, percentage, currency and change formatting shared by the insight text and both email versions,
 * so the same figure is always written the same way.
 */
final class ReportFormat
{
    public const GREEN = '#1E8E3E';

    public const RED = '#C5221F';

    public const GRAY = '#6B7280';

    public static function number(int|float|null $value, int $decimals = 0): string
    {
        return $value === null ? '–' : number_format((float) $value, $decimals, '.', ',');
    }

    /** A percentage that is already in percent (25.0 -> "25.0%"). */
    public static function percent(int|float|null $value, int $decimals = 1): string
    {
        return $value === null ? '–' : number_format((float) $value, $decimals, '.', ',').'%';
    }

    /** A fraction (0.025 -> "2.5%"). */
    public static function rate(int|float|null $fraction, int $decimals = 1): string
    {
        return $fraction === null ? '–' : self::percent((float) $fraction * 100, $decimals);
    }

    /** "IDR 136,830,577" (no decimals for IDR, JPY, VND, KRW), "USD 1,234.50" for other currencies. */
    public static function money(int|float|null $value, ?string $currency): string
    {
        if ($value === null) {
            return '–';
        }

        $currency = strtoupper(trim((string) $currency));
        $decimals = in_array($currency, ['IDR', 'JPY', 'VND', 'KRW'], true) ? 0 : 2;

        return trim($currency.' '.number_format((float) $value, $decimals, '.', ','));
    }

    /** Seconds as "1m 34s" (or "34s"). */
    public static function duration(int|float|null $seconds): string
    {
        if ($seconds === null) {
            return '–';
        }

        $total = (int) round((float) $seconds);

        return $total >= 60 ? intdiv($total, 60).'m '.str_pad((string) ($total % 60), 2, '0', STR_PAD_LEFT).'s' : $total.'s';
    }

    /**
     * Text, color and arrow for a Compare::change() result.
     *
     * @param  array<string, mixed>|null  $change
     * @return array{text: string, arrow: string, color: string, direction: string}
     */
    public static function change(?array $change): array
    {
        $direction = (string) ($change['direction'] ?? 'none');
        $percent = $change['percent'] ?? null;

        $color = match ($change['improved'] ?? null) {
            true => self::GREEN,
            false => self::RED,
            default => self::GRAY,
        };

        return match ($direction) {
            'up' => ['text' => '+'.self::percent($percent), 'arrow' => '▲', 'color' => $color, 'direction' => 'up'],
            'down' => ['text' => self::percent($percent), 'arrow' => '▼', 'color' => $color, 'direction' => 'down'],
            'flat' => ['text' => '0.0%', 'arrow' => '▬', 'color' => self::GRAY, 'direction' => 'flat'],
            'new' => ['text' => 'new', 'arrow' => '▲', 'color' => self::GRAY, 'direction' => 'new'],
            default => ['text' => 'n/a', 'arrow' => '', 'color' => self::GRAY, 'direction' => 'none'],
        };
    }

    /**
     * Change of a rate in percentage points, from a Compare::change() on fractions (difference = fraction diff).
     *
     * @param  array<string, mixed>|null  $change
     * @return array{text: string, arrow: string, color: string, direction: string}
     */
    public static function rateChange(?array $change): array
    {
        $difference = $change['difference'] ?? null;
        $direction = (string) ($change['direction'] ?? 'none');

        if ($difference === null || ! in_array($direction, ['up', 'down', 'flat'], true)) {
            return self::change($change);
        }

        $points = round((float) $difference * 100, 1);
        if ($points === 0.0) {
            return self::change(['direction' => 'flat']);
        }

        $color = match ($change['improved'] ?? null) {
            true => self::GREEN,
            false => self::RED,
            default => self::GRAY,
        };

        return $points > 0
            ? ['text' => '+'.number_format($points, 1).' pts', 'arrow' => '▲', 'color' => $color, 'direction' => 'up']
            : ['text' => number_format($points, 1).' pts', 'arrow' => '▼', 'color' => $color, 'direction' => 'down'];
    }
}
