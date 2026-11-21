<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\Property;

/**
 * Fixture data for two imaginary hotels with different IDs, currency, booking tracking and ads setup.
 * Pinned "now" for tests: 2026-11-21. Last complete week: 9 to 15 Nov 2026. Last complete month: October 2026.
 */
final class FakeData
{
    public const NOW = '2026-11-21 10:00:00';

    public const NANDINI_ID = '111111111';

    public const KAMANDALU_ID = '222222222';

    public static function nandini(): Property
    {
        return new Property([
            'name' => 'Nandini',
            'slug' => 'nandini',
            'ga4_property_id' => self::NANDINI_ID,
            'gsc_site_url' => 'sc-domain:nandini.test',
            'booking_engine' => ['type' => 'hostname', 'value' => 'book.nandini.test'],
            'ads_source' => 'ga4',
            'currency' => 'IDR',
            'timezone' => 'Asia/Makassar',
        ]);
    }

    public static function kamandalu(): Property
    {
        return new Property([
            'name' => 'Kamandalu',
            'slug' => 'kamandalu',
            'ga4_property_id' => self::KAMANDALU_ID,
            'gsc_site_url' => 'https://kamandalu.test/',
            'booking_engine' => ['type' => 'event', 'value' => 'click_book_now'],
            'ads_source' => 'none',
            'currency' => 'USD',
            'timezone' => 'Asia/Makassar',
        ]);
    }

    /** @return array<string, array<string, array<string, list<array>>>> */
    public static function ga4(): array
    {
        return [
            self::NANDINI_ID => self::ga4Nandini(),
            // Kamandalu has twice the traffic (its booking tracking differs through the Property, not the fixture).
            self::KAMANDALU_ID => self::scale(self::ga4Nandini(), 2),
        ];
    }

    /** @return array<string, array<string, list<array>>> */
    public static function gsc(): array
    {
        return [
            'sc-domain:nandini.test' => self::gscNandini(),
            'https://kamandalu.test/' => self::scale(self::gscNandini(), 2),
        ];
    }

    private static function r(array $dimensions, array $metrics): array
    {
        return ['dimensions' => $dimensions, 'metrics' => array_map('floatval', $metrics)];
    }

