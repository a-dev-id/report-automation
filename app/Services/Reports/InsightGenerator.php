<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\ReportFormat;

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
 * - The caution is one neutral line when payload.quality_alerts has entries.
 * Thresholds: config report.insights.
 */
final class InsightGenerator
{
    /** @param  array<string, mixed>  $payload */
    public function generate(array $payload): array
    {
        $monthly = ($payload['meta']['type'] ?? 'weekly') === 'monthly';
        $words = $monthly
            ? ['this' => 'This month', 'previous' => 'last month', 'yearAgo' => 'the same month last year', 'unit' => 'month']
            : ['this' => 'This week', 'previous' => 'the previous week', 'yearAgo' => null, 'unit' => 'week'];
        $currency = (string) ($payload['meta']['currency'] ?? '');

        $site = $this->section($payload, 'site');

        $bullets = array_values(array_filter([
            $this->channelBullet($this->section($payload, 'channels'), $words),
            $this->bookingBullet($site, $words),
            $this->countryBullet($this->section($payload, 'country_performance')),
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
            'headline' => $this->headline($site, $words),
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

        $parts = ['sessions '.$this->move($site['sessions'])];
        if (isset($site['keyEvents'])) {
            $parts[] = 'key events '.$this->move($site['keyEvents']);
        }

        $text = $words['this'].', '.implode(' and ', $parts);

        $hasComparison = $this->hasComparison($site['sessions']) || (isset($site['keyEvents']) && $this->hasComparison($site['keyEvents']));
        $text .= $hasComparison ? ", compared with {$words['previous']}." : '.';

        if ($words['yearAgo'] !== null) {
            $yearAgo = [];
            foreach (['sessions' => 'sessions', 'keyEvents' => 'key events'] as $key => $label) {
                if (isset($site[$key]) && ($phrase = $this->yearAgoPhrase($site[$key])) !== null) {
                    $yearAgo[] = "{$label} are {$phrase}";
                }
            }
            if ($yearAgo !== []) {
                $text .= " Against {$words['yearAgo']}, ".implode(' and ', $yearAgo).'.';
            } else {
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
            .($rate !== null ? ' ('.ReportFormat::percent($rate).' of all sessions)' : '').', '.$this->movePhrase($visits, $words['previous']).'.';

        if ($rate !== null && $previousRate !== null) {
            $text .= ' The booking-engine rate was '.ReportFormat::percent($previousRate)." in {$words['previous']}.";
        }

        return $text;
    }

    /** The country with the best traffic quality, not the most sessions. */
    private function countryBullet(?array $rows): ?string
    {
        if ($rows === null || $rows === []) {
            return null;
        }

        $min = (int) config('report.insights.min_country_sessions');
        $countries = [];
        foreach ($rows as $row) {
            $name = (string) ($row['country'] ?? '');
            if ($name === '' || ($row['checkForBots'] ?? false) || ($row['quality'] ?? '') === 'Low Data') {
                continue;
            }
            $c = &$countries[$name];
            $c ??= ['name' => $name, 'sessions' => 0.0, 'visits' => null, 'purchasers' => 0.0, 'engaged' => 0.0, 'quality' => []];
            $sessions = (float) ($row['sessions'] ?? 0);
            $c['sessions'] += $sessions;
            $c['purchasers'] += (float) ($row['purchasers'] ?? 0);
            $c['engaged'] += $sessions * (float) ($row['engagementRate'] ?? 0);
            if (($row['bookingEngineVisits'] ?? null) !== null) {
                $c['visits'] = ($c['visits'] ?? 0.0) + (float) $row['bookingEngineVisits'];
            }
            $c['quality'][] = (string) ($row['quality'] ?? '');
            unset($c);
        }

        $countries = array_filter($countries, fn (array $c) => $c['sessions'] >= $min);
        if ($countries === []) {
            return null;
        }

        $knowsBookings = array_filter($countries, fn (array $c) => $c['visits'] !== null) !== [];
        $score = fn (array $c) => $knowsBookings
            ? ($c['visits'] ?? 0.0) / $c['sessions'] + $c['purchasers'] / $c['sessions']
            : $c['purchasers'] / $c['sessions'];

        // Only countries that show some real intent can be "best quality".
        $candidates = array_filter($countries, fn (array $c) => $score($c) > 0);
        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => [$score($b), $b['purchasers'], $b['sessions']] <=> [$score($a), $a['purchasers'], $a['sessions']]);
        $top = $candidates[0];

        $detail = [];
        if ($top['visits'] !== null) {
            $detail[] = ReportFormat::percent($top['visits'] / $top['sessions'] * 100).' of its '.ReportFormat::number($top['sessions']).' sessions reached the booking engine';
        }
        if ($top['purchasers'] > 0) {
            $detail[] = ReportFormat::number($top['purchasers']).' purchaser'.($top['purchasers'] == 1 ? '' : 's');
        }

        $largest = $countries;
        usort($largest, fn (array $a, array $b) => $b['sessions'] <=> $a['sessions']);
        $note = $largest[0]['name'] !== $top['name'] ? " The largest source of sessions was {$largest[0]['name']}, but it ranks lower on quality." : '';

        return "Best traffic quality: {$top['name']} ({$this->joinDetail($detail)}), judged on booking and purchase activity rather than session count.{$note}";
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
            return "Purchases: none recorded {$this->lower($words['this'])}, compared with ".ReportFormat::number($previous)." in {$words['previous']}.";
        }

        $money = is_array($revenue) && ($revenue['current'] ?? 0) > 0 ? ' worth '.ReportFormat::money($revenue['current'], $currency) : '';

        return 'Purchases: '.ReportFormat::number($current).' purchase'.($current == 1.0 ? '' : 's').$money.', '.$this->movePhrase($purchases, $words['previous']).'.';
    }

    private function aiBullet(?array $ai, array $words): ?string
    {
        $total = $ai['total'] ?? null;
        if (! is_array($total) || (float) ($total['current'] ?? 0) <= 0) {
            return null;
        }

        $top = $ai['sources'][0]['source'] ?? null;

        return 'AI assistants referred '.ReportFormat::number($total['current']).' sessions'
            .($top !== null ? " (mostly {$top})" : '').', '.$this->movePhrase($total, $words['previous']).'.';
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
            ? 'Users: '.ReportFormat::number($m['current']).', '.$this->movePhrase($m, $words['previous']).'.'
            : null;
    }

    /** @param  mixed  $alerts */
    private function caution(mixed $alerts): ?string
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

    /** "up 8.0% from 143 in the previous week", "down 16.1% from 143", "no comparison available". */
    private function movePhrase(array $metric, string $previousLabel): string
    {
        $change = $metric['change'] ?? [];
        $previous = ReportFormat::number($metric['previous'] ?? null);

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

    private function rate(int|float|null $part, int|float|null $whole): ?float
    {
        return $part === null || $whole === null || (float) $whole <= 0 ? null : (float) $part / (float) $whole * 100;
    }

    /** @param  list<string>  $detail */
    private function joinDetail(array $detail): string
    {
        return $detail === [] ? 'high engagement' : implode(', ', $detail);
    }

    private function lower(string $text): string
    {
        return lcfirst($text);
    }
}
