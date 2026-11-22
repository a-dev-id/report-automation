<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Number, percentage, currency and change formatting shared by the insight text and both email versions,
 * so the same figure is always written the same way.
 *
 * Change styling: a positive change is green and a negative change is red, always together with an arrow and an explicit
 * + or - sign so meaning never depends on color. Zero is gray, "new" is blue-gray. Metrics listed in
 * config report.styling.neutral_metrics (ad cost, cost per click) are gray whatever the direction. A metric where lower is
 * better (Search Console position) is inverted by Compare::change(): a drop in the number is "improved", so green.
 */
final class ReportFormat
{
    public const GREEN = '#15803D';

    public const RED = '#B91C1C';

    public const GRAY = '#6B7280';

    public const NEW = '#475569';

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

    /** True when this metric key is listed in config report.styling.neutral_metrics. */
    public static function isNeutral(?string $metric): bool
    {
        return $metric !== null && in_array($metric, (array) config('report.styling.neutral_metrics', []), true);
    }

    /**
     * Text, color and arrow for a Compare::change() result. kind is the style class: pos, neg, zero, new, neutral or none.
     *
     * @param  array<string, mixed>|null  $change
     * @param  string|null  $metric  the metric's key, to apply report.styling.neutral_metrics
     * @return array{text: string, arrow: string, color: string, direction: string, kind: string}
     */
    public static function change(?array $change, ?string $metric = null): array
    {
        $direction = (string) ($change['direction'] ?? 'none');
        $percent = $change['percent'] ?? null;

        return match ($direction) {
            'up' => self::styled('+'.self::percent($percent), '▲', 'up', $change, $metric),
            'down' => self::styled(self::percent($percent), '▼', 'down', $change, $metric),
            'flat' => self::result('0.0%', '▬', self::GRAY, 'flat', 'zero'),
            'new' => self::result('New', '▲', self::NEW, 'new', 'new'),
            default => self::result('n/a', '', self::GRAY, 'none', 'none'),
        };
    }

    /**
     * Change of a rate in percentage points, from a Compare::change() on fractions (difference = fraction diff).
     *
     * @param  array<string, mixed>|null  $change
     * @return array{text: string, arrow: string, color: string, direction: string, kind: string}
     */
    public static function rateChange(?array $change, ?string $metric = null): array
    {
        $difference = $change['difference'] ?? null;
        $direction = (string) ($change['direction'] ?? 'none');

        if ($difference === null || ! in_array($direction, ['up', 'down', 'flat'], true)) {
            return self::change($change, $metric);
        }

        $points = round((float) $difference * 100, 1);
        if ($points === 0.0) {
            return self::change(['direction' => 'flat']);
        }

        return $points > 0
            ? self::styled('+'.number_format($points, 1).' pts', '▲', 'up', $change, $metric)
            : self::styled(number_format($points, 1).' pts', '▼', 'down', $change, $metric);
    }

    /**
     * @param  array<string, mixed>|null  $change
     * @return array{text: string, arrow: string, color: string, direction: string, kind: string}
     */
    private static function styled(string $text, string $arrow, string $direction, ?array $change, ?string $metric): array
    {
        if (self::isNeutral($metric)) {
            return self::result($text, $arrow, self::GRAY, $direction, 'neutral');
        }

        return match ($change['improved'] ?? null) {
            true => self::result($text, $arrow, self::GREEN, $direction, 'pos'),
            false => self::result($text, $arrow, self::RED, $direction, 'neg'),
            default => self::result($text, $arrow, self::GRAY, $direction, 'zero'),
        };
    }

    /** @return array{text: string, arrow: string, color: string, direction: string, kind: string} */
    private static function result(string $text, string $arrow, string $color, string $direction, string $kind): array
    {
        return ['text' => $text, 'arrow' => $arrow, 'color' => $color, 'direction' => $direction, 'kind' => $kind];
    }
}
