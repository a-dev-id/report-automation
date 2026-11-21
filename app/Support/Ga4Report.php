<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One GA4 runReport request, described without any Google classes so fakes and tests stay simple.
 *
 * $dateRanges is name => [start, end] (Y-m-d). $dimensionFilter uses a small array language:
 *   ['field' => 'hostName', 'op' => 'exact|contains|begins_with|ends_with|regex|partial_regex', 'value' => '...']
 *   ['and' => [filter, ...]]   ['or' => [filter, ...]]   ['not' => filter]
 * All string matches are case-insensitive. $orderBys is a list of
 *   ['field' => 'sessions', 'metric' => true, 'desc' => true]
 *
 * Note: GA4 applies $limit to the rows of all date ranges together. Sections that rank rows
 * therefore use a large limit and rank in PHP. The label names the report in logs and fakes.
 */
final class Ga4Report
{
    /**
     * @param  array<string, array{0: string, 1: string}>  $dateRanges
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     * @param  array<string, mixed>|null  $dimensionFilter
     * @param  list<array{field: string, metric: bool, desc: bool}>  $orderBys
     */
    public function __construct(
        public readonly string $label,
        public readonly array $dateRanges,
        public readonly array $dimensions,
        public readonly array $metrics,
        public readonly ?array $dimensionFilter = null,
        public readonly array $orderBys = [],
        public readonly int $limit = 10000,
    ) {
    }
}