    private static function ga4Nandini(): array
    {
        $r = self::r(...);

        $channels = fn (array $values) => array_map(
            fn ($name, $sessions) => $r(['sessionDefaultChannelGroup' => $name], ['sessions' => $sessions, 'totalUsers' => $sessions * 0.7, 'averageSessionDuration' => 60]),
            array_keys($values),
            $values,
        );

        $countries = fn (array $values) => array_map(
            fn ($name, $sessions) => $r(['country' => $name], ['sessions' => $sessions]),
            array_keys($values),
            $values,
        );

        $perf = fn (string $country, string $campaign, string $sourceMedium, array $m) => $r(
            ['country' => $country, 'sessionCampaignName' => $campaign, 'sessionSourceMedium' => $sourceMedium],
            $m,
        );
        $perfKey = fn (string $country, string $campaign, string $sourceMedium, float $events, float $sessions) => $r(
            ['country' => $country, 'sessionCampaignName' => $campaign, 'sessionSourceMedium' => $sourceMedium],
            ['sessions' => $sessions, 'eventCount' => $events],
        );

        return [
            'site.totals' => [
                'current' => [$r([], ['sessions' => 10000, 'totalUsers' => 7000, 'averageSessionDuration' => 95.5, 'ecommercePurchases' => 12, 'purchaseRevenue' => 48000000])],
                'previous' => [$r([], ['sessions' => 8000, 'totalUsers' => 6000, 'averageSessionDuration' => 100, 'ecommercePurchases' => 10, 'purchaseRevenue' => 40000000])],
                'yearAgo' => [$r([], ['sessions' => 5000, 'totalUsers' => 3500, 'averageSessionDuration' => 80, 'ecommercePurchases' => 6, 'purchaseRevenue' => 20000000])],
            ],
            'site.booking' => [
                'current' => [$r([], ['sessions' => 900, 'eventCount' => 2700])],
                'previous' => [$r([], ['sessions' => 600, 'eventCount' => 1500])],
                'yearAgo' => [$r([], ['sessions' => 300, 'eventCount' => 600])],
            ],
            'channels' => [
                'current' => $channels(['Organic Search' => 4000, 'Direct' => 2500, 'Paid Search' => 1200, 'Organic Social' => 800, 'Referral' => 600, 'Email' => 400, 'Display' => 300, 'Unassigned' => 200]),
                'previous' => $channels(['Organic Search' => 3200, 'Direct' => 2000, 'Paid Search' => 1000, 'Organic Social' => 700, 'Referral' => 500, 'Email' => 300, 'Display' => 200, 'Unassigned' => 100]),
                'yearAgo' => $channels(['Organic Search' => 2000, 'Direct' => 1500, 'Paid Search' => 800, 'Organic Social' => 400, 'Referral' => 300]),
            ],
            'countries' => [
                'current' => $countries(['Indonesia' => 3000, 'Australia' => 1800, 'United States' => 1200, 'United Kingdom' => 900, 'Germany' => 700, 'France' => 500, 'Singapore' => 400, 'Netherlands' => 350, 'Canada' => 350, 'India' => 300, 'Japan' => 250, 'Brazil' => 250]),
                'previous' => $countries(['Indonesia' => 2600, 'Australia' => 1200, 'United States' => 1000, 'United Kingdom' => 800, 'Germany' => 500, 'France' => 400, 'Singapore' => 350, 'Netherlands' => 300, 'Canada' => 250, 'India' => 250, 'Japan' => 200, 'Brazil' => 150]),
                'yearAgo' => $countries(['Indonesia' => 2000, 'Australia' => 1000, 'United States' => 800, 'United Kingdom' => 500, 'Germany' => 300, 'France' => 200, 'Singapore' => 200]),
            ],
            'country_performance' => [
                'current' => [
                    $perf('Indonesia', '(not set)', 'google / organic', ['totalUsers' => 2000, 'sessions' => 3000, 'averageSessionDuration' => 80, 'totalPurchasers' => 5, 'purchaseRevenue' => 20000000]),
                    $perf('Australia', 'brand-aus', 'google / cpc', ['totalUsers' => 1500, 'sessions' => 1800, 'averageSessionDuration' => 120, 'totalPurchasers' => 4, 'purchaseRevenue' => 16000000]),
                    $perf('United States', '(not set)', 'google / organic', ['totalUsers' => 1000, 'sessions' => 1200, 'averageSessionDuration' => 60, 'totalPurchasers' => 0, 'purchaseRevenue' => 0]),
                    $perf('United Kingdom', '(not set)', '(direct) / (none)', ['totalUsers' => 800, 'sessions' => 900, 'averageSessionDuration' => 5, 'totalPurchasers' => 0, 'purchaseRevenue' => 0]),
                    $perf('Germany', 'spam-run', 'spam.example / referral', ['totalUsers' => 650, 'sessions' => 700, 'averageSessionDuration' => 0.2, 'totalPurchasers' => 0, 'purchaseRevenue' => 0]),
                    $perf('France', '(not set)', 'bing / organic', ['totalUsers' => 400, 'sessions' => 500, 'averageSessionDuration' => 30, 'totalPurchasers' => 0, 'purchaseRevenue' => 0]),
                    $perf('Singapore', '(not set)', 'google / organic', ['totalUsers' => 350, 'sessions' => 400, 'averageSessionDuration' => 50, 'totalPurchasers' => 0, 'purchaseRevenue' => 0]),
                    $perf('Netherlands', '(not set)', 'google / organic', ['totalUsers' => 300, 'sessions' => 350, 'averageSessionDuration' => 20, 'totalPurchasers' => 0, 'purchaseRevenue' => 0]),
                    $perf('Canada', '(not set)', 'google / organic', ['totalUsers' => 280, 'sessions' => 300, 'averageSessionDuration' => 3.5, 'totalPurchasers' => 0, 'purchaseRevenue' => 0]),
                    $perf('Japan', '(not set)', 'google / organic', ['totalUsers' => 8, 'sessions' => 8, 'averageSessionDuration' => 0, 'totalPurchasers' => 0, 'purchaseRevenue' => 0]),
                    $perf('Brazil', '(not set)', 'google / organic', ['totalUsers' => 6, 'sessions' => 6, 'averageSessionDuration' => 0, 'totalPurchasers' => 0, 'purchaseRevenue' => 0]),
                    $perf('Italy', '(not set)', 'google / organic', ['totalUsers' => 2, 'sessions' => 2, 'averageSessionDuration' => 0, 'totalPurchasers' => 0, 'purchaseRevenue' => 0]),
                ],
            ],
            'country_performance.booking' => [
                'current' => [
                    $perfKey('Australia', 'brand-aus', 'google / cpc', 90, 80),
                    $perfKey('United States', '(not set)', 'google / organic', 3, 3),
                ],
            ],
            'country_performance.previous' => [
                // Australia is missing on purpose: it had no sessions from this campaign before (direction "new").
                'previous' => [
                    $r(['country' => 'Indonesia', 'sessionCampaignName' => '(not set)', 'sessionSourceMedium' => 'google / organic'], ['sessions' => 2400]),
                    $r(['country' => 'United States', 'sessionCampaignName' => '(not set)', 'sessionSourceMedium' => 'google / organic'], ['sessions' => 1500]),
                    $r(['country' => 'Germany', 'sessionCampaignName' => 'spam-run', 'sessionSourceMedium' => 'spam.example / referral'], ['sessions' => 700]),
                ],
            ],
            'ai.sources' => [
                'current' => array_map(fn ($s, $n) => $r(['sessionSource' => $s], ['sessions' => $n]), ['chatgpt.com', 'perplexity.ai', 'gemini.google.com', 'claude.ai'], [120, 40, 10, 5]),
                'previous' => array_map(fn ($s, $n) => $r(['sessionSource' => $s], ['sessions' => $n]), ['chatgpt.com', 'perplexity.ai', 'claude.ai'], [80, 20, 6]),
                'yearAgo' => array_map(fn ($s, $n) => $r(['sessionSource' => $s], ['sessions' => $n]), ['chatgpt.com', 'copilot.microsoft.com'], [30, 3]),
            ],
            'ai.countries' => [
                'current' => array_map(fn ($s, $n) => $r(['country' => $s], ['sessions' => $n]), ['United States', 'Indonesia', 'Australia', 'Germany'], [70, 50, 30, 25]),
            ],
            'ai.landing_pages' => [
                'current' => array_map(fn ($s, $n) => $r(['landingPage' => $s], ['sessions' => $n]), ['/', '/rooms', '/spa', '/blog'], [90, 60, 40, 10]),
            ],
            'paid' => [
                // Two campaigns in the current range: the section sums them.
                'current' => [
                    $r(['sessionCampaignName' => 'brand'], ['advertiserAdImpressions' => 30000, 'advertiserAdClicks' => 700, 'advertiserAdCost' => 3000000]),
                    $r(['sessionCampaignName' => 'spa'], ['advertiserAdImpressions' => 20000, 'advertiserAdClicks' => 300, 'advertiserAdCost' => 2000000]),
                ],
                'previous' => [$r(['sessionCampaignName' => 'brand'], ['advertiserAdImpressions' => 40000, 'advertiserAdClicks' => 800, 'advertiserAdCost' => 4800000])],
                'yearAgo' => [$r(['sessionCampaignName' => 'brand'], ['advertiserAdImpressions' => 20000, 'advertiserAdClicks' => 300, 'advertiserAdCost' => 1500000])],
            ],
        ];
    }

