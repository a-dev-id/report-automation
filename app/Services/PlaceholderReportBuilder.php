<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ReportBuilder;
use App\Models\Property;
use App\Support\ReportPeriod;
use RuntimeException;

/**
 * Stand-in until the GA4 and Search Console services exist (tracker phases 3 and 4).
 * It always fails, so an activated property can never send an empty report by accident.
 * Replace the binding in ReportServiceProvider with the real builder.
 */
class PlaceholderReportBuilder implements ReportBuilder
{
    public function build(Property $property, ReportPeriod $period): array
    {
        throw new RuntimeException('The report builder is not implemented yet. Build the GA4 and Search Console services first.');
    }
}
