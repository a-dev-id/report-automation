<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Jobs\BuildAndSendReport;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\Recipient;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

/** The job renders the charts once, hands them to every ReportMail, and a chart failure never fails the report. */
class ReportJobEmailTest extends TestCase
{
    use BuildsFakePayloads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function pendingReport(): Report
    {
        $property = Property::create([
            'name' => 'Nandini', 'slug' => 'nandini', 'ga4_property_id' => FakeData::NANDINI_ID, 'gsc_site_url' => 'sc-domain:nandini.test',
            'booking_engine' => ['type' => 'hostname', 'value' => 'book.nandini.test'], 'ads_source' => 'ga4',
            'currency' => 'IDR', 'timezone' => 'Asia/Makassar',
        ]);
        foreach (['a@example.test', 'b@example.test'] as $email) {
            Recipient::create(['property_id' => $property->id, 'email' => $email]);
        }

        return Report::create([
            'property_id' => $property->id, 'type' => 'weekly', 'period_start' => '2026-11-09', 'period_end' => '2026-11-15',
            'status' => ReportStatus::Pending->value,
        ]);
    }

    public function test_the_job_sends_the_rich_email_with_shared_charts(): void
    {
        config(['report.charts.enabled' => true]);
        Http::fake(['quickchart.io/*' => Http::response($this->tinyPng(), 200)]);
        Mail::fake();
        $report = $this->pendingReport();
        $report->update(['payload' => $this->fakePayload($report->property, ReportType::Weekly)]);   // stored payload: no Google call

        (new BuildAndSendReport($report->id))->handle($this->app->make(\App\Contracts\ReportBuilder::class));

        $this->assertSame(ReportStatus::Sent, $report->fresh()->status);
        Mail::assertSent(ReportMail::class, 2);
        Mail::assertSent(ReportMail::class, fn (ReportMail $m) => $m->hasTo('a@example.test') && ! in_array(null, $m->charts, true));
        Http::assertSentCount(3);   // rendered once for both recipients, not once per recipient
    }

    public function test_a_chart_failure_never_fails_the_report(): void
    {
        config(['report.charts.enabled' => true]);
        Http::fake(['quickchart.io/*' => Http::response('down', 503)]);
        Mail::fake();
        $report = $this->pendingReport();
        $report->update(['payload' => $this->fakePayload($report->property, ReportType::Weekly)]);

        (new BuildAndSendReport($report->id))->handle($this->app->make(\App\Contracts\ReportBuilder::class));

        $this->assertSame(ReportStatus::Sent, $report->fresh()->status);
        Mail::assertSent(ReportMail::class, 2);
        Mail::assertSent(ReportMail::class, fn (ReportMail $m) => $m->charts === ['trend' => null, 'channels' => null, 'countries' => null]);
    }

    public function test_the_job_builds_sends_and_renders_with_the_log_style_mailer(): void
    {
        // End to end with the "array" mailer (nothing leaves the machine): build, render and deliver.
        config(['mail.default' => 'array']);
        $report = $this->pendingReport();

        (new BuildAndSendReport($report->id))->handle($this->app->make(\App\Contracts\ReportBuilder::class));

        $this->assertSame(ReportStatus::Sent, $report->fresh()->status);
        $messages = app('mail.manager')->mailer()->getSymfonyTransport()->messages();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Traffic Quality Insight', $messages->first()->getOriginalMessage()->getHtmlBody());
        $this->assertSame('Nandini Weekly Traffic Report: 9 to 15 Nov 2026', $messages->first()->getOriginalMessage()->getSubject());
    }
}
