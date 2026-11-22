<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\Report;
use App\Models\ReportDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

class ReportPreviewTest extends TestCase
{
    use BuildsFakePayloads;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
        $this->dir = $this->reportsPath('preview');
        File::deleteDirectory($this->dir);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function nandini(): Property
    {
        return Property::create([
            'name' => 'Nandini', 'slug' => 'nandini', 'ga4_property_id' => FakeData::NANDINI_ID, 'gsc_site_url' => 'sc-domain:nandini.test',
            'booking_engine' => ['type' => 'hostname', 'value' => 'book.nandini.test'], 'ads_source' => 'ga4',
            'currency' => 'IDR', 'timezone' => 'Asia/Makassar', 'active' => false,
        ]);
    }

    private function storedReport(Property $property): Report
    {
        return Report::create([
            'property_id' => $property->id, 'type' => 'weekly', 'period_start' => '2026-11-09', 'period_end' => '2026-11-15',
            'status' => 'pending', 'payload' => $this->fakePayload($property, ReportType::Weekly),
        ]);
    }

    private function preview(array $args = []): array
    {
        $exit = Artisan::call('report:preview', $args + ['property' => 'nandini', 'type' => 'weekly', 'periodStart' => '2026-11-09']);

        return [$exit, Artisan::output()];
    }

    public function test_the_command_renders_the_stored_report_without_calling_google_or_sending_anything(): void
    {
        $property = $this->nandini();
        $this->storedReport($property);
        $calls = count($this->ga4->calls) + count($this->gsc->calls);   // the fixture payload itself made these calls

        [$exit, $output] = $this->preview();

        $this->assertSame(0, $exit);
        $path = $this->reportsPath('preview/nandini-weekly-2026-11-09.html');
        $this->assertFileExists($path);
        $this->assertStringContainsString('Subject: Nandini Weekly Traffic Report: 9 to 15 Nov 2026', $output);
        $this->assertStringContainsString("File:    {$path}", $output);
        $this->assertStringContainsString('Payload: stored report', $output);
        $this->assertStringContainsString('Traffic Quality Insight', file_get_contents($path));
        $this->assertStringContainsString('10,000', file_get_contents($path));

        $this->assertSame($calls, count($this->ga4->calls) + count($this->gsc->calls), 'no Google call');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(0, ReportDelivery::count());
        $this->assertSame(1, Report::count());                       // nothing created
        $this->assertFalse($property->fresh()->active);              // never enabled
    }

