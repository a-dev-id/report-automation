<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Contracts\Ga4Client;
use App\Contracts\SearchConsoleClient;
use App\Enums\ReportType;
use App\Models\Property;
use App\Services\Ga4GscReportBuilder;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Tests\Fakes\FakeData;
use Tests\Fakes\FakeGa4Client;
use Tests\Fakes\FakeSearchConsoleClient;

/** Builds a real payload (through Ga4GscReportBuilder) from the fake Google clients. No network. */
trait BuildsFakePayloads
{
    protected FakeGa4Client $ga4;

    protected FakeSearchConsoleClient $gsc;

    protected function bindFakeGoogle(): void
    {
        $this->ga4 = new FakeGa4Client(FakeData::ga4(), [FakeData::NANDINI_ID => 'IDR', FakeData::KAMANDALU_ID => 'USD']);
        $this->gsc = new FakeSearchConsoleClient(FakeData::gsc());
        $this->app->instance(Ga4Client::class, $this->ga4);
        $this->app->instance(SearchConsoleClient::class, $this->gsc);
    }

    /** @return array<string, mixed> */
    protected function fakePayload(Property $property, ReportType $type, string $now = FakeData::NOW): array
    {
        $now = CarbonImmutable::parse($now, 'Asia/Makassar');
        $period = ReportPeriod::forType($type, $now, new DateTimeZone($property->timezone));

        return $this->app->make(Ga4GscReportBuilder::class)->buildAt($property, $period, $now);
    }

    protected function period(ReportType $type, string $now = FakeData::NOW): ReportPeriod
    {
        return ReportPeriod::forType($type, CarbonImmutable::parse($now, 'Asia/Makassar'), new DateTimeZone('Asia/Makassar'));
    }

    /** A 1x1 PNG, enough for QuickChart fakes and CID embedding. */
    protected function tinyPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
}
