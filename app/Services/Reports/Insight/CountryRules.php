<?php

declare(strict_types=1);

namespace App\Services\Reports\Insight;

/**
 * "Countries" paragraph. Countries are ranked by ENGAGED sessions (payload.countries), not by raw sessions.
 *
 *   overview        which countries bring the most engaged visitors, with their share of engaged sessions
 *   entered         countries that entered the top list, in three tiers from engagement and booking-engine visits (config
 *                   insight.new_country): strong (50%+ engaged and 3+ booking visits) "shows genuine booking interest", some (30%+ and
 *                   1+ visit) "shows some booking interest", anything weaker "needs a closer look". "Genuine" is never used below 3 visits.
 *   dropped         countries that dropped out of the top list
 *   priorityMarkets combined share of config report.insight.priority_countries, and whether it grew
 *   botSuspects     Country Performance rows flagged "check for bots"
 *   bestQuality     the country with the best booking or purchase rate: only named when it has at least min_country_sessions
 *                   sessions AND a booking-engine or purchase rate above min_country_rate_percent (otherwise left out)
 */
final class CountryRules
{
    /** @return list<Finding> */
    public function findings(InsightContext $c): array
    {
        $rows = $this->rows($c);
        if ($rows === []) {
            return [];
        }

        $out = array_filter([$this->overview($c, $rows)]);
        foreach (['entered', 'dropped', 'priorityMarkets', 'botSuspects', 'bestQuality'] as $rule) {
            $finding = $this->{$rule}($c, $rows);
            if ($finding !== null) {
                $out[] = $finding->withRule($rule);
            }
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> country rows (not "Other"), ranked by engaged sessions */
    public function rows(InsightContext $c): array
    {
        $countries = $c->section('countries');

        return array_values(array_filter($countries['rows'] ?? [], fn (array $r) => ! ($r['isOther'] ?? false) && ($r['country'] ?? '') !== '(not set)'));
    }

    /**
     * The first time a share appears it says what it is a share of, and "engaged sessions" is explained once in plain words.
     */
    public function overview(InsightContext $c, array $rows): ?Finding
    {
        $top = array_slice($rows, 0, 3);
        $parts = [];
        foreach ($top as $i => $r) {
            $share = $r['engagedShare']['current'] ?? null;
            $parts[] = $r['country'].($share !== null ? ' ('.$c->value('pct', $share).($i === 0 ? ' of engaged sessions' : '').')' : '');
        }

        return new Finding(
            'Ranked by engaged sessions (visits where people actually interacted with the site), the most engaged visitors came from '.$c->join($parts).'.',
            weight: 100,
            essential: true,
            rule: 'overview',
        );
    }
    public function entered(InsightContext $c, array $rows): ?Finding
    {
        $entered = (array) ($c->section('countries')['entered'] ?? []);
        if ($entered === []) {
            return null;
        }

        $byName = array_column($rows, null, 'country');
        $strong = (array) $c->config('new_country.strong', ['min_engagement_percent' => 50, 'min_booking_visits' => 3]);
        $some = (array) $c->config('new_country.some', ['min_engagement_percent' => 30, 'min_booking_visits' => 1]);
        $weakBelow = (float) $c->config('new_country.weak.max_engagement_percent', 25);

        $parts = [];
        $worrying = [];

        foreach (array_slice($entered, 0, 3) as $name) {
            $row = $byName[$name] ?? null;
            if ($row === null) {
                continue;
            }

            $sessions = (float) ($row['sessions']['current'] ?? 0);
            $engagement = $sessions > 0 ? (float) ($row['engagedSessions']['current'] ?? 0) / $sessions * 100 : 0.0;
            $visits = $this->bookingVisits($c, $name);

            $detail = $c->value('pct', $engagement).' engaged'.($visits !== null ? ', '.$c->value('n', $visits).' booking-engine visits' : '');

            if ($visits !== null) {
                $verdict = match (true) {
                    $engagement >= (float) $strong['min_engagement_percent'] && $visits >= (float) $strong['min_booking_visits'] => 'shows genuine booking interest',
                    $engagement >= (float) $some['min_engagement_percent'] && $visits >= (float) $some['min_booking_visits'] => 'shows some booking interest',
                    default => 'needs a closer look',
                };
            } else {
                // No booking data for this country: judge on engagement only and never claim booking interest.
                $verdict = match (true) {
                    $engagement >= (float) $strong['min_engagement_percent'] => 'shows strong engagement',
                    $engagement >= (float) $some['min_engagement_percent'] => 'shows moderate engagement',
                    default => 'needs a closer look',
                };
            }

            if ($engagement < $weakBelow && ($visits === null || $visits <= 0)) {
                $worrying[] = $name;
            }

            $parts[] = "{$name} ({$detail}) {$verdict}";
        }

        if ($parts === []) {
            return null;
        }

        return new Finding(
            'New in the top list: '.implode('; ', $parts).'.',
            weight: 92,
            action: $worrying !== [] ? 'Check the '.$c->join(array_slice($worrying, 0, 2)).' traffic in GA4 before counting it as demand.' : null,
            actionPriority: 2,
        );
    }
    public function dropped(InsightContext $c, array $rows): ?Finding
    {
        $dropped = array_slice((array) ($c->section('countries')['dropped'] ?? []), 0, 4);

        return $dropped === [] ? null : new Finding('Dropped out of the top list: '.$c->join($dropped).'.', weight: 80);
    }

    public function priorityMarkets(InsightContext $c, array $rows): ?Finding
    {
        $priority = $c->section('countries')['priorityMarkets'] ?? null;
        $now = $priority['engagedShare']['current'] ?? null;
        if (! is_array($priority) || $now === null) {
            return null;
        }

        $before = $priority['engagedShare']['previous'] ?? null;
        $text = 'Your priority markets ('.$c->join((array) $priority['countries']).') made up '.$c->value('pct', $now).' of engaged sessions';

        if ($before === null) {
            $text .= ' (no comparison available).';
        } elseif (abs($now - $before) < 0.1) {
            $text .= ', unchanged from '.$c->value('pct', $before).'.';
        } else {
            $text .= ', '.($now > $before ? 'up' : 'down').' from '.$c->value('pct', $before).'.';
        }

        return new Finding($text, weight: 90);
    }

    public function botSuspects(InsightContext $c, array $rows): ?Finding
    {
        $names = [];
        foreach ((array) ($c->section('country_performance') ?? []) as $row) {
            if (($row['checkForBots'] ?? false) && ! in_array($row['country'], $names, true)) {
                $names[] = (string) $row['country'];
            }
        }

        return $names === []
            ? null
            : new Finding('Visits from '.$c->join(array_slice($names, 0, 3)).' are very short with no booking activity, so treat those figures with care.', weight: 78);
    }

    public function bestQuality(InsightContext $c, array $rows): ?Finding
    {
        $performance = $c->section('country_performance');
        if ($performance === null || $performance === []) {
            return null;
        }

        $all = [];
        $clean = [];
        foreach ($performance as $row) {
            $name = (string) ($row['country'] ?? '');
            if ($name === '') {
                continue;
            }

            $sessions = (float) ($row['sessions'] ?? 0);
            $engaged = (float) ($row['engagedSessions'] ?? $sessions * (float) ($row['engagementRate'] ?? 0));
            $all[$name]['sessions'] = ($all[$name]['sessions'] ?? 0.0) + $sessions;
            $all[$name]['engaged'] = ($all[$name]['engaged'] ?? 0.0) + $engaged;

            if (($row['checkForBots'] ?? false) || ($row['quality'] ?? '') === 'Low Data') {
                continue;
            }

            $e = &$clean[$name];
            $e ??= ['name' => $name, 'sessions' => 0.0, 'visits' => null, 'purchasers' => 0.0];
            $e['sessions'] += $sessions;
            $e['purchasers'] += (float) ($row['purchasers'] ?? 0);
            if (($row['bookingEngineVisits'] ?? null) !== null) {
                $e['visits'] = ($e['visits'] ?? 0.0) + (float) $row['bookingEngineVisits'];
            }
            unset($e);
        }

        $clean = array_filter($clean, fn (array $e) => $e['sessions'] > 0);
        if ($clean === []) {
            return null;
        }

        $knowsBookings = array_filter($clean, fn (array $e) => $e['visits'] !== null) !== [];
        $score = fn (array $e) => $knowsBookings
            ? ($e['visits'] ?? 0.0) / $e['sessions'] + $e['purchasers'] / $e['sessions']
            : $e['purchasers'] / $e['sessions'];

        $ranked = array_values($clean);
        usort($ranked, fn (array $a, array $b) => [$score($b), $b['purchasers'], $b['sessions']] <=> [$score($a), $a['purchasers'], $a['sessions']]);
        $top = $ranked[0];

        $minSessions = (float) $c->config('min_country_sessions', 50);
        $minRate = (float) $c->config('min_country_rate_percent', 1.0);
        $bookingRate = $top['visits'] !== null ? $top['visits'] / $top['sessions'] * 100 : 0.0;
        $purchaseRate = $top['purchasers'] / $top['sessions'] * 100;

        if ($top['sessions'] < $minSessions || ($bookingRate <= $minRate && $purchaseRate <= $minRate)) {
            return null;   // no weak filler
        }

        $detail = [];
        if ($top['visits'] !== null && $bookingRate > $minRate) {
            $detail[] = $c->value('pct', $bookingRate).' of its '.$c->value('n', $top['sessions']).' top-source sessions reached the booking engine';
        }
        if ($top['purchasers'] > 0 && $purchaseRate > $minRate) {
            $detail[] = $c->value('n', $top['purchasers']).' purchaser'.($top['purchasers'] == 1 ? '' : 's').' ('.$c->value('pct', $purchaseRate).' of those sessions)';
        }

        $text = "The best traffic quality came from {$top['name']} (".($detail === [] ? 'high engagement' : implode(', ', $detail)).'), judged on bookings and purchases rather than sessions.';

        // "Largest" and "engaged leader" are taken over ALL countries (the countries section), not just the rows shown here.
        $countries = $c->section('countries');
        $largest = $countries['largestBySessions']['country'] ?? $this->leader($all, 'sessions');
        if ($largest !== null && $largest !== $top['name']) {
            $engagedLeader = $countries['engagedLeader']['country'] ?? $this->leader($all, 'engaged');
            $text .= " The largest country by sessions was {$largest}";
            $text .= $engagedLeader !== null && $engagedLeader !== $largest ? ", and the engaged-sessions leader was {$engagedLeader}." : '.';
        }

        return new Finding($text, weight: 75);
    }

    // ----------------------------------------------------------------------------------------------

    /** Booking-engine visits of a country across its Country Performance rows, or null when the hotel has no booking definition. */
    private function bookingVisits(InsightContext $c, string $country): ?float
    {
        $sum = null;
        foreach ((array) ($c->section('country_performance') ?? []) as $row) {
            if (($row['country'] ?? null) === $country && ($row['bookingEngineVisits'] ?? null) !== null) {
                $sum = ($sum ?? 0.0) + (float) $row['bookingEngineVisits'];
            }
        }

        // The country is in the top list but has no row in the table: booking activity is unknown for it.
        return $sum;
    }

    /** The country with the highest value of $key, or null when nothing is above zero. */
    private function leader(array $countries, string $key): ?string
    {
        $best = null;
        foreach ($countries as $name => $values) {
            if ($values[$key] > 0 && ($best === null || $values[$key] > $countries[$best][$key])) {
                $best = $name;
            }
        }

        return $best;
    }
}
