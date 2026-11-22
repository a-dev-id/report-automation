<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Services\Reports\Insight\Finding;
use App\Services\Reports\Insight\InsightContext;
use App\Support\Compare;
use App\Support\Rich;

/** Small hand-built payloads for the insight rule tests: every number in a test is visible in the test. */
trait BuildsInsightPayloads
{
    /** A metric array as Compare::metric() makes it. */
    protected function m(?float $current, ?float $previous, ?float $yearAgo = null, bool $lowerIsBetter = false, bool $withYearAgo = false): array
    {
        $values = ['current' => $current, 'previous' => $previous];
        if ($withYearAgo || $yearAgo !== null) {
            $values['yearAgo'] = $yearAgo;
        }

        return Compare::metric($values, $lowerIsBetter);
    }

    /** @param  array<string, mixed>  $site */
    protected function payload(string $type = 'weekly', array $site = []): array
    {
        $monthly = $type === 'monthly';

        return [
            'meta' => [
                'slug' => 'nandini', 'name' => 'Nandini', 'currency' => 'IDR', 'timezone' => 'Asia/Makassar', 'type' => $type,
                'dateRanges' => ['current' => ['2026-10-01', '2026-10-31'], 'previous' => ['2026-09-01', '2026-09-30'], 'yearAgo' => $monthly ? ['2025-10-01', '2025-10-31'] : null],
            ],
            'site' => $site + [
                'sessions' => $this->m(1000, 800),
                'totalUsers' => $this->m(700, 600),
                'engagementRate' => $this->m(0.60, 0.60),
                'averageSessionDuration' => $this->m(90, 90),
                'keyEvents' => $this->m(30, 30),
                'bookingEngineVisits' => $this->m(50, 40),
                'bookingEvents' => null,
                'purchases' => $this->m(10, 10),
                'purchaseRevenue' => $this->m(10000000, 10000000),
            ],
        ];
    }

    protected function context(array $payload): InsightContext
    {
        return new InsightContext($payload);
    }

    /** The sentence of a finding without markup. */
    protected function plain(?Finding $finding): ?string
    {
        return $finding === null ? null : Rich::toPlain($finding->text);
    }

    /** @param  list<Finding>  $findings */
    protected function plainAll(array $findings): string
    {
        return implode(' ', array_map(fn (Finding $f) => Rich::toPlain($f->text), $findings));
    }

    /** @param  list<Finding>  $findings */
    protected function rules(array $findings): array
    {
        return array_map(fn (Finding $f) => $f->rule, $findings);
    }

    /** @param  list<Finding>  $findings */
    protected function find(array $findings, string $rule): ?Finding
    {
        foreach ($findings as $f) {
            if ($f->rule === $rule) {
                return $f;
            }
        }

        return null;
    }

    /** A paid section like the payload's, from plain numbers (current, previous). */
    protected function paid(array $overrides = [], array $campaigns = []): array
    {
        return [
            'source' => 'ga4', 'available' => true, 'note' => null, 'currency' => 'IDR',
            'metrics' => $overrides + [
                'impressions' => $this->m(50000, 40000),
                'clicks' => $this->m(1000, 900),
                'ctr' => $this->m(0.02, 0.0225),
                'cost' => $this->m(5000000, 4800000),
                'costPerClick' => $this->m(5000, 5333, lowerIsBetter: true),
            ],
            'campaigns' => $campaigns,
        ];
    }

    /** A channel row as TrafficByChannel makes it. */
    protected function channel(string $name, float $sessions, float $previous, float $share, float $previousShare, float $engagement = 0.6, float $duration = 90.0, bool $other = false): array
    {
        return [
            'channel' => $name, 'isOther' => $other,
            'share' => ['current' => $share, 'previous' => $previousShare],
            'shareChange' => ['previous' => Compare::points($share, $previousShare)],
            'sessions' => $this->m($sessions, $previous),
            'totalUsers' => $this->m($sessions * 0.7, $previous * 0.7),
            'averageSessionDuration' => $this->m($duration, $duration),
            'engagementRate' => $this->m($engagement, $engagement),
        ];
    }

    /** A country row as TrafficByCountry makes it. */
    protected function country(string $name, float $engaged, ?float $previousEngaged, float $sessions, float $share): array
    {
        return [
            'country' => $name, 'isOther' => false,
            'sessions' => $this->m($sessions, $sessions),
            'engagedSessions' => $this->m($engaged, $previousEngaged),
            'engagedShare' => ['current' => $share, 'previous' => $share],
            'share' => ['current' => $share, 'previous' => $share],
        ];
    }

    /** A Country Performance row. */
    protected function perf(string $country, float $sessions, float $engaged, ?float $visits, float $purchasers = 0.0, string $quality = 'Potential', bool $bot = false): array
    {
        return [
            'country' => $country, 'campaign' => '(not set)', 'sourceMedium' => 'google / organic',
            'sessions' => $sessions, 'engagedSessions' => $engaged, 'engagementRate' => $sessions > 0 ? $engaged / $sessions : 0.0,
            'averageSessionDuration' => 60.0, 'bookingEngineVisits' => $visits, 'bookingEngineEvents' => $visits,
            'purchasers' => $purchasers, 'purchaseRevenue' => 0.0, 'quality' => $quality, 'checkForBots' => $bot, 'flag' => $bot ? 'check for bots' : null,
        ];
    }
}
