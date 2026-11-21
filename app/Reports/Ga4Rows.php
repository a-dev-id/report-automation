<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\ReportType;
use App\Support\Compare;
use App\Support\ReportPeriod;

/** Helpers shared by the GA4 query classes. Pure functions on the Ga4Client result format. */
final class Ga4Rows
{
    /** Date ranges for the report, named current, previous and (monthly only) yearAgo. */
    public static function ranges(ReportPeriod $period): array
    {
        return array_filter($period->dateRanges(), fn ($range) => $range !== null);
    }

    /** Range names to compare: current, previous, plus yearAgo for monthly reports. */
    public static function rangeNames(ReportPeriod $period): array
    {
        return array_keys(self::ranges($period));
    }

    /** First row's value of a metric in a range without dimensions; 0.0 if GA4 returned no row. */
    public static function total(array $result, string $range, string $metric): float
    {
        return (float) ($result[$range][0]['metrics'][$metric] ?? 0.0);
    }

    /**
     * A metric over all ranges of a result without dimensions, as a Compare::metric() array.
     *
     * @param  list<string>  $rangeNames
     */
    public static function totalMetric(array $result, array $rangeNames, string $metric, bool $lowerIsBetter = false): array
    {
        $values = [];
        foreach ($rangeNames as $range) {
            $values[$range] = self::total($result, $range, $metric);
        }

        return Compare::metric($values, $lowerIsBetter);
    }

    /**
     * Groups rows by their dimension values.
     *
     * @param  list<string>  $dimensions
     * @return array<string, array{dimensions: array<string, string>, ranges: array<string, array<string, float>>}>
     */
    public static function group(array $result, array $dimensions): array
    {
        $groups = [];
        foreach ($result as $range => $rows) {
            foreach ($rows as $row) {
                $values = [];
                foreach ($dimensions as $name) {
                    $values[$name] = (string) ($row['dimensions'][$name] ?? '');
                }
                $key = implode("\x1f", $values);
                $groups[$key] ??= ['dimensions' => $values, 'ranges' => []];
                $groups[$key]['ranges'][$range] = $row['metrics'];
            }
        }

        return $groups;
    }

    /** A metric of a group in a range; 0.0 if the group has no row there. */
    public static function value(array $group, string $range, string $metric): float
    {
        return (float) ($group['ranges'][$range][$metric] ?? 0.0);
    }

    /** Sorts groups by a metric in the current range, then the previous range, highest first. */
    public static function sortByCurrent(array $groups, string $metric): array
    {
        uasort($groups, fn (array $a, array $b) => [self::value($b, 'current', $metric), self::value($b, 'previous', $metric)]
            <=> [self::value($a, 'current', $metric), self::value($a, 'previous', $metric)]);

        return $groups;
    }

    /**
     * Compare::metric() for one metric of a group.
     *
     * @param  list<string>  $rangeNames
     */
    public static function groupMetric(array $group, array $rangeNames, string $metric, bool $lowerIsBetter = false): array
    {
        $values = [];
        foreach ($rangeNames as $range) {
            $values[$range] = self::value($group, $range, $metric);
        }

        return Compare::metric($values, $lowerIsBetter);
    }

    /**
     * Sum of a metric over groups, per range.
     *
     * @param  list<string>  $rangeNames
     * @return array<string, float>
     */
    public static function sum(array $groups, array $rangeNames, string $metric): array
    {
        $sums = array_fill_keys($rangeNames, 0.0);
        foreach ($groups as $group) {
            foreach ($rangeNames as $range) {
                $sums[$range] += self::value($group, $range, $metric);
            }
        }

        return $sums;
    }

    /** Value from a [weekly, monthly] config pair for this report type. */
    public static function topN(string $configKey, ReportPeriod $period): int
    {
        return (int) config("report.top.{$configKey}")[$period->type === ReportType::Monthly ? 1 : 0];
    }
}
