<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
use App\Services\Reports\ChartRenderer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

class ChartRendererTest extends TestCase
{
    use BuildsFakePayloads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
        config(['report.charts.enabled' => true]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function render(ReportType $type = ReportType::Weekly, ?array $payload = null, $property = null): array
    {
        $property ??= FakeData::nandini();

        return (new ChartRenderer())->render($property, $this->period($type), $payload ?? $this->fakePayload($property, $type));
    }

    /** @return list<array<string, mixed>> request bodies sent to QuickChart */
    private function sentBodies(): array
    {
        $bodies = [];
        Http::assertSent(function (Request $request) use (&$bodies) {
            $bodies[] = $request->data();

            return true;
        });

        return $bodies;
    }

    public function test_renders_three_pngs_to_the_expected_paths(): void
    {
        Http::fake(['quickchart.io/*' => Http::response($this->tinyPng(), 200, ['Content-Type' => 'image/png'])]);

        $charts = $this->render();

        $this->assertSame(['trend', 'channels', 'countries'], array_keys($charts));
        foreach ($charts as $name => $path) {
            $this->assertNotNull($path, $name);
            $this->assertFileExists($path);
            $this->assertSame($this->tinyPng(), file_get_contents($path));
            $this->assertSame($this->reportsPath("nandini/weekly/2026-11-09/{$name}.png"), $path);
        }
        Http::assertSentCount(3);
    }

    public function test_monthly_charts_are_stored_under_the_month_start(): void
    {
        Http::fake(['quickchart.io/*' => Http::response($this->tinyPng(), 200)]);

        $charts = $this->render(ReportType::Monthly);

        $this->assertSame($this->reportsPath('nandini/monthly/2026-10-01/trend.png'), $charts['trend']);
    }

    public function test_the_request_follows_the_chart_rules(): void
    {
        Http::fake(['quickchart.io/*' => Http::response($this->tinyPng(), 200)]);
        $property = FakeData::nandini();
        $property->brand_color = '#8A2BE2';

        $this->render(ReportType::Weekly, null, $property);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://quickchart.io/chart');
        $bodies = $this->sentBodies();
        $this->assertCount(3, $bodies);

        foreach ($bodies as $body) {
            $this->assertSame(600, $body['width']);
            $this->assertSame(2, $body['devicePixelRatio']);
            $this->assertSame('white', $body['backgroundColor']);
            $this->assertSame('png', $body['format']);
            $this->assertStringNotContainsString('"pie"', $body['chart']);
            $this->assertStringNotContainsString('doughnut', $body['chart']);
            $this->assertStringNotContainsString('3d', strtolower($body['chart']));
            $this->assertStringContainsString('#8A2BE2', $body['chart']);                       // brand color for the main series
            $this->assertMatchesRegularExpression('/"size":(1[4-9]|[2-9]\d)/', $body['chart']);  // 14px+ fonts
            $this->assertStringContainsString('"datalabels"', $body['chart']);                   // values written on the chart
        }

        [$trend, $channels, $countries] = $bodies;

        $this->assertStringContainsString('"type":"line"', $trend['chart']);
        $this->assertStringContainsString('"Previous week"', $trend['chart']);
        $this->assertStringContainsString('#B0B7C3', $trend['chart']);                           // gray previous period
        $this->assertStringContainsString('"legend":{"display":true', $trend['chart']);
        $this->assertStringContainsString('"labels":["21 Sep",', $trend['chart']);

        $this->assertStringContainsString('"type":"bar"', $channels['chart']);
        $this->assertStringContainsString('"indexAxis":"y"', $channels['chart']);                // horizontal
        $this->assertStringContainsString('"legend":{"display":false}', $channels['chart']);     // no legend where not needed
        $this->assertStringContainsString('"labels":["Organic Search","Direct","Paid Search","Organic Social","Referral","Email"]', $channels['chart']);
        $this->assertStringNotContainsString('"Other"', $channels['chart']);                     // top 6 only, no Other bucket

        $this->assertStringContainsString('"labels":["Indonesia","Australia","United States","United Kingdom","Germany","France","Singapore","Netherlands"]', $countries['chart']);
        $this->assertStringNotContainsString('"Other"', $countries['chart']);
    }

    public function test_the_default_color_is_used_without_a_valid_brand_color(): void
    {
        Http::fake(['quickchart.io/*' => Http::response($this->tinyPng(), 200)]);
        $property = FakeData::nandini();
        $property->brand_color = 'red; drop table';

        $this->render(ReportType::Weekly, null, $property);

        foreach ($this->sentBodies() as $body) {
            $this->assertStringContainsString('#1F6F8B', $body['chart']);
            $this->assertStringNotContainsString('drop table', $body['chart']);
        }
    }

    public function test_base_url_is_configurable(): void
    {
        config(['report.charts.base_url' => 'https://charts.example.test/']);
        Http::fake(['charts.example.test/*' => Http::response($this->tinyPng(), 200)]);

        $this->render();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://charts.example.test/chart');
    }

    public function test_charts_can_be_switched_off(): void
    {
        config(['report.charts.enabled' => false]);
        Http::fake();

        $charts = $this->render();

        $this->assertSame(['trend' => null, 'channels' => null, 'countries' => null], $charts);
        Http::assertNothingSent();
    }

    public function test_a_server_error_returns_null_and_logs_a_warning(): void
    {
        Http::fake(['quickchart.io/*' => Http::response('Bad Gateway', 502)]);
        Log::spy();

        $charts = $this->render();

        $this->assertSame(['trend' => null, 'channels' => null, 'countries' => null], $charts);
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_a_response_that_is_not_a_png_is_a_failure(): void
    {
        Http::fake(['quickchart.io/*' => Http::response('<html>error</html>', 200)]);
        Log::spy();

        $this->assertSame(['trend' => null, 'channels' => null, 'countries' => null], $this->render());
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_a_connection_failure_never_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        Log::spy();

        $charts = $this->render();

        $this->assertSame(['trend' => null, 'channels' => null, 'countries' => null], $charts);
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_one_failing_chart_does_not_stop_the_others(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            // Every attempt for the second chart (channels) fails, first and last succeed.
            return in_array($calls, [2, 3], true) ? Http::response('nope', 500) : Http::response($this->tinyPng(), 200);
        });
        Log::spy();

        $charts = $this->render();

        $this->assertNotNull($charts['trend']);
        $this->assertNull($charts['channels']);
        $this->assertNotNull($charts['countries']);
    }

    public function test_one_retry_is_made_after_a_failure(): void
    {
        Http::fake(['quickchart.io/*' => Http::sequence()->push('busy', 503)->push($this->tinyPng(), 200)->whenEmpty(Http::response($this->tinyPng(), 200))]);

        $charts = $this->render();

        $this->assertNotNull($charts['trend']);   // the retry rescued it
    }

    public function test_charts_without_data_are_skipped_without_a_request(): void
    {
        Http::fake(['quickchart.io/*' => Http::response($this->tinyPng(), 200)]);
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $payload['channels'] = ['error' => ['type' => 'GoogleApiException', 'message' => 'boom']];
        $payload['trend'] = ['granularity' => 'week', 'points' => []];
        unset($payload['countries']);

        $charts = $this->render(ReportType::Weekly, $payload);

        $this->assertSame(['trend' => null, 'channels' => null, 'countries' => null], $charts);
        Http::assertNothingSent();
    }
}
