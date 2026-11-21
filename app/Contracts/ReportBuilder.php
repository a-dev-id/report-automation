<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Property;
use App\Support\ReportPeriod;

interface ReportBuilder
{
    /**
     * Collect the numbers for one property and period (GA4, Search Console, insights).
     * The returned array is stored in reports.payload and given to the email template.
     *
     * @return array<string, mixed>
     */
    public function build(Property $property, ReportPeriod $period): array;
}
