<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\ReportFormat;
use DateTimeImmutable;

/**
 * Rule-based "Traffic Quality Insight" for one payload (see docs/payload.md). No external API.
 *
 * Output: ['headline' => string, 'bullets' => list<string> (3 to 5 when the data allows), 'caution' => string|null]
 *
 * - The headline is sessions and key events against the previous period (and year-ago for monthly reports).
 * - Bullets, in this order, each only when its data exists: channel movement, booking engine, quality country,
 *   purchases, AI traffic. Engagement and users fill up to 3 bullets if too little else is available.
 * - Sections that are missing or are {error} objects are skipped, never guessed.
 * - A zero or missing previous value never divides: the wording says "no comparison available".
 *
 * Large-change guard: a metric that moved by more than report.insights.large_drop_percent (a drop) or
 * large_rise_percent (a rise) is NOT stated as a fact. The headline leaves it out, bullets give only the value, and
 * the caution gets one neutral sentence suggesting a tracking check. The KPI tile in the email still shows the number.
 *
 * Caution = large-change sentence (if any) + one data-quality sentence (if payload.quality_alerts has entries).
 * Thresholds: config report.insights.
 */
final class InsightGenerator
{
    /** @var array<string, array{label: string, percent: float, against: string}> metrics held back by the guard */
    private array $flagged = [];

    /** @param  array<string, mixed>  $payload */
    public function generate(array $payload): array
    {
        $this->flagged = [];

        $monthly = ($payload['meta']['type'] ?? 'weekly') === 'monthly';
        $words = $monthly
            ? ['this' => 'This month', 'previous' => 'last month', 'yearAgo' => 'the same month last year', 'yearAgoName' => $this->yearAgoName($payload)]
            : ['this' => 'This week', 'previous' => 'the previous week', 'yearAgo' => null, 'yearAgoName' => null];
        $currency = (string) ($payload['meta']['currency'] ?? '');

        $site = $this->section($payload, 'site');

        // The headline runs first so the guard sees the headline metrics first.
        $headline = $this->headline($site, $words);

        $bullets = array_values(array_filter([
            $this->channelBullet($this->section($payload, 'channels'), $words),
            $this->bookingBullet($site, $words),
            $this->countryBullet($this->section($payload, 'country_performance'), $this->section($payload, 'countries')),
            $this->purchaseBullet($site, $words, $currency),
            $this->aiBullet($this->section($payload, 'ai_traffic'), $words),
        ]));

        if (count($bullets) < 3) {
            $bullets = array_merge($bullets, array_filter([
                $this->engagementBullet($site, $words),
                $this->usersBullet($site, $words),
            ]));
        }

        return [
            'headline' => $headline,
            'bullets' => array_slice($bullets, 0, max(1, (int) config('report.insights.max_bullets'))),
            'caution' => $this->caution($payload['quality_alerts'] ?? []),
        ];
    }

    // ----------------------------------------------------------------------------------------------

