<?php

declare(strict_types=1);

namespace App\Reports\Queries;

use App\Contracts\Ga4Client;
use App\Models\Property;
use App\Reports\Ga4Rows;
use App\Support\Compare;
use App\Support\Ga4Report;
use App\Support\ReportPeriod;

/**
 * Paid ads, depending on properties.ads_source:
 *   none           : returns null (the section is simply absent for this hotel).
 *   google_ads_api : returns ['source', 'available' => false, 'note' => ..., 'currency' => null, 'metrics' => null] (not implemented).
 *   ga4            : ['source' => 'ga4', 'available' => true, 'note' => null, 'currency' => GA4's currencyCode, 'metrics' => [
 *                      impressions, clicks, cost, ctr, costPerClick  (each a Compare::metric() array)]]
 * ctr is a fraction (clicks / impressions) and costPerClick is cost / clicks, both null when the
 * divisor is zero. costPerClick treats lower as better. Cost is in 'currency', the currencyCode GA4 returned.
 *
 * GA4 rejects the advertiser* metrics without a campaign dimension ("Please add sessionCampaignName"), so the
 * report is run by sessionCampaignName and the rows are summed here.
 */
final class PaidPerformance
{
    public function __construct(private readonly Ga4Client $ga4)
    {
    }

    public function fetch(Property $property, ReportPeriod $period): ?array
    {
        return match ($property->ads_source) {
            'ga4' => $this->fromGa4($property, $period),
            'google_ads_api' => [
                'source' => 'google_ads_api',
                'available' => false,
                'note' => 'Google Ads API reporting is not implemented yet.',
                'currency' => null,
                'metrics' => null,
            ],
            default => null,
        };
    }

    private static function sum(array $rows, string $metric): float
    {
        return (float) array_sum(array_map(fn (array $row) => $row['metrics'][$metric] ?? 0.0, $rows));
    }

    private function fromGa4(Property $property, ReportPeriod $period): array
    {
        $ranges = Ga4Rows::ranges($period);
        $result = $this->ga4->runReport($property, new Ga4Report(
            'paid', $ranges, ['sessionCampaignName'], ['advertiserAdImpressions', 'advertiserAdClicks', 'advertiserAdCost'],
        ));

        $impressions = $clicks = $cost = $ctr = $cpc = [];
        foreach (array_keys($ranges) as $range) {
            $impressions[$range] = self::sum($result[$range] ?? [], 'advertiserAdImpressions');
            $clicks[$range] = self::sum($result[$range] ?? [], 'advertiserAdClicks');
            $cost[$range] = self::sum($result[$range] ?? [], 'advertiserAdCost');
            $ctr[$range] = $impressions[$range] > 0 ? $clicks[$range] / $impressions[$range] : null;
            $cpc[$range] = $clicks[$range] > 0 ? $cost[$range] / $clicks[$range] : null;
        }

        return [
            'source' => 'ga4',
            'available' => true,
            'note' => null,
            'currency' => $this->ga4->currencyCode($property),
            'metrics' => [
                'impressions' => Compare::metric($impressions),
                'clicks' => Compare::metric($clicks),
                'cost' => Compare::metric($cost),
                'ctr' => Compare::metric($ctr),
                'costPerClick' => Compare::metric($cpc, lowerIsBetter: true),
            ],
        ];
    }
}
