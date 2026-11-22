<?php

declare(strict_types=1);

namespace App\Services\Reports\Insight;

/**
 * "Website traffic" paragraph, from payload.site (plus channels, to name the channel that grew most).
 *
 *   overview              sessions, users, average session duration, engagement rate, key events
 *   yearAgo               monthly only: sessions, key events and booking-engine visits against the same month last year
 *   bookingVisits         booking-engine visits (only with a booking definition)
 *   topOffer              the most visited offer (payload.top_offers), when the hotel has offer pages
 *   growthLowQuality      sessions up while engagement or session duration is down: growth from lower-quality traffic
 *   fewerButBetter        sessions down while engagement is up
 *   bookingNotFollowing   sessions up while booking-engine visits are flat or down
 *   bookingRate           the booking-engine rate (booking-engine sessions / sessions) moved
 *   purchases             purchases and revenue, or "numbers are small" when there are fewer than the minimum
 *
 * Thresholds: config report.insight (verdict.engagement_points, verdict.stable_percent, small_numbers.min_purchases).
 */
final class WebsiteTrafficRules
{
    /** @return list<Finding> */
    public function findings(InsightContext $c): array
    {
        $site = $c->section('site');
        if ($site === null || ! isset($site['sessions'])) {
            return [];
        }

        $out = array_filter([$this->overview($c, $site)]);
        foreach (['bookingVisits', 'topOffer', 'yearAgo', 'growthLowQuality', 'fewerButBetter', 'bookingNotFollowing', 'bookingRate', 'purchases'] as $rule) {
            $finding = $this->{$rule}($c, $site);
            if ($finding !== null) {
                $out[] = $finding->withRule($rule);
            }
        }

        return array_values($out);
    }

    /**
     * What changed beyond the headline (which already states sessions, engagement and booking-engine visits): users, session
     * duration and key events. If the site has none of them, there is no overview and the rules below stand alone.
     */
    public function overview(InsightContext $c, array $site): ?Finding
    {
        $parts = [];
        if (($users = $c->phrase($site['totalUsers'] ?? null, 'n', 'Users')) !== null) {
            $parts[] = "total users {$users}";
        }
        if (($duration = $c->phrase($site['averageSessionDuration'] ?? null, 'dur', 'Average session duration')) !== null) {
            $parts[] = "average session duration {$duration}";
        }
        if (($keyEvents = $c->phrase($site['keyEvents'] ?? null, 'n', 'Key events')) !== null) {
            $parts[] = "key events {$keyEvents}";
        }

        return $parts === [] ? null : new Finding(ucfirst($c->join($parts)).'.', weight: 100, essential: true, rule: 'overview');
    }

    public function bookingVisits(InsightContext $c, array $site): ?Finding
    {
        $phrase = $c->phrase($site['bookingEngineVisits'] ?? null, 'n', 'Booking-engine visits');

        // The headline already states booking-engine visits, so this is the first sentence to go when space is short.
        return $phrase === null ? null : new Finding("Sessions that reached the booking engine {$phrase}.", weight: 40);
    }

    public function topOffer(InsightContext $c, array $site): ?Finding
    {
        $top = $c->section('top_offers')['rows'][0] ?? null;
        if (! is_array($top) || (float) ($top['views']['current'] ?? 0) <= 0) {
            return null;
        }

        $direction = $c->dir($top['views']);
        $change = match (true) {
            $direction === 'guarded' => '',
            $direction === 'new' => ', new this period',
            default => ', '.$c->change($top['views']['change']),
        };

        return new Finding('The most visited offer was '.$top['name'].' ('.$c->value('n', $top['views']['current']).' views'.$change.').', weight: 66);
    }

    /** Monthly reports: the same month last year. A year-ago change beyond the guard is left to the caution. */
    public function yearAgo(InsightContext $c, array $site): ?Finding
    {
        if (! $c->monthly || $c->yearAgoName === null) {
            return null;
        }

        $parts = [];
        foreach ([['sessions', 'Sessions', 'sessions'], ['keyEvents', 'Key events', 'key events'], ['bookingEngineVisits', 'Booking-engine visits', 'booking-engine visits']] as [$key, $label, $words]) {
            $phrase = $c->yearAgoPhrase($site[$key] ?? null, $label);
            if ($phrase !== null) {
                $parts[] = "{$words} {$phrase}";
            }
        }

        return $parts === [] ? null : new Finding('Compared with '.$c->yearAgoName.': '.$c->join($parts).'.', weight: 86);
    }

    public function growthLowQuality(InsightContext $c, array $site): ?Finding
    {
        if ($c->dir($site['sessions'] ?? null) !== 'up') {
            return null;
        }

        $engagementDown = ($points = $c->points($site['engagementRate'] ?? null)) !== null
            && $c->dir($site['engagementRate'] ?? null) !== 'guarded'
            && $points <= -(float) $c->config('verdict.engagement_points', 2.0);
        $durationDown = $c->dir($site['averageSessionDuration'] ?? null) === 'down'
            && ($c->percent($site['averageSessionDuration'] ?? null) ?? 0) <= -(float) $c->config('verdict.stable_percent', 5.0);

        if (! $engagementDown && ! $durationDown) {
            return null;
        }

        $leader = $this->biggestGainer($c);
        $text = 'The growth came mainly from lower-quality traffic: visitors stayed for less time or engaged less'
            .($leader !== null ? ", and the channel that grew most was {$leader['name']} ({$c->value('n', $leader['gain'])} more sessions)" : '').'.';

        return new Finding(
            $text,
            weight: 95,
            action: $leader !== null
                ? "Check whether the {$leader['name']} visits are genuine, since that channel grew most."
                : 'Check which channel grew most and whether those visits are genuine.',
            actionPriority: 2,
        );
    }

