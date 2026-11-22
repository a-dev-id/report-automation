<?php

declare(strict_types=1);

namespace App\Services\Reports\Insight;

/**
 * The one-sentence verdict that opens the insight ("Traffic declined this week: sessions fell -9.8% to 6,545, ..."). It looks at sessions, engagement and booking-engine visits TOGETHER, never one
 * metric alone:
 *
 *   healthy growth             sessions up, and engagement and booking visits are not worse
 *   growth with weaker quality sessions up, but engagement or booking visits are worse
 *   a decline                  sessions down, with no offsetting gain in engagement or booking visits
 *   stable                     sessions within +/- verdict.stable_percent and engagement and booking visits barely moved
 *   mixed                      anything else (for example fewer sessions but better engagement)
 *
 * "Worse" and "better" use verdict.engagement_points (points of engagement rate) and verdict.booking_percent. A metric held back
 * by the large-change guard is not used: if sessions themselves are guarded there is no verdict, only the value.
 */
final class Verdict
{
    /** How the headline opens for each verdict, with the period ("this week") added after it. */
    public const OPENINGS = [
        'healthy growth' => 'Traffic grew healthily',
        'growth with weaker quality' => 'Traffic grew, but quality weakened',
        'decline' => 'Traffic declined',
        'stable' => 'Traffic was stable',
        'mixed' => 'Traffic was mixed',
    ];

    /** @return array{key: string|null, headline: string} */
    public function evaluate(InsightContext $c): array
    {
        $site = $c->section('site');
        if ($site === null || ! isset($site['sessions']) || ($site['sessions']['current'] ?? null) === null) {
            return ['key' => null, 'headline' => $c->thisPeriod.': traffic figures are not available for this report.'];
        }

        $sessions = $site['sessions'];
        if ($c->dir($sessions, 'Sessions') === 'guarded') {
            return [
                'key' => null,
                'headline' => $c->thisPeriod.': sessions were '.$c->value('n', $sessions['current']).' (see the note below on the size of the change).',
            ];
        }

        $key = $this->key($c, $site);
        $facts = array_filter([
            'sessions '.$c->phrase($sessions, 'n', 'Sessions'),
            isset($site['engagementRate']) && ($e = $c->phrase($site['engagementRate'], 'rate', 'Engagement rate')) !== null ? "engagement {$e}" : null,
            isset($site['bookingEngineVisits']) && ($b = $c->phrase($site['bookingEngineVisits'], 'n', 'Booking-engine visits')) !== null ? "booking-engine visits {$b}" : null,
        ]);

        $headline = $key === null
            ? $c->thisPeriod.': '.$c->join($facts).'.'
            : self::OPENINGS[$key].' '.lcfirst($c->thisPeriod).': '.$c->join($facts).'.';

        return ['key' => $key, 'headline' => $headline];
    }

    /** @param  array<string, mixed>  $site */
    private function key(InsightContext $c, array $site): ?string
    {
        $stable = (float) $c->config('verdict.stable_percent', 5.0);
        $enough = (float) $c->config('verdict.engagement_points', 2.0);
        $bookingPercent = (float) $c->config('verdict.booking_percent', 5.0);

        $change = $site['sessions']['change'] ?? [];
        $direction = (string) ($change['direction'] ?? 'none');
        $percent = $change['percent'] ?? null;

        $up = $direction === 'new' || ($percent !== null && $percent > $stable);
        $down = $percent !== null && $percent < -$stable;

        $points = isset($site['engagementRate']) && $c->dir($site['engagementRate']) !== 'guarded' ? $c->points($site['engagementRate']) : null;
        $engagementWorse = $points !== null && $points <= -$enough;
        $engagementBetter = $points !== null && $points >= $enough;

        $bookingPercentChange = isset($site['bookingEngineVisits']) ? $c->percent($site['bookingEngineVisits']) : null;
        $bookingDirection = isset($site['bookingEngineVisits']) ? $c->dir($site['bookingEngineVisits']) : 'none';
        $bookingWorse = $bookingDirection === 'down' && $bookingPercentChange !== null && $bookingPercentChange <= -$bookingPercent;
        $bookingBetter = ($bookingDirection === 'up' && $bookingPercentChange !== null && $bookingPercentChange >= $bookingPercent) || $bookingDirection === 'new';

        $worse = $engagementWorse || $bookingWorse;
        $better = $engagementBetter || $bookingBetter;

        if ($up) {
            return $worse ? 'growth with weaker quality' : 'healthy growth';
        }
        if ($down) {
            return $better ? 'mixed' : 'decline';
        }

        return $worse || $better ? 'mixed' : 'stable';
    }
}
