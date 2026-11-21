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
 * Countries ranked by ENGAGED sessions (GA4 engagedSessions): top 8 (monthly: top 10, config report.top.countries)
 * plus "Other". Sessions stay in each row because share, the sum check and the table still use them.
 *
 * share is the country's percent of all sessions in that range. shareChange is in percentage
 * points (current minus previous, and minus yearAgo for monthly reports).
 *
 * sessionsSum is the sum of all country rows (current period), sessionsTotal the site total for the same period
 * (passed in from SiteTotals) and warning is true when the sum exceeds the total by more than
 * report.quality_alerts.country_sum_tolerance_percent. GA4 counts a session in every country it was seen in,
 * so proxy or bot traffic can make the rows add up to more than the real total. The rows are left as GA4 gives them.
 *
 * largestBySessions and engagedLeader are taken over ALL countries (not just the rows shown), "(not set)" excluded, so
 * text that names "the largest country by sessions" is true even when that country is not among the top rows.
 *
 * Output: ['total' => ['sessions' => metric], 'sessionsSum', 'sessionsTotal', 'warning',
 *   'largestBySessions' => ['country', 'sessions']|null, 'engagedLeader' => ['country', 'engagedSessions']|null, 'rows' => [[
 *   'country', 'isOther', 'sessions' => metric, 'engagedSessions' => metric,
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
            ['sessions', 'engagedSessions'],
            null,
            [['field' => 'engagedSessions', 'metric' => true, 'desc' => true]],
        ));

        $groups = Ga4Rows::sortByCurrent(Ga4Rows::group($result, ['country']), 'engagedSessions');
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
                array_combine($names, array_map(fn ($r) => Ga4Rows::value($group, $r, 'engagedSessions'), $names)),
            );
        }
        if ($rest !== []) {
            $rows[] = $this->row('Other', true, Ga4Rows::sum($rest, $names, 'sessions'), $totals, Ga4Rows::sum($rest, $names, 'engagedSessions'));
        }

        $named = array_filter($groups, fn (array $g) => $g['dimensions']['country'] !== '(not set)');
        $largest = null;
        foreach ($named as $g) {
            if ($largest === null || Ga4Rows::value($g, 'current', 'sessions') > Ga4Rows::value($largest, 'current', 'sessions')) {
                $largest = $g;
            }
        }
        $leader = $named === [] ? null : reset($named);   // $groups is already ranked by engaged sessions

        $sum = $totals['current'];
        $siteTotal = $siteSessions['current'] ?? null;
        $tolerance = (float) config('report.quality_alerts.country_sum_tolerance_percent');

        return [
            'total' => ['sessions' => Compare::metric($totals)],
            'sessionsSum' => $sum,
            'sessionsTotal' => $siteTotal,
            'warning' => $siteTotal !== null && $sum > $siteTotal * (1 + $tolerance / 100),
            'largestBySessions' => $largest === null ? null : ['country' => $largest['dimensions']['country'], 'sessions' => Ga4Rows::value($largest, 'current', 'sessions')],
            'engagedLeader' => $leader === null ? null : ['country' => $leader['dimensions']['country'], 'engagedSessions' => Ga4Rows::value($leader, 'current', 'engagedSessions')],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, float>  $sessions  by range
     * @param  array<string, float>  $totals  by range
     * @param  array<string, float>  $engaged  by range
     */
    private function row(string $country, bool $isOther, array $sessions, array $totals, array $engaged): array
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
            'engagedSessions' => Compare::metric($engaged),
            'share' => $share,
            'shareChange' => $shareChange,
        ];
    }
}
