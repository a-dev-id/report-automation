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
        // One series only: no dashed previous-period line, no gray, no legend.
        $this->assertStringNotContainsString('Previous week', $trend['chart']);
        $this->assertStringNotContainsString('#B0B7C3', $trend['chart']);
        $this->assertStringNotContainsString('borderDash', $trend['chart']);
        $this->assertSame(1, substr_count($trend['chart'], '"borderColor"'));
        $this->assertStringContainsString('"legend":{"display":false}', $trend['chart']);
        $this->assertStringContainsString('"labels":["21 Sep",', $trend['chart']);
        // Labels on the first point, the peak (index 6) and the last point (index 7); the last two touch, so the lower one goes below.
        $this->assertStringContainsString('function(c){return [true,false,false,false,false,false,true,true][c.dataIndex];}', $trend['chart']);
        $this->assertStringContainsString('function(c){return ["top","top","top","top","top","top","top","bottom"][c.dataIndex];}', $trend['chart']);
        $this->assertStringContainsString('"clamp":true', $trend['chart']);

        $this->assertStringContainsString('"type":"bar"', $channels['chart']);
        $this->assertStringContainsString('"indexAxis":"y"', $channels['chart']);                // horizontal
        $this->assertStringContainsString('"legend":{"display":false}', $channels['chart']);     // no legend where not needed
        $this->assertStringContainsString('"labels":["Organic Search","Direct","Paid Search","Organic Social","Referral","Email"]', $channels['chart']);
        $this->assertStringNotContainsString('"Other"', $channels['chart']);                     // top 6 only, no Other bucket

        // Ranked by engaged sessions, and the bars show engaged sessions.
        $this->assertStringContainsString('"labels":["Indonesia","Australia","United States","United Kingdom","Singapore","France","Netherlands","India"]', $countries['chart']);
        $this->assertStringContainsString('"data":[1500.0,1440.0,840.0,270.0,240.0,200.0,175.0,150.0]', $countries['chart']);
        $this->assertStringContainsString('"title":{"display":true,"text":"Top countries by engaged sessions"', $countries['chart']);
        $this->assertStringNotContainsString('"title"', $channels['chart']);
        $this->assertStringNotContainsString('"Other"', $countries['chart']);
    }

    public function test_trend_label_plan_never_puts_two_touching_labels_on_the_same_side(): void
    {
        $plan = fn (array $v) => (new ChartRenderer())->labelPlan($v);

        // Peak in the middle, far from both ends: three labels, all above.
        [$display, $align] = $plan([10.0, 20.0, 30.0, 90.0, 40.0, 30.0, 20.0, 15.0]);
        $this->assertSame([true, false, false, true, false, false, false, true], $display);
        $this->assertSame(array_fill(0, 8, 'top'), $align);

        // Last point right after the peak: the lower (last) label goes below its point.
        [$display, $align] = $plan([10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 90.0, 70.0]);
        $this->assertSame([true, false, false, false, false, false, true, true], $display);
        $this->assertSame('top', $align[6]);
        $this->assertSame('bottom', $align[7]);

        // First point right before the peak: the first (lower) label goes below.
        [, $align] = $plan([50.0, 90.0, 40.0, 30.0, 20.0, 10.0, 5.0, 2.0]);
        $this->assertSame('bottom', $align[0]);
        $this->assertSame('top', $align[1]);

        // The peak is the last point: one label there, nothing to collide with, and the last is not pushed down.
        [$display, $align] = $plan([10.0, 20.0, 30.0, 90.0]);
        $this->assertSame([true, false, false, true], $display);
        $this->assertSame(array_fill(0, 4, 'top'), $align);

        // The peak is the first point.
        [$display, $align] = $plan([90.0, 20.0, 30.0, 40.0]);
        $this->assertSame([true, false, false, true], $display);
        $this->assertSame('top', $align[0]);

        // Two points: both labelled and touching: the lower one goes below.
        [$display, $align] = $plan([10.0, 20.0]);
        $this->assertSame([true, true], $display);
        $this->assertSame(['bottom', 'top'], $align);

        // Peak, first and last all adjacent (twelve-month chart): every touching pair is separated.
        [, $align] = $plan([80.0, 90.0, 10.0, 12.0, 14.0, 16.0, 18.0, 20.0, 22.0, 24.0, 85.0, 88.0]);
        $this->assertSame('bottom', $align[0]);   // below the peak next to it
        $this->assertSame('top', $align[1]);
        $this->assertSame('top', $align[11]);     // 88 is not next to the peak (index 1), nothing touches it
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