    private function headline(?array $site, array $words): string
    {
        if ($site === null || ! isset($site['sessions'])) {
            return $words['this'].': traffic figures are not available for this report.';
        }

        // Sessions always anchor the headline. A guarded sessions change is left out: only the value is stated.
        if ($this->guarded($site['sessions']['change'] ?? null)) {
            $this->flag('Sessions', $site['sessions']['change'], $words['previous']);
            $parts = ['sessions were '.ReportFormat::number($site['sessions']['current'] ?? null)];
            $hasComparison = false;
        } else {
            $parts = ['sessions '.$this->move($site['sessions'])];
            $hasComparison = $this->hasComparison($site['sessions']);
        }

        // Key events: only when the change is not guarded. If it is, it is not mentioned here at all.
        if (isset($site['keyEvents'])) {
            if ($this->guarded($site['keyEvents']['change'] ?? null)) {
                $this->flag('Key events', $site['keyEvents']['change'], $words['previous']);
            } else {
                $parts[] = 'key events '.$this->move($site['keyEvents']);
                $hasComparison = $hasComparison || $this->hasComparison($site['keyEvents']);
            }
        }

        $text = $words['this'].', '.implode(' and ', $parts);
        $text .= $hasComparison ? ", compared with {$words['previous']}." : '.';

        if ($words['yearAgo'] !== null) {
            $yearAgo = [];
            foreach (['sessions' => 'Sessions', 'keyEvents' => 'Key events'] as $key => $label) {
                if (! isset($site[$key])) {
                    continue;
                }
                if ($this->guarded($site[$key]['yearAgoChange'] ?? null)) {
                    $this->flag($label, $site[$key]['yearAgoChange'], $words['yearAgoName'] ?? $words['yearAgo']);

                    continue;
                }
                if (($phrase = $this->yearAgoPhrase($site[$key])) !== null) {
                    $yearAgo[] = strtolower($label)." are {$phrase}";
                }
            }
            if ($yearAgo !== []) {
                $text .= " Against {$words['yearAgo']}, ".implode(' and ', $yearAgo).'.';
            } elseif ($this->flagged === []) {
                $text .= ' No year-over-year comparison is available.';
            }
        }

        return $text;
    }

    private function channelBullet(?array $channels, array $words): ?string
    {
        if ($channels === null) {
            return null;
        }

        $min = (int) config('report.insights.min_channel_sessions');
        $movers = [];
        foreach ($channels['rows'] ?? [] as $row) {
            $current = (float) ($row['sessions']['current'] ?? 0);
            $previous = (float) ($row['sessions']['previous'] ?? 0);
            if (($row['isOther'] ?? false) || $current + $previous < $min) {
                continue;
            }
            $movers[] = ['name' => (string) $row['channel'], 'difference' => $current - $previous, 'change' => $row['sessions']['change'] ?? null];
        }

        if ($movers === []) {
            return null;
        }

        usort($movers, fn (array $a, array $b) => $b['difference'] <=> $a['difference']);
        $best = $movers[0]['difference'] > 0 ? $movers[0] : null;
        $worst = end($movers)['difference'] < 0 ? end($movers) : null;

        $describe = function (array $m): string {
            $sign = $m['difference'] > 0 ? '+' : '-';
            $percent = $m['change']['percent'] ?? null;
            $tail = $percent === null ? 'new' : ($percent > 0 ? '+' : '').ReportFormat::percent($percent);

            return sprintf('%s sessions, %s', $sign.ReportFormat::number(abs($m['difference'])), $tail);
        };

        if ($best !== null && $worst !== null) {
            return "Channels: {$best['name']} grew the most ({$describe($best)}), while {$worst['name']} fell the most ({$describe($worst)}) vs {$words['previous']}.";
        }
        if ($best !== null) {
            return "Channels: {$best['name']} grew the most ({$describe($best)}) vs {$words['previous']}; no channel fell.";
        }
        if ($worst !== null) {
            return "Channels: {$worst['name']} fell the most ({$describe($worst)}) vs {$words['previous']}; no channel grew.";
        }

        return null;
    }

    private function bookingBullet(?array $site, array $words): ?string
    {
        $visits = $site['bookingEngineVisits'] ?? null;
        $sessions = $site['sessions'] ?? null;
        if (! is_array($visits) || ! is_array($sessions) || ($visits['current'] ?? null) === null) {
            return null;   // no booking definition, or no data
        }

        $rate = $this->rate($visits['current'], $sessions['current'] ?? null);
        $previousRate = $this->rate($visits['previous'] ?? null, $sessions['previous'] ?? null);

        $text = 'Booking engine: '.ReportFormat::number($visits['current']).' sessions reached it'
            .($rate !== null ? ' ('.ReportFormat::percent($rate).' of all sessions)' : '').', '.$this->movePhrase($visits, $words['previous'], 'Booking engine visits').'.';

        // The rate comparison is only a fact if the underlying change is not guarded.
        if ($rate !== null && $previousRate !== null && ! $this->guarded($visits['change'] ?? null)) {
            $text .= ' The booking-engine rate was '.ReportFormat::percent($previousRate)." in {$words['previous']}.";
        }

        return $text;
    }

