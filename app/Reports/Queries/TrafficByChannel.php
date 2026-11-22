<?php

declare(strict_types=1);

namespace App\Reports\Queries;

use App\Contracts\Ga4Client;
use App\Models\Property;
use App\Reports\Ga4Rows;
use App\Support\Ga4Report;
use App\Support\ReportPeriod;

/**
 * Sessions and users by default channel group: the top N channels by current sessions (config
 * report.top.channels) and an "Other" row that sums the rest.
 *
 * share is the channel's percent of all sessions in that range, shareChange its move in percentage points.
 * otherChannels names the channels that were folded into "Other" (current period), so text can say what the
 * remaining share came from.
 *
 * Output: ['total' => ['sessions' => metric], 'otherChannels' => [['channel', 'sessions', 'share']], 'rows' => [['channel', 'isOther', 'share', 'shareChange', 'sessions' => metric, 'totalUsers' => metric,
 *   'averageSessionDuration' => metric (seconds), 'engagementRate' => metric (fraction)]; for "Other" both are
 *   sessions-weighted averages of the rest], ...]]
 */
final class TrafficByChannel
{
    public function __construct(private readonly Ga4Client $ga4)
    {
    }

    /** @return array<string, float> sessions-weighted average of a metric per range */
    private static function weighted(array $groups, array $names, string $metric): array
    {
        $out = [];
        foreach ($names as $range) {
            $sessions = $weighted = 0.0;
            foreach ($groups as $group) {
                $s = Ga4Rows::value($group, $range, 'sessions');
                $sessions += $s;
                $weighted += $s * Ga4Rows::value($group, $range, $metric);
            }
            $out[$range] = $sessions > 0 ? $weighted / $sessions : 0.0;
        }

        return $out;
    }

    public function fetch(Property $property, ReportPeriod $period): array
    {
        $ranges = Ga4Rows::ranges($period);
        $names = array_keys($ranges);

        $result = $this->ga4->runReport($property, new Ga4Report(
            'channels',
            $ranges,
            ['sessionDefaultChannelGroup'],
            ['sessions', 'totalUsers', 'averageSessionDuration', 'engagementRate'],
            null,
            [['field' => 'sessions', 'metric' => true, 'desc' => true]],
        ));

        $groups = Ga4Rows::sortByCurrent(Ga4Rows::group($result, ['sessionDefaultChannelGroup']), 'sessions');
        $top = array_slice($groups, 0, Ga4Rows::topN('channels', $period), true);
        $rest = array_diff_key($groups, $top);
        $totals = Ga4Rows::sum($groups, $names, 'sessions');
        $shares = function (array $sessionsByRange) use ($totals): array {
            $out = [];
            foreach ($sessionsByRange as $range => $value) {
                $out[$range] = $totals[$range] > 0 ? round($value / $totals[$range] * 100, 2) : null;
            }

            return $out;
        };

        $rows = [];
        foreach ($top as $group) {
            $rows[] = [
                'channel' => $group['dimensions']['sessionDefaultChannelGroup'],
                'isOther' => false,
                'share' => $share = $shares(array_combine($names, array_map(fn ($r) => Ga4Rows::value($group, $r, 'sessions'), $names))),
                'shareChange' => self::shareChange($share),
                'sessions' => Ga4Rows::groupMetric($group, $names, 'sessions'),
                'totalUsers' => Ga4Rows::groupMetric($group, $names, 'totalUsers'),
                'averageSessionDuration' => Ga4Rows::groupMetric($group, $names, 'averageSessionDuration'),
                'engagementRate' => Ga4Rows::groupMetric($group, $names, 'engagementRate'),
            ];
        }

        if ($rest !== []) {
            $rows[] = [
                'channel' => 'Other',
                'isOther' => true,
                'share' => $share = $shares(Ga4Rows::sum($rest, $names, 'sessions')),
                'shareChange' => self::shareChange($share),
                'sessions' => \App\Support\Compare::metric(Ga4Rows::sum($rest, $names, 'sessions')),
                'totalUsers' => \App\Support\Compare::metric(Ga4Rows::sum($rest, $names, 'totalUsers')),
                'averageSessionDuration' => \App\Support\Compare::metric(self::weighted($rest, $names, 'averageSessionDuration')),
                'engagementRate' => \App\Support\Compare::metric(self::weighted($rest, $names, 'engagementRate')),
            ];
        }

        $others = [];
        foreach ($rest as $group) {
            $sessions = Ga4Rows::value($group, 'current', 'sessions');
            if ($sessions > 0) {
                $others[] = [
                    'channel' => $group['dimensions']['sessionDefaultChannelGroup'],
                    'sessions' => $sessions,
                    'share' => $totals['current'] > 0 ? round($sessions / $totals['current'] * 100, 2) : null,
                ];
            }
        }

        return [
            'total' => ['sessions' => \App\Support\Compare::metric($totals)],
            'otherChannels' => $others,
            'rows' => $rows,
        ];
    }

    /** @param  array<string, float|null>  $share */
    private static function shareChange(array $share): array
    {
        $change = ['previous' => \App\Support\Compare::points($share['current'] ?? null, $share['previous'] ?? null)];
        if (array_key_exists('yearAgo', $share)) {
            $change['yearAgo'] = \App\Support\Compare::points($share['current'] ?? null, $share['yearAgo']);
        }

        return $change;
    }
}