    public function fewerButBetter(InsightContext $c, array $site): ?Finding
    {
        if ($c->dir($site['sessions'] ?? null) !== 'down') {
            return null;
        }

        $points = $c->points($site['engagementRate'] ?? null);
        if ($points !== null && $c->dir($site['engagementRate'] ?? null) !== 'guarded' && $points >= (float) $c->config('verdict.engagement_points', 2.0)) {
            return new Finding('There were fewer visitors, but the ones who came were more interested.', weight: 85);
        }

        return null;
    }

    public function bookingNotFollowing(InsightContext $c, array $site): ?Finding
    {
        if ($c->dir($site['sessions'] ?? null) !== 'up' || ! isset($site['bookingEngineVisits'])) {
            return null;
        }

        if (in_array($c->dir($site['bookingEngineVisits']), ['flat', 'down'], true)) {
            return new Finding(
                'More visitors are not reaching the booking step: check the booking button and the pages people land on.',
                weight: 92,
                action: 'Check the booking button and the pages most visitors land on.',
                actionPriority: 1,
            );
        }

        return null;
    }

    /**
     * The booking-engine rate (booking-engine sessions / sessions). It is only interpreted when it moved by at least
     * booking_rate.min_points percentage points or booking_rate.min_relative_percent percent AND the booking-engine visits reach
     * booking_rate.min_visits in either period. A smaller move on enough volume is stated as numbers only; too little volume says nothing.
     */
    public function bookingRate(InsightContext $c, array $site): ?Finding
    {
        $visits = $site['bookingEngineVisits'] ?? null;
        $sessions = $site['sessions'] ?? null;
        if (! is_array($visits) || ! is_array($sessions) || $c->dir($visits) === 'guarded' || $c->dir($sessions) === 'guarded') {
            return null;
        }

        $now = $this->rate($visits['current'] ?? null, $sessions['current'] ?? null);
        $before = $this->rate($visits['previous'] ?? null, $sessions['previous'] ?? null);
        if ($now === null || $before === null || abs($now - $before) < 0.1) {
            return null;
        }

        $minVisits = (float) $c->config('booking_rate.min_visits', 20);
        if (max((float) ($visits['current'] ?? 0), (float) ($visits['previous'] ?? 0)) < $minVisits) {
            return null;   // too few booking-engine visits for a rate to mean anything
        }

        $points = abs($now - $before);
        $relative = $before > 0 ? $points / $before * 100 : 100.0;

        if ($points < (float) $c->config('booking_rate.min_points', 0.5) && $relative < (float) $c->config('booking_rate.min_relative_percent', 15)) {
            return new Finding(
                'The booking-engine rate was '.$c->value('pct', $now).' of sessions, against '.$c->value('pct', $before).' '.$c->previousShort().'.',
                weight: 55,
            );
        }

        $up = $now > $before;

        return new Finding(
            'The booking-engine rate '.($up ? 'rose' : 'fell').' from '.$c->value('pct', $before).' to '.$c->value('pct', $now)
            .' of sessions, which means '.($up ? 'more' : 'fewer').' visitors showed interest in booking.',
            weight: 88,
        );
    }
    public function purchases(InsightContext $c, array $site): ?Finding
    {
        $purchases = $site['purchases'] ?? null;
        if (! is_array($purchases) || ($purchases['current'] ?? null) === null) {
            return null;
        }

        $now = (float) $purchases['current'];
        $before = $purchases['previous'] ?? null;
        $min = (float) $c->config('small_numbers.min_purchases', 5);

        if ($now <= 0 && (float) ($before ?? 0) <= 0) {
            return null;
        }

        // Both periods below the minimum: percentage changes mean little.
        if ($now < $min && (float) ($before ?? 0) < $min) {
            return new Finding(
                'Purchases are few ('.$c->value('n', $now).' this '.$c->unit.', '.($before === null ? 'no figure' : $c->value('n', $before)).' before), '
                .'so percentage changes can mislead.',
                weight: 87,
            );
        }

        $revenue = $site['purchaseRevenue'] ?? null;
        $money = is_array($revenue) && (float) ($revenue['current'] ?? 0) > 0 ? ', worth '.$c->value('money', $revenue['current']) : '';

        return new Finding('Purchases '.$c->phrase($purchases, 'n', 'Purchases').$money.'.', weight: 80);
    }

    // ----------------------------------------------------------------------------------------------

    /** @return array{name: string, gain: float}|null the channel with the biggest increase in sessions */
    private function biggestGainer(InsightContext $c): ?array
    {
        $channels = $c->section('channels');
        $best = null;
        foreach ($channels['rows'] ?? [] as $row) {
            if ($row['isOther'] ?? false) {
                continue;
            }
            $gain = (float) ($row['sessions']['current'] ?? 0) - (float) ($row['sessions']['previous'] ?? 0);
            if ($gain > 0 && ($best === null || $gain > $best['gain'])) {
                $best = ['name' => (string) $row['channel'], 'gain' => $gain];
            }
        }

        return $best;
    }

    private function rate(int|float|null $part, int|float|null $whole): ?float
    {
        return $part === null || $whole === null || (float) $whole <= 0 ? null : (float) $part / (float) $whole * 100;
    }
}
