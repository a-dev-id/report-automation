<?php

declare(strict_types=1);

namespace App\Reports\Queries;

use App\Contracts\Ga4Client;
use App\Models\Property;
use App\Reports\Ga4Rows;
use App\Support\Compare;
use App\Support\Ga4Report;
use App\Support\ReportPeriod;

/**
 * Sessions by country: top 8 (monthly: top 10, config report.top.countries) plus "Other".
 *
 * share is the country's percent of all sessions in that range. shareChange is in percentage
 * points (current minus previous, and minus yearAgo for monthly reports).
 *
 * sessionsSum is the sum of all country rows (current period), sessionsTotal the site total for the same period
 * (passed in from SiteTotals) and warning is true when the sum exceeds the total by more than
 * report.quality_alerts.country_sum_tolerance_percent. GA4 counts a session in every country it was seen in,
 * so proxy or bot traffic can make the rows add up to more than the real total. The rows are left as GA4 gives them.
 *
 * Output: ['total' => ['sessions' => metric], 'sessionsSum', 'sessionsTotal', 'warning', 'rows' => [[
 *   'country', 'isOther', 'sessions' => metric,
 *   'share' => ['current', 'previous', ('yearAgo')], 'shareChange' => ['previous' => points, ('yearAgo' => points)]
 * ], ...]]
 */
final class TrafficByCountry
{
    public function __construct(private readonly Ga4Client $ga4)
    {
    }

    /** @param  array{current: float|null}|null  $siteSessions  the site.sessions metric */
    public function fetch(Property $property, ReportPeriod $period, ?array $siteSessions = null): array
    {
        $ranges = Ga4Rows::ranges($period);
        $names = array_keys($ranges);

        $result = $this->ga4->runReport($property, new Ga4Report(
            'countries',
            $ranges,
            ['country'],
            ['sessions'],
            null,
            [['field' => 'sessions', 'metric' => true, 'desc' => true]],
        ));

        $groups = Ga4Rows::sortByCurrent(Ga4Rows::group($result, ['country']), 'sessions');
        $totals = Ga4Rows::sum($groups, $names, 'sessions');
        $top = array_slice($groups, 0, Ga4Rows::topN('countries', $period), true);
        $rest = array_diff_key($groups, $top);

        $rows = [];
        foreach ($top as $group) {
            $rows[] = $this->row(
                $group['dimensions']['country'],
                false,
                array_combine($names, array_map(fn ($r) => Ga4Rows::value($group, $r, 'sessions'), $names)),
                $totals,
            );
        }
        if ($rest !== []) {
            $rows[] = $this->row('Other', true, Ga4Rows::sum($rest, $names, 'sessions'), $totals);
        }

        $sum = $totals['current'];
        $siteTotal = $siteSessions['current'] ?? null;
        $tolerance = (float) config('report.quality_alerts.country_sum_tolerance_percent');

        return [
            'total' => ['sessions' => Compare::metric($totals)],
            'sessionsSum' => $sum,
            'sessionsTotal' => $siteTotal,
            'warning' => $siteTotal !== null && $sum > $siteTotal * (1 + $tolerance / 100),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, float>  $sessions  by range
     * @param  array<string, float>  $totals  by range
     */
    private function row(string $country, bool $isOther, array $sessions, array $totals): array
    {
        $share = [];
        foreach ($sessions as $range => $value) {
            $share[$range] = $totals[$range] > 0 ? round($value / $totals[$range] * 100, 2) : null;
        }

        $shareChange = ['previous' => Compare::points($share['current'], $share['previous'])];
        if (array_key_exists('yearAgo', $share)) {
            $shareChange['yearAgo'] = Compare::points($share['current'], $share['yearAgo']);
        }

        return [
            'country' => $country,
            'isOther' => $isOther,
            'sessions' => Compare::metric($sessions),
            'share' => $share,
            'shareChange' => $shareChange,
        ];
    }
}