    public function test_out_option_writes_to_the_given_file(): void
    {
        $this->storedReport($this->nandini());
        $out = $this->reportsPath('preview/custom/my-preview.html');

        [$exit, $output] = $this->preview(['--out' => $out]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($out);
        $this->assertStringContainsString("File:    {$out}", $output);
        $this->assertFileDoesNotExist($this->reportsPath('preview/nandini-weekly-2026-11-09.html'));
    }

    public function test_without_a_stored_report_it_builds_the_payload_caches_it_and_reuses_the_cache(): void
    {
        $this->nandini();

        [$exit, $output] = $this->preview();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Payload: built now', $output);
        $this->assertFileExists($this->reportsPath('preview/nandini-weekly-2026-11-09.payload.json'));
        $this->assertSame(0, Report::count());                       // a preview never creates a report row
        $this->assertNotEmpty($this->ga4->calls);

        $callsAfterBuild = count($this->ga4->calls);
        [, $second] = $this->preview();
        $this->assertStringContainsString('Payload: cached preview payload', $second);
        $this->assertSame($callsAfterBuild, count($this->ga4->calls));

        [, $third] = $this->preview(['--fresh' => true]);
        $this->assertStringContainsString('Payload: built now', $third);
        $this->assertGreaterThan($callsAfterBuild, count($this->ga4->calls));

        Mail::assertNothingSent();
        $this->assertSame(0, ReportDelivery::count());
    }

    public function test_no_charts_option_skips_charts_and_shows_tables_without_a_missing_chart_note(): void
    {
        config(['report.charts.enabled' => true]);
        Http::fake(['quickchart.io/*' => Http::response($this->tinyPng(), 200)]);
        $this->storedReport($this->nandini());

        $this->preview(['--no-charts' => true]);

        Http::assertNothingSent();
        $html = file_get_contents($this->reportsPath('preview/nandini-weekly-2026-11-09.html'));
        $this->assertStringContainsString('Week of', $html);
        $this->assertStringNotContainsString('could not be generated', $html);
        $this->assertTrue(config('report.charts.enabled'), 'the switch is restored');
    }

    public function test_with_charts_the_images_are_inlined_as_data_uris(): void
    {
        config(['report.charts.enabled' => true]);
        Http::fake(['quickchart.io/*' => Http::response($this->tinyPng(), 200)]);
        $this->storedReport($this->nandini());

        $this->preview();

        $html = file_get_contents($this->reportsPath('preview/nandini-weekly-2026-11-09.html'));
        $this->assertSame(3, substr_count($html, 'src="data:image/png;base64,'));
        $this->assertStringNotContainsString('cid:', $html);
    }

    public function test_the_command_rejects_bad_input(): void
    {
        $this->nandini();

        [$exit, $output] = $this->preview(['type' => 'daily']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('weekly or monthly', $output);

        [$exit, $output] = $this->preview(['property' => 'nope']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString("No property with slug 'nope'", $output);

        [$exit, $output] = $this->preview(['type' => 'monthly', 'periodStart' => '2026-10-02']);   // not the 1st
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('must start on the 1st', $output);

        [$exit, $output] = $this->preview(['periodStart' => '2026-13-45']);
        $this->assertNotSame(0, $exit);
    }

    public function test_a_weekly_preview_accepts_any_start_day_and_covers_seven_days(): void
    {
        $this->nandini();

        [$exit, $output] = $this->preview(['periodStart' => '2026-11-08']);   // a Sunday: 8 to 14 Nov

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Subject: Nandini Weekly Traffic Report: 8 to 14 Nov 2026', $output);
    }

    public function test_monthly_preview_for_a_given_month(): void
    {
        $property = $this->nandini();
        Report::create([
            'property_id' => $property->id, 'type' => 'monthly', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'status' => 'pending', 'payload' => $this->fakePayload($property, ReportType::Monthly),
        ]);

        [$exit, $output] = $this->preview(['type' => 'monthly', 'periodStart' => '2026-10-01']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Subject: Nandini Monthly Traffic Report: October 2026', $output);
        $this->assertFileExists($this->reportsPath('preview/nandini-monthly-2026-10-01.html'));
    }

    // ---- route -------------------------------------------------------------------------------

    public function test_the_route_is_a_404_outside_local(): void
    {
        $this->storedReport($this->nandini());

        $this->assertSame('testing', app()->environment());
        $this->get('/reports/preview/nandini/weekly/2026-11-09')->assertNotFound();

        foreach (['production', 'staging'] as $env) {
            $this->app['env'] = $env;
            $this->get('/reports/preview/nandini/weekly/2026-11-09')->assertNotFound();
        }
    }

    public function test_the_route_returns_the_html_in_local(): void
    {
        $this->storedReport($this->nandini());
        $this->app['env'] = 'local';

        $response = $this->get('/reports/preview/nandini/weekly/2026-11-09');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('Traffic Quality Insight', false);
        $response->assertSee('Nandini Weekly Traffic Report: 9 to 15 Nov 2026', false);
        Mail::assertNothingSent();
        $this->assertSame(0, ReportDelivery::count());
    }

    public function test_the_route_404s_for_unknown_or_invalid_input_even_in_local(): void
    {
        $this->storedReport($this->nandini());
        $this->app['env'] = 'local';

        $this->get('/reports/preview/unknown/weekly/2026-11-09')->assertNotFound();
        $this->get('/reports/preview/nandini/daily/2026-11-09')->assertNotFound();
        $this->get('/reports/preview/nandini/weekly/not-a-date')->assertNotFound();
        $this->get('/reports/preview/nandini/monthly/2026-10-02')->assertNotFound();   // monthly must be the 1st
    }

    public function test_the_route_can_skip_charts(): void
    {
        config(['report.charts.enabled' => true]);
        Http::fake();
        $this->storedReport($this->nandini());
        $this->app['env'] = 'local';

        $this->get('/reports/preview/nandini/weekly/2026-11-09?charts=0')->assertOk()->assertSee('Week of', false);

        Http::assertNothingSent();
    }

    public function test_the_mail_class_is_not_used_by_a_preview(): void
    {
        $this->storedReport($this->nandini());

        $this->preview();

        Mail::assertNotSent(ReportMail::class);
        Mail::assertNotQueued(ReportMail::class);
    }
}