    /**
     * The country with the best traffic quality, not the most sessions. Included only when the winner has enough
     * sessions and a booking-engine rate or purchase rate above the configured minimum: no weak filler.
     * Rates are measured on the country's rows in the Country Performance table (its top traffic sources), which the
     * wording says ("top-source sessions").
     */
    private function countryBullet(?array $rows, ?array $countries = null): ?string
    {
        if ($rows === null || $rows === []) {
            return null;
        }

        // All rows feed the "largest" and "engaged leader" facts; only clean rows compete on quality.
        $all = [];
        $clean = [];
        foreach ($rows as $row) {
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

            $c = &$clean[$name];
            $c ??= ['name' => $name, 'sessions' => 0.0, 'visits' => null, 'purchasers' => 0.0];
            $c['sessions'] += $sessions;
            $c['purchasers'] += (float) ($row['purchasers'] ?? 0);
            if (($row['bookingEngineVisits'] ?? null) !== null) {
                $c['visits'] = ($c['visits'] ?? 0.0) + (float) $row['bookingEngineVisits'];
            }
            unset($c);
        }

        $clean = array_filter($clean, fn (array $c) => $c['sessions'] > 0);
        if ($clean === []) {
            return null;
        }

        $knowsBookings = array_filter($clean, fn (array $c) => $c['visits'] !== null) !== [];
        $score = fn (array $c) => $knowsBookings
            ? ($c['visits'] ?? 0.0) / $c['sessions'] + $c['purchasers'] / $c['sessions']
            : $c['purchasers'] / $c['sessions'];

        $ranked = array_values($clean);
        usort($ranked, fn (array $a, array $b) => [$score($b), $b['purchasers'], $b['sessions']] <=> [$score($a), $a['purchasers'], $a['sessions']]);
        $top = $ranked[0];

        $minSessions = (float) config('report.insights.min_country_sessions');
        $minRate = (float) config('report.insights.min_country_rate_percent');
        $bookingRate = $top['visits'] !== null ? $top['visits'] / $top['sessions'] * 100 : 0.0;
        $purchaseRate = $top['purchasers'] / $top['sessions'] * 100;

        if ($top['sessions'] < $minSessions || ($bookingRate <= $minRate && $purchaseRate <= $minRate)) {
            return null;
        }

        $detail = [];
        if ($top['visits'] !== null && $bookingRate > $minRate) {
            $detail[] = ReportFormat::percent($bookingRate).' of its '.ReportFormat::number($top['sessions']).' top-source sessions reached the booking engine';
        }
        if ($top['purchasers'] > 0 && $purchaseRate > $minRate) {
            $detail[] = ReportFormat::number($top['purchasers']).' purchaser'.($top['purchasers'] == 1 ? '' : 's').' ('.ReportFormat::percent($purchaseRate).' of those sessions)';
        }

        $text = "Best traffic quality: {$top['name']} ({$this->joinDetail($detail)}), judged on booking and purchase activity rather than session count.";

        // The rows are only the top country x source rows, so the true largest and engaged leader come from the countries section.
        $largest = $countries['largestBySessions']['country'] ?? $this->leader($all, 'sessions');
        if ($largest !== null && $largest !== $top['name']) {
            $engagedLeader = $countries['engagedLeader']['country'] ?? $this->leader($all, 'engaged');
            $text .= " The largest country by sessions was {$largest}";
            $text .= $engagedLeader !== null && $engagedLeader !== $largest ? ", and the engaged-sessions leader was {$engagedLeader}." : '.';
        }

        return $text;
    }

