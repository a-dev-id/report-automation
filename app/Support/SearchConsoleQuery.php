<?php

declare(strict_types=1);

namespace App\Support;

/** One Search Console searchanalytics.query request. Dates are Y-m-d. The label is for logs and fakes. */
final class SearchConsoleQuery
{
    /** @param  list<string>  $dimensions  query, page, date, country, device */
    public function __construct(
        public readonly string $label,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly array $dimensions = [],
        public readonly int $rowLimit = 1000,
    ) {
    }
}
