<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\SearchConsoleClient;
use App\Exceptions\PropertyNotConfiguredException;
use App\Models\Property;
use App\Support\SearchConsoleQuery;
use DateTimeImmutable;
use Throwable;

/**
 * In-memory Search Console client. Fixtures are keyed by site URL, then by query label
 * ("totals.current", "queries.previous", ...). Honors rowLimit. Daily rows are generated.
 */
final class FakeSearchConsoleClient implements SearchConsoleClient
{
    /** @var list<array{siteUrl: string, query: SearchConsoleQuery}> */
    public array $calls = [];

    private ?Throwable $failure = null;

    /** @param  array<string, array<string, list<array>>>  $fixtures  siteUrl => label => rows */
    public function __construct(private array $fixtures)
    {
    }

    public function failWith(Throwable $e): self
    {
        $this->failure = $e;

        return $this;
    }

    public function query(Property $property, SearchConsoleQuery $query): array
    {
        $site = trim((string) $property->gsc_site_url);
        if ($site === '') {
            throw new PropertyNotConfiguredException("Property '{$property->slug}' has no Search Console site.");
        }

        $this->calls[] = ['siteUrl' => $site, 'query' => $query];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($query->label === 'daily.current') {
            $scale = str_contains($site, 'kamandalu') ? 2 : 1;
            $rows = [];
            for ($d = new DateTimeImmutable($query->startDate); $d <= new DateTimeImmutable($query->endDate); $d = $d->modify('+1 day')) {
                if ((int) $d->format('j') % 5 === 0) {
                    continue; // no row on some days: filled with zeros by the section
                }
                $clicks = (float) ((int) $d->format('j') * $scale);
                $rows[] = ['keys' => [$d->format('Y-m-d')], 'clicks' => $clicks, 'impressions' => $clicks * 20, 'ctr' => 0.05, 'position' => 10.0];
            }

            return $rows;
        }

        return array_slice($this->fixtures[$site][$query->label] ?? [], 0, $query->rowLimit);
    }
}
