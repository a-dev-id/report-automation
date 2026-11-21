<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Period-over-period comparison rules, in one place.
 *
 * direction is one of:
 *   up, down, flat : both values exist and the previous one is not zero. flat means the change
 *                    rounds to 0.0 percent.
 *   new            : there is no previous value (missing or zero) but the current value is above zero.
 *                    A percent change would be infinite, so percent is null.
 *   none           : there is nothing to compare: the current value is missing, or both are zero.
 *
 * "improved" says whether the move is good news. It is true or false for up/down (inverted when
 * lower is better, as for Search Console average position) and null for flat, new and none.
 */
final class Compare
{
    /**
     * @return array{difference: float|null, percent: float|null, direction: string, improved: bool|null}
     */
    public static function change(int|float|null $current, int|float|null $previous, bool $lowerIsBetter = false): array
    {
        if ($current === null) {
            return self::result(null, null, 'none', null);
        }

        $difference = $previous === null ? null : round((float) $current - (float) $previous, 6);

        if ($previous === null || (float) $previous === 0.0) {
            return self::result($difference, null, (float) $current > 0.0 ? 'new' : 'none', null);
        }

        $percent = round(((float) $current - (float) $previous) / abs((float) $previous) * 100, 1);

        if ($percent === 0.0) {
            return self::result($difference, 0.0, 'flat', null);
        }

        $up = $percent > 0;

        return self::result($difference, $percent, $up ? 'up' : 'down', $up !== $lowerIsBetter);
    }

    /**
     * Change of a share, in percentage points (a share going from 40% to 45% is +5.0 points).
     *
     * @return array{points: float|null, direction: string}
     */
    public static function points(int|float|null $current, int|float|null $previous): array
    {
        if ($current === null || $previous === null) {
            return ['points' => null, 'direction' => 'none'];
        }

        $points = round((float) $current - (float) $previous, 1);

        return ['points' => $points, 'direction' => $points > 0 ? 'up' : ($points < 0 ? 'down' : 'flat')];
    }

    /**
     * One metric with its comparisons. yearAgo keys are present only when $values has a "yearAgo" key
     * (monthly reports), even if that value is null.
     *
     * @param  array{current: int|float|null, previous: int|float|null, yearAgo?: int|float|null}  $values
     * @return array<string, mixed>
     */
    public static function metric(array $values, bool $lowerIsBetter = false): array
    {
        $out = [
            'current' => $values['current'],
            'previous' => $values['previous'],
            'change' => self::change($values['current'], $values['previous'], $lowerIsBetter),
        ];

        if (array_key_exists('yearAgo', $values)) {
            $out['yearAgo'] = $values['yearAgo'];
            $out['yearAgoChange'] = self::change($values['current'], $values['yearAgo'], $lowerIsBetter);
        }

        return $out;
    }

    /** @return array{difference: float|null, percent: float|null, direction: string, improved: bool|null} */
    private static function result(?float $difference, ?float $percent, string $direction, ?bool $improved): array
    {
        return ['difference' => $difference, 'percent' => $percent, 'direction' => $direction, 'improved' => $improved];
    }
}
