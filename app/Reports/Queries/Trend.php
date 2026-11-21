<?php

declare(strict_types=1);

namespace App\Reports\Queries;

use App\Contracts\Ga4Client;
use App\Enums\ReportType;
use App\Models\Property;
use App\Support\Ga4Report;
use App\Support\ReportPeriod;
use DateTimeImmutable;

/**
 * Sessions and users per week (last 8 ISO weeks, weekly report) or per month (last 12 months,
 * monthly report), ending with the reported period. Buckets with no traffic are present with zeros.
 *
 * Output: ['granularity' => 'week'|'month', 'points' => [['key', 'start', 'end', 'sessions', 'totalUsers'], ...]]
 * oldest first. key is the GA4 value (isoYearIsoWeek like "202646", or yearMonth like "202610").
 *
 * Users are counted per bucket, so they cannot be added across buckets.
 */
final class Trend
{
    public function __construct(private readonly Ga4Client $ga4)
    {
    }

    public function fetch(Property $property, ReportPeriod $period): array
    {
        $weekly = $period->type === ReportType::Weekly;
        $count = $weekly ? 8 : 12;

        /** @var list<array{key: string, start: DateTimeImmutable, end: DateTimeImmutable}> $buckets */
        $buckets = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $start = $weekly ? $period->start->modify("-{$i} weeks") : $period->start->modify("-{$i} months");
            $end = $weekly ? $start->modify('+6 days') : $start->modify('last day of this month');
            $buckets[] = ['key' => $start->format($weekly ? 'oW' : 'Ym'), 'start' => $start, 'end' => $end];
        }

        $result = $this->ga4->runReport($property, new Ga4Report(
            $weekly ? 'trend.weekly' : 'trend.monthly',
            ['current' => [$buckets[0]['start']->format('Y-m-d'), $period->endDate()]],
            [$weekly ? 'isoYearIsoWeek' : 'yearMonth'],
            ['sessions', 'totalUsers'],
        ));

        $dimension = $weekly ? 'isoYearIsoWeek' : 'yearMonth';
        $byKey = [];
        foreach ($result['current'] as $row) {
            $byKey[$row['dimensions'][$dimension]] = $row['metrics'];
        }

        return [
            'granularity' => $weekly ? 'week' : 'month',
            'points' => array_map(fn (array $b) => [
                'key' => $b['key'],
                'start' => $b['start']->format('Y-m-d'),
                'end' => $b['end']->format('Y-m-d'),
                'sessions' => $byKey[$b['key']]['sessions'] ?? 0.0,
                'totalUsers' => $byKey[$b['key']]['totalUsers'] ?? 0.0,
            ], $buckets),
        ];
    }
}