    private function purchaseBullet(?array $site, array $words, string $currency): ?string
    {
        $purchases = $site['purchases'] ?? null;
        $revenue = $site['purchaseRevenue'] ?? null;
        if (! is_array($purchases)) {
            return null;
        }

        $current = (float) ($purchases['current'] ?? 0);
        $previous = $purchases['previous'] ?? null;

        if ($current <= 0 && (float) ($previous ?? 0) <= 0) {
            return null;
        }

        if ($current <= 0) {
            return 'Purchases: none recorded '.lcfirst($words['this']).', compared with '.ReportFormat::number($previous)." in {$words['previous']}.";
        }

        $money = is_array($revenue) && ($revenue['current'] ?? 0) > 0 ? ' worth '.ReportFormat::money($revenue['current'], $currency) : '';

        return 'Purchases: '.ReportFormat::number($current).' purchase'.($current == 1.0 ? '' : 's').$money.', '.$this->movePhrase($purchases, $words['previous'], 'Purchases').'.';
    }

    private function aiBullet(?array $ai, array $words): ?string
    {
        $total = $ai['total'] ?? null;
        if (! is_array($total) || (float) ($total['current'] ?? 0) <= 0) {
            return null;
        }

        $top = $ai['sources'][0]['source'] ?? null;

        return 'AI assistants referred '.ReportFormat::number($total['current']).' sessions'
            .($top !== null ? " (mostly {$top})" : '').', '.$this->movePhrase($total, $words['previous'], 'AI traffic').'.';
    }

    private function engagementBullet(?array $site, array $words): ?string
    {
        $m = $site['engagementRate'] ?? null;
        if (! is_array($m) || ($m['current'] ?? null) === null) {
            return null;
        }

        $text = 'Engagement rate was '.ReportFormat::rate($m['current']);
        if (($m['previous'] ?? null) !== null && (float) $m['previous'] > 0) {
            $text .= ' ('.ReportFormat::rateChange($m['change'] ?? null)['text']." vs {$words['previous']}, which was ".ReportFormat::rate($m['previous']).')';
        } else {
            $text .= ' (no comparison available)';
        }

        return $text.'.';
    }

    private function usersBullet(?array $site, array $words): ?string
    {
        $m = $site['totalUsers'] ?? null;

        return is_array($m) && ($m['current'] ?? null) !== null
            ? 'Users: '.ReportFormat::number($m['current']).', '.$this->movePhrase($m, $words['previous'], 'Users').'.'
            : null;
    }

