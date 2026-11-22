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
 *   'sources' => each row also has 'share' (percent of AI sessions per range),
 *   'topCountries' => [['country', 'sessions' (current), 'previousSessions', 'change' (Compare::change), 'share' (percent of AI sessions), 'isNew']],
 *   'droppedCountries' => countries in the previous top list that are not in the current one,
 *   'topLandingPages' => [['landingPage', 'sessions']]  (current period, all AI sources together)
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
        $totals = Ga4Rows::sum($sources, $names, 'sessions');

        $share = function (array $group) use ($names, $totals): array {
            $out = [];
            foreach ($names as $range) {
                $out[$range] = $totals[$range] > 0 ? round(Ga4Rows::value($group, $range, 'sessions') / $totals[$range] * 100, 2) : null;
            }

            return $out;
        };

        $landing = Ga4Rows::topN('ai_landing_pages', $period);

        return [
            'total' => Compare::metric($totals),
            'sources' => array_values(array_map(fn (array $g) => [
                'source' => $g['dimensions']['sessionSource'],
                'sessions' => Ga4Rows::groupMetric($g, $names, 'sessions'),
                'share' => $share($g),
            ], array_filter(
                $sources,
                fn (array $g) => Ga4Rows::value($g, 'current', 'sessions') > 0 || Ga4Rows::value($g, 'previous', 'sessions') > 0,
            ))),
        ] + $this->countries($property, $period, $filter, $order, $totals['current']) + [
            'topLandingPages' => array_map(
                fn (array $row) => ['landingPage' => $row['dimensions']['landingPage'], 'sessions' => $row['metrics']['sessions']],
                array_slice($this->ga4->runReport($property, new Ga4Report(
                    'ai.landing_pages', $current, ['landingPage'], ['sessions'], $filter, $order, $landing,
                ))['current'], 0, $landing),
            ),
        ];
    }

    /**
     * Top countries by AI sessions, each compared with the previous period, plus the countries that dropped out of the top list.
     *
     * @param  array<string, mixed>  $filter
     * @param  list<array<string, mixed>>  $order
     * @return array{topCountries: list<array<string, mixed>>, droppedCountries: list<string>}
     */
    private function countries(Property $property, ReportPeriod $period, array $filter, array $order, float $total): array
    {
        $ranges = Ga4Rows::ranges($period);
        $limit = Ga4Rows::topN('ai_countries', $period);

        $groups = Ga4Rows::sortByCurrent(Ga4Rows::group($this->ga4->runReport($property, new Ga4Report(
            'ai.countries',
            ['current' => $ranges['current'], 'previous' => $ranges['previous']],
            ['country'],
            ['sessions'],
            $filter,
            $order,
        )), ['country']), 'sessions');

        $top = array_slice($groups, 0, $limit, true);

        $byPrevious = $groups;
        uasort($byPrevious, fn (array $a, array $b) => Ga4Rows::value($b, 'previous', 'sessions') <=> Ga4Rows::value($a, 'previous', 'sessions'));
        $previousTop = [];
        foreach (array_slice($byPrevious, 0, $limit) as $g) {
            if (Ga4Rows::value($g, 'previous', 'sessions') > 0) {
                $previousTop[] = $g['dimensions']['country'];
            }
        }
        $currentNames = array_map(fn (array $g) => $g['dimensions']['country'], array_values($top));

        return [
            'topCountries' => array_values(array_map(function (array $g) use ($total) {
                $now = Ga4Rows::value($g, 'current', 'sessions');
                $before = Ga4Rows::value($g, 'previous', 'sessions');

                return [
                    'country' => $g['dimensions']['country'],
                    'sessions' => $now,
                    'previousSessions' => $before,
                    'change' => Compare::change($now, $before),
                    'share' => $total > 0 ? round($now / $total * 100, 2) : null,
                    'isNew' => $before <= 0 && $now > 0,
                ];
            }, $top)),
            'droppedCountries' => array_values(array_diff($previousTop, $currentNames)),
        ];
    }
}
