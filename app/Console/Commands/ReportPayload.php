<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReportType;
use App\Models\Property;
use App\Services\Ga4GscReportBuilder;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

class ReportPayload extends Command
{
    protected $signature = 'report:payload {property : Property slug} {type : weekly or monthly} {--now= : Pretend it is this date/time, e.g. 2026-11-17}';

    protected $description = 'Build the report payload for the latest complete period and print it as JSON (nothing is saved or emailed)';

    public function handle(Ga4GscReportBuilder $builder): int
    {
        $type = ReportType::tryFrom((string) $this->argument('type'));
        if ($type === null) {
            $this->error('Type must be weekly or monthly.');

            return self::INVALID;
        }

        $property = Property::query()->where('slug', $this->argument('property'))->first();
        if ($property === null) {
            $this->error("No property with slug '{$this->argument('property')}'.");

            return self::FAILURE;
        }

        try {
            $tz = new DateTimeZone($property->timezone);
            $now = $this->option('now') ? CarbonImmutable::parse((string) $this->option('now'), $tz) : CarbonImmutable::now();
            $period = ReportPeriod::forType($type, $now, $tz);

            $payload = $builder->buildAt($property, $period, $now);
        } catch (Throwable $e) {
            $this->error(class_basename($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return self::SUCCESS;
    }
}