    /** @param  mixed  $alerts */
    private function caution(mixed $alerts): ?string
    {
        $parts = array_filter([$this->largeChangeSentence(), $this->qualitySentence($alerts)]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** One neutral sentence for every metric the guard held back. */
    private function largeChangeSentence(): ?string
    {
        if ($this->flagged === []) {
            return null;
        }

        $items = [];
        foreach (array_values($this->flagged) as $i => $f) {
            $label = $i === 0 ? $f['label'] : lcfirst($f['label']);
            $items[] = sprintf('%s changed by %s%s versus %s', $label, $f['percent'] > 0 ? '+' : '', ReportFormat::percent($f['percent']), $f['against']);
        }

        $many = count($items) > 1;

        return implode(' and ', $items).'. '
            .($many ? 'Changes this large often come' : 'A change this large often comes')
            .' from a change in how events are tracked, so please check the tracking setup before drawing conclusions.';
    }

    /** @param  mixed  $alerts */
    private function qualitySentence(mixed $alerts): ?string
    {
        if (! is_array($alerts) || $alerts === []) {
            return null;
        }

        $clauses = [];
        foreach ($alerts as $alert) {
            $clauses[] = match ($alert['code'] ?? null) {
                'country_sum_mismatch' => 'some sessions are counted in more than one country, so the country figures add up to more than the site total',
                'direct_low_engagement' => 'a large share of Direct traffic has very short sessions, which can lower averages',
                'currency_mismatch' => 'Google Analytics reports money in '.($alert['details']['ga4Currency'] ?? 'another currency').', which differs from the hotel setting',
                default => rtrim(lcfirst((string) ($alert['message'] ?? 'a data-quality note was recorded')), '. '),
            };
        }

        return 'Data note: '.implode('; ', array_unique($clauses)).'. Please read these figures with care; they are shown as reported.';
    }

    // ----------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null the section, or null if it is missing or is an {error} object */
    private function section(array $payload, string $key): ?array
    {
        $section = $payload[$key] ?? null;

        return is_array($section) && ! isset($section['error']) ? $section : null;
    }

    /** True when a change is beyond the configured guard. A missing percent (new, none) is never guarded. */
    private function guarded(?array $change): bool
    {
        $percent = $change['percent'] ?? null;
        if ($percent === null) {
            return false;
        }

        return $percent < -(float) config('report.insights.large_drop_percent')
            || $percent > (float) config('report.insights.large_rise_percent');
    }

    private function flag(string $label, array $change, string $against): void
    {
        $this->flagged["{$label}|{$against}"] = ['label' => $label, 'percent' => (float) $change['percent'], 'against' => $against];
    }

    private function hasComparison(array $metric): bool
    {
        return in_array($metric['change']['direction'] ?? 'none', ['up', 'down', 'flat'], true);
    }

    /** "rose 25.0% to 10,000", "fell 9.8% to 6,545", "were flat at 100", "reached 5 (none before)", "were 7 (no comparison available)". */
    private function move(array $metric): string
    {
        $current = ReportFormat::number($metric['current'] ?? null);
        $change = $metric['change'] ?? [];
        $percent = $change['percent'] ?? null;

        return match ($change['direction'] ?? 'none') {
            'up' => 'rose '.ReportFormat::percent($percent).' to '.$current,
            'down' => 'fell '.ReportFormat::percent(abs((float) $percent)).' to '.$current,
            'flat' => 'were flat at '.$current,
            'new' => 'reached '.$current.' (none before)',
            default => 'were '.$current.' (no comparison available)',
        };
    }

    /**
     * "up 8.0% from 143 in the previous week", "no comparison available". If the change is beyond the guard, only
     * the previous value is given and the change is left to the caution line.
     */
    private function movePhrase(array $metric, string $previousLabel, string $metricLabel): string
    {
        $change = $metric['change'] ?? [];
        $previous = ReportFormat::number($metric['previous'] ?? null);

        if ($this->guarded($change)) {
            $this->flag($metricLabel, $change, $previousLabel);

            return "compared with {$previous} in {$previousLabel} (see the note below)";
        }

        return match ($change['direction'] ?? 'none') {
            'up' => 'up '.ReportFormat::percent($change['percent'] ?? null)." from {$previous} in {$previousLabel}",
            'down' => 'down '.ReportFormat::percent(abs((float) ($change['percent'] ?? 0)))." from {$previous} in {$previousLabel}",
            'flat' => "flat compared with {$previousLabel}",
            'new' => "none in {$previousLabel}, so no comparison is available",
            default => 'no comparison available',
        };
    }

    private function yearAgoPhrase(array $metric): ?string
    {
        $change = $metric['yearAgoChange'] ?? [];

        return match ($change['direction'] ?? 'none') {
            'up' => 'up '.ReportFormat::percent($change['percent'] ?? null),
            'down' => 'down '.ReportFormat::percent(abs((float) ($change['percent'] ?? 0))),
            'flat' => 'flat',
            default => null,
        };
    }

    /** "October 2025" for a monthly report of October 2026. */
    private function yearAgoName(array $payload): string
    {
        $start = $payload['meta']['dateRanges']['yearAgo'][0] ?? null;

        try {
            return $start ? (new DateTimeImmutable($start))->format('F Y') : 'the same month last year';
        } catch (\Throwable) {
            return 'the same month last year';
        }
    }

    private function rate(int|float|null $part, int|float|null $whole): ?float
    {
        return $part === null || $whole === null || (float) $whole <= 0 ? null : (float) $part / (float) $whole * 100;
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

    /** @param  list<string>  $detail */
    private function joinDetail(array $detail): string
    {
        return $detail === [] ? 'high engagement' : implode(', ', $detail);
    }
}
