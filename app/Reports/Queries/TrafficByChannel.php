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
 * Output: ['total' => ['sessions' => metric], 'rows' => [['channel', 'isOther', 'sessions' => metric, 'totalUsers' => metric,
 *   'averageSessionDuration' => metric (seconds; for "Other" the sessions-weighted average)], ...]]
 */
final class TrafficByChannel
{
    public function __construct(private readonly Ga4Client $ga4)
    {
    }

    /** @return array<string, float> sessions-weighted average session duration per range */
    private static function weightedDuration(array $groups, array $names): array
    {
        $out = [];
        foreach ($names as $range) {
            $sessions = $weighted = 0.0;
            foreach ($groups as $group) {
                $s = Ga4Rows::value($group, $range, 'sessions');
                $sessions += $s;
                $weighted += $s * Ga4Rows::value($group, $range, 'averageSessionDuration');
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
            ['sessions', 'totalUsers', 'averageSessionDuration'],
            null,
            [['field' => 'sessions', 'metric' => true, 'desc' => true]],
        ));

        $groups = Ga4Rows::sortByCurrent(Ga4Rows::group($result, ['sessionDefaultChannelGroup']), 'sessions');
        $top = array_slice($groups, 0, Ga4Rows::topN('channels', $period), true);
        $rest = array_diff_key($groups, $top);

        $rows = [];
        foreach ($top as $group) {
            $rows[] = [
                'channel' => $group['dimensions']['sessionDefaultChannelGroup'],
                'isOther' => false,
                'sessions' => Ga4Rows::groupMetric($group, $names, 'sessions'),
                'totalUsers' => Ga4Rows::groupMetric($group, $names, 'totalUsers'),
                'averageSessionDuration' => Ga4Rows::groupMetric($group, $names, 'averageSessionDuration'),
            ];
        }

        if ($rest !== []) {
            $rows[] = [
                'channel' => 'Other',
                'isOther' => true,
                'sessions' => \App\Support\Compare::metric(Ga4Rows::sum($rest, $names, 'sessions')),
                'totalUsers' => \App\Support\Compare::metric(Ga4Rows::sum($rest, $names, 'totalUsers')),
                'averageSessionDuration' => \App\Support\Compare::metric(self::weightedDuration($rest, $names)),
            ];
        }

        return [
            'total' => ['sessions' => \App\Support\Compare::metric(Ga4Rows::sum($groups, $names, 'sessions'))],
            'rows' => $rows,
        ];
    }
}