    private static function gscNandini(): array
    {
        $row = fn (array $keys, float $clicks, float $impressions, float $position) => [
            'keys' => $keys, 'clicks' => $clicks, 'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0, 'position' => $position,
        ];
        $queries = fn (array $values) => array_map(fn ($q, $v) => $row([$q], $v[0], $v[1], $v[2]), array_keys($values), $values);

        return [
            'totals.current' => [$row([], 500, 20000, 12.3)],
            'totals.previous' => [$row([], 400, 18000, 13.1)],
            'totals.yearAgo' => [$row([], 200, 8000, 20.5)],
            'queries.current' => $queries([
                'nandini ubud' => [200, 4000, 1.2], 'ubud villa' => [120, 5000, 4.5], 'ubud spa' => [60, 3000, 8.0],
                'best hotel ubud' => [40, 2500, 9.0], 'ubud pool villa' => [30, 2000, 11.0], 'ubud resort' => [20, 1500, 14.0],
                'bali retreat' => [15, 1200, 16.0], 'ubud wedding' => [10, 900, 18.0], 'ubud yoga' => [8, 800, 20.0],
                'ubud honeymoon' => [6, 700, 22.0], 'ubud cheap' => [4, 600, 25.0], 'ubud hostel' => [1, 500, 30.0],
            ]),
            'queries.previous' => $queries(['nandini ubud' => [160, 3800, 1.4], 'ubud villa' => [100, 4800, 5.0]]),
            'pages.current' => array_map(
                fn ($p, $v) => $row([$p], $v[0], $v[1], $v[2]),
                ['https://nandini.test/', 'https://nandini.test/rooms', 'https://nandini.test/spa', 'https://nandini.test/blog', 'https://nandini.test/offers', 'https://nandini.test/contact'],
                [[300, 9000, 6.0], [100, 5000, 9.0], [50, 3000, 10.0], [30, 2000, 12.0], [15, 1000, 15.0], [5, 400, 25.0]],
            ),
            'pages.previous' => array_map(fn ($p, $v) => $row([$p], $v[0], $v[1], $v[2]), ['https://nandini.test/'], [[250, 8500, 6.5]]),
        ];
    }

    /**
     * Multiplies counts (not durations, positions or ctr).
     *
     * @param  array<string, mixed>  $fixture
     */
    private static function scale(array $fixture, int $factor): array
    {
        $countKeys = ['sessions', 'totalUsers', 'ecommercePurchases', 'purchaseRevenue', 'totalPurchasers', 'eventCount',
            'advertiserAdImpressions', 'advertiserAdClicks', 'advertiserAdCost', 'clicks', 'impressions'];

        $walk = function (mixed $node) use (&$walk, $countKeys, $factor): mixed {
            if (! is_array($node)) {
                return $node;
            }
            $out = [];
            foreach ($node as $key => $value) {
                $out[$key] = in_array($key, $countKeys, true) && is_numeric($value) ? $value * $factor : $walk($value);
            }

            return $out;
        };

        return $walk($fixture);
    }
}
