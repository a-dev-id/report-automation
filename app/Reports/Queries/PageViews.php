<?php

declare(strict_types=1);

namespace App\Reports\Queries;

use App\Contracts\Ga4Client;
use App\Models\Property;
use App\Reports\Ga4Rows;
use App\Support\Compare;
use App\Support\Ga4Report;
use App\Support\InsightConfig;
use App\Support\ReportPeriod;

/**
 * The most visited pages and the most visited offers, ranked by page views and compared with the previous period.
 *
 * Offers are the pages under the hotel's offer path (config report.insight.offer_path_prefix, "/offer/" by default, override per
 * hotel under report.insight.properties.{slug}). A hotel with no offer pages gets no offers section.
 *
 * Output of pages() and offers(): [
 *   'total' => metric (page views of ALL matching pages),
 *   'rows' => [['path', 'name', 'views' => metric, 'users' => float (current period)], ...]  top N by current views (report.top.pages / report.top.offers)
 * ]
 */
final class PageViews
{
    public function __construct(private readonly Ga4Client $ga4)
    {
    }

    /** @return array{total: array<string, mixed>, rows: list<array<string, mixed>>} */
    public function pages(Property $property, ReportPeriod $period): array
    {
        return $this->rank($property, $period, 'pages', null);
    }

    /** @return array{total: array<string, mixed>, rows: list<array<string, mixed>>} */
    public function offers(Property $property, ReportPeriod $period): array
    {
        $prefix = trim((string) InsightConfig::get('offer_path_prefix', $property->slug, '/offer/'));

        if ($prefix === '') {
            return ['total' => Compare::metric(['current' => 0.0, 'previous' => 0.0]), 'rows' => []];
        }

        return $this->rank($property, $period, 'offers', ['field' => 'pagePath', 'op' => 'begins_with', 'value' => $prefix], $prefix);
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return array{total: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    private function rank(Property $property, ReportPeriod $period, string $label, ?array $filter, ?string $offerPrefix = null): array
    {
        $ranges = Ga4Rows::ranges($period);
        $compared = ['current' => $ranges['current'], 'previous' => $ranges['previous']];

        $result = $this->ga4->runReport($property, new Ga4Report(
            "{$label}.views",
            $compared,
            ['pagePath'],
            ['screenPageViews', 'totalUsers'],
            $filter,
            [['field' => 'screenPageViews', 'metric' => true, 'desc' => true]],
        ));

        $groups = Ga4Rows::sortByCurrent(Ga4Rows::group($result, ['pagePath']), 'screenPageViews');
        $names = array_keys($compared);

        $rows = [];
        foreach ($groups as $group) {
            if (Ga4Rows::value($group, 'current', 'screenPageViews') <= 0 || count($rows) >= Ga4Rows::topN($label, $period)) {
                continue;
            }

            $path = $group['dimensions']['pagePath'];
            $rows[] = [
                'path' => $path,
                'name' => $offerPrefix !== null ? self::offerName($path, $offerPrefix) : ($path === '/' ? 'Home page' : $path),
                'views' => Ga4Rows::groupMetric($group, $names, 'screenPageViews'),
                'users' => Ga4Rows::value($group, 'current', 'totalUsers'),
            ];
        }

        return ['total' => Compare::metric(Ga4Rows::sum($groups, $names, 'screenPageViews')), 'rows' => $rows];
    }

    /** "/offer/black-friday-limited-time-offer" becomes "Black Friday Limited Time Offer". */
    public static function offerName(string $path, string $prefix): string
    {
        $slug = trim(substr($path, strlen($prefix)), '/');
        $slug = $slug === '' ? $path : $slug;

        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }
}
