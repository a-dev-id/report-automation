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
 * Sessions from AI assistants: sessionSource matching config report.ai_sources (case-insensitive).
 *
 * Output: [
 *   'total' => metric (all matching sources),
 *   'sources' => [['source', 'sessions' => metric], ...] by current sessions; sources with no sessions in both the
 *     current and the previous period are left out (even if they had year-ago sessions),
 *   'topCountries' => [['country', 'sessions']], 'topLandingPages' => [['landingPage', 'sessions']]  (current period, all AI sources together)
 * ]
 */
final class AiReferralTraffic
{
    public function __construct(private readonly Ga4Client $ga4)
    {
    }

    public function fetch(Property $property, ReportPeriod $period): array
    {
        $ranges = Ga4Rows::ranges($period);
        $names = array_keys($ranges);
        $current = ['current' => $ranges['current']];
        $filter = ['field' => 'sessionSource', 'op' => 'partial_regex', 'value' => implode('|', config('report.ai_sources'))];
        $order = [['field' => 'sessions', 'metric' => true, 'desc' => true]];

        $sources = Ga4Rows::sortByCurrent(Ga4Rows::group($this->ga4->runReport($property, new Ga4Report(
            'ai.sources', $ranges, ['sessionSource'], ['sessions'], $filter, $order,
        )), ['sessionSource']), 'sessions');

        $top = fn (string $label, string $dimension, string $configKey) => array_map(
            fn (array $row) => [$dimension => $row['dimensions'][$dimension], 'sessions' => $row['metrics']['sessions']],
            array_slice($this->ga4->runReport($property, new Ga4Report(
                $label, $current, [$dimension], ['sessions'], $filter, $order, Ga4Rows::topN($configKey, $period),
            ))['current'], 0, Ga4Rows::topN($configKey, $period)),
        );

        return [
            'total' => Compare::metric(Ga4Rows::sum($sources, $names, 'sessions')),
            'sources' => array_values(array_map(fn (array $g) => [
                'source' => $g['dimensions']['sessionSource'],
                'sessions' => Ga4Rows::groupMetric($g, $names, 'sessions'),
            ], array_filter(
                $sources,
                fn (array $g) => Ga4Rows::value($g, 'current', 'sessions') > 0 || Ga4Rows::value($g, 'previous', 'sessions') > 0,
            ))),
            'topCountries' => $top('ai.countries', 'country', 'ai_countries'),
            'topLandingPages' => $top('ai.landing_pages', 'landingPage', 'ai_landing_pages'),
        ];
    }
}
