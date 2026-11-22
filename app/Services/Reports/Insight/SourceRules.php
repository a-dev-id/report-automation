<?php

declare(strict_types=1);

namespace App\Services\Reports\Insight;

/**
 * "Where visitors came from" paragraph, from payload.channels.
 *
 *   overview            the largest sources with their share of sessions, and what the remaining share came from
 *   dominantLowQuality  one channel is more than dominant_channel.min_share_percent of sessions AND its engagement is below
 *                       dominant_channel.max_engagement_percent or its average session is shorter than the bot threshold
 *                       (traffic_quality.bot_check.max_avg_session_seconds): likely automated or low-quality traffic
 *   shareMoves          a channel's share moved by more than share_move_points
 */
final class SourceRules
{
    /** @return list<Finding> */
    public function findings(InsightContext $c): array
    {
        $rows = $this->rows($c);
        if ($rows === []) {
            return [];
        }

        $out = array_filter([$this->overview($c, $rows)]);
        foreach (['dominantLowQuality', 'shareMoves'] as $rule) {
            $found = $this->{$rule}($c, $rows);
            foreach ($found === null ? [] : (is_array($found) ? $found : [$found]) as $finding) {
                $out[] = $finding->withRule($rule);
            }
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> channel rows without the "Other" bucket, biggest first */
    public function rows(InsightContext $c): array
    {
        $channels = $c->section('channels');

        return array_values(array_filter($channels['rows'] ?? [], fn (array $r) => ! ($r['isOther'] ?? false) && ($r['share']['current'] ?? null) !== null));
    }

    public function overview(InsightContext $c, array $rows): ?Finding
    {
        $top = $rows[0];
        $text = $top['channel'].' was the largest source at '.$c->value('pct', $top['share']['current']).' of sessions';

        $text .= $c->dir($top['sessions'] ?? null) === 'guarded' || ($top['sessions']['change']['direction'] ?? 'none') === 'none'
            ? ''
            : ' ('.$c->change($top['sessions']['change']).' in sessions)';

        $next = array_slice($rows, 1, 2);
        if ($next !== []) {
            $text .= ', followed by '.$c->join(array_map(fn (array $r) => $r['channel'].' ('.$c->value('pct', $r['share']['current']).')', $next));
        }
        $text .= '.';

        // What the rest came from: the channels after the first three, then the ones folded into "Other".
        $channels = $c->section('channels');
        $named = array_merge(
            array_map(fn (array $r) => ['channel' => $r['channel'], 'share' => $r['share']['current']], array_slice($rows, 3)),
            array_map(fn (array $r) => ['channel' => $r['channel'], 'share' => $r['share']], (array) ($channels['otherChannels'] ?? [])),
        );
        $named = array_values(array_filter($named, fn (array $r) => ($r['share'] ?? 0) > 0));
        $remaining = array_sum(array_column($named, 'share'));

        if ($named !== [] && $remaining > 0) {
            $names = array_slice(array_column($named, 'channel'), 0, 3);
            $text .= ' The remaining '.$c->value('pct', $remaining).' came from '
                .(count($named) > 3 ? implode(', ', $names).' and smaller channels' : $c->join($names)).'.';
        }

        return new Finding($text, weight: 100, essential: true, rule: 'overview');
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function dominantLowQuality(InsightContext $c, array $rows): ?Finding
    {
        $minShare = (float) $c->config('dominant_channel.min_share_percent', 40);
        $maxEngagement = (float) $c->config('dominant_channel.max_engagement_percent', 25);
        $botSeconds = (float) config('report.traffic_quality.bot_check.max_avg_session_seconds', 5.0);

        foreach ($rows as $row) {
            $share = (float) $row['share']['current'];
            if ($share <= $minShare) {
                continue;
            }

            $engagement = $row['engagementRate']['current'] ?? null;
            $duration = $row['averageSessionDuration']['current'] ?? null;
            $lowEngagement = $engagement !== null && (float) $engagement * 100 < $maxEngagement;
            $shortVisits = $duration !== null && (float) $duration < $botSeconds;

            if (! $lowEngagement && ! $shortVisits) {
                continue;
            }

            return new Finding(
                $row['channel'].' is '.$c->value('pct', $share).' of sessions, but only '.$c->value('rate', $engagement)
                .' of those visits were engaged (average visit '.$c->value('dur', $duration).'). This is likely automated or low-quality traffic '
                .'that inflates sessions and lowers averages, so do not read it as demand.',
                weight: 98,
                action: 'Check bot and referral filtering in GA4.',
                actionPriority: 1,
            );
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<Finding>
     */
    public function shareMoves(InsightContext $c, array $rows): array
    {
        $threshold = (float) $c->config('share_move_points', 10);
        $movers = [];
        foreach ($rows as $row) {
            $points = $row['shareChange']['previous']['points'] ?? null;
            if ($points !== null && abs((float) $points) > $threshold) {
                $movers[] = ['row' => $row, 'points' => (float) $points];
            }
        }
        usort($movers, fn (array $a, array $b) => abs($b['points']) <=> abs($a['points']));

        $out = [];
        foreach (array_slice($movers, 0, 2) as $m) {
            $out[] = new Finding(
                $m['row']['channel']."'s share of sessions moved from ".$c->value('pct', $m['row']['share']['previous'] ?? null).' to '.$c->value('pct', $m['row']['share']['current'])
                .' ('.$c->change(['direction' => $m['points'] > 0 ? 'up' : 'down', 'percent' => 0, 'improved' => null, 'difference' => $m['points'] / 100], null, true).').',
                weight: 70,
            );
        }

        return $out;
    }
}
