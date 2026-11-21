<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\Ga4Client;
use App\Exceptions\PropertyNotConfiguredException;
use App\Models\Property;
use App\Support\Ga4Report;
use DateTimeImmutable;
use Throwable;

/**
 * In-memory GA4 client. Fixtures are keyed by GA4 property ID, then by the report label, then by
 * date range name, so a request for one hotel can never return another hotel's rows. Only the
 * ranges that were requested are returned. Trend reports are generated from the requested range.
 */
final class FakeGa4Client implements Ga4Client
{
    /** @var list<array{propertyId: string, report: Ga4Report}> */
    public array $calls = [];

    /** @var array<string, Throwable> label => exception */
    private array $failures = [];

    /** @var array<string, string> */
    private array $reported = [];

    /**
     * @param  array<string, array<string, array<string, list<array>>>>  $fixtures  propertyId => label => range => rows
     * @param  array<string, string>  $currencies  propertyId => currencyCode that GA4 reports for it
     */
    public function __construct(private array $fixtures, private array $currencies = [])
    {
    }

    public function currencyCode(Property $property): ?string
    {
        return $this->reported[trim((string) $property->ga4_property_id)] ?? null;
    }

    public function failOn(string $label, Throwable $e): self
    {
        $this->failures[$label] = $e;

        return $this;
    }

    public function runReport(Property $property, Ga4Report $report): array
    {
        $id = trim((string) $property->ga4_property_id);
        if ($id === '') {
            throw new PropertyNotConfiguredException("Property '{$property->slug}' has no valid GA4 property ID.");
        }

        $this->calls[] = ['propertyId' => $id, 'report' => $report];
        if (isset($this->currencies[$id])) {
            $this->reported[$id] = $this->currencies[$id];
        }

        if (isset($this->failures[$report->label])) {
            throw $this->failures[$report->label];
        }

        if (str_starts_with($report->label, 'trend.')) {
            return $this->trend($id, $report);
        }

        $fixture = $this->fixtures[$id][$report->label] ?? [];
        $result = [];
        foreach (array_keys($report->dateRanges) as $range) {
            $rows = $fixture[$range] ?? [];
            // GA4 returns rows in the requested order (before the limit is applied).
            foreach (array_reverse($report->orderBys) as $order) {
                usort($rows, fn (array $a, array $b) => $order['desc']
                    ? ($b[$order['metric'] ? 'metrics' : 'dimensions'][$order['field']] ?? 0) <=> ($a[$order['metric'] ? 'metrics' : 'dimensions'][$order['field']] ?? 0)
                    : ($a[$order['metric'] ? 'metrics' : 'dimensions'][$order['field']] ?? 0) <=> ($b[$order['metric'] ? 'metrics' : 'dimensions'][$order['field']] ?? 0));
            }
            $result[$range] = array_slice($rows, 0, $report->limit);
        }

        return $result;
    }

    /** @return list<Ga4Report> */
    public function reportsFor(string $propertyId, ?string $label = null): array
    {
        $reports = [];
        foreach ($this->calls as $call) {
            if ($call['propertyId'] === $propertyId && ($label === null || $call['report']->label === $label)) {
                $reports[] = $call['report'];
            }
        }

        return $reports;
    }

    /** One row per bucket of the requested range with deterministic numbers, minus every 4th bucket. */
    private function trend(string $id, Ga4Report $report): array
    {
        $weekly = $report->dimensions[0] === 'isoYearIsoWeek';
        $scale = (int) substr($id, 0, 1);
        [$start, $end] = $report->dateRanges['current'];

        $rows = [];
        $i = 0;
        for ($d = new DateTimeImmutable($start); $d <= new DateTimeImmutable($end); $d = $d->modify($weekly ? '+1 week' : '+1 month')) {
            if (++$i % 4 === 0) {
                continue; // a bucket with no traffic: GA4 returns no row
            }
            $rows[] = [
                'dimensions' => [$report->dimensions[0] => $d->format($weekly ? 'oW' : 'Ym')],
                'metrics' => ['sessions' => (float) ($i * 100 * $scale), 'totalUsers' => (float) ($i * 70 * $scale)],
            ];
        }

        return ['current' => $rows];
    }
}
