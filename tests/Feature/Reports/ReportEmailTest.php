<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

class ReportEmailTest extends TestCase
{
    use BuildsFakePayloads;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
        $this->dir = $this->reportsPath('test-email');
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function property(array $overrides = []): Property
    {
        return Property::create($overrides + [
            'name' => 'Nandini', 'slug' => 'nandini', 'ga4_property_id' => FakeData::NANDINI_ID, 'gsc_site_url' => 'sc-domain:nandini.test',
            'booking_engine' => ['type' => 'hostname', 'value' => 'book.nandini.test'], 'ads_source' => 'ga4',
            'currency' => 'IDR', 'timezone' => 'Asia/Makassar', 'brand_color' => '#8A2BE2',
        ]);
    }

    private function report(Property $property, array $payload, ReportType $type = ReportType::Weekly): Report
    {
        $period = $this->period($type);

        return Report::create([
            'property_id' => $property->id, 'type' => $type->value, 'period_start' => $period->startDate(), 'period_end' => $period->endDate(),
            'status' => 'pending', 'payload' => $payload,
        ]);
    }

    /** Renders the mail the way a test can: returns [mail, html, text]. */
    private function render(Report $report, ?array $charts = null): array
    {
        $mail = new ReportMail($report->load('property'), $report->property, $charts ?? ['trend' => null, 'channels' => null, 'countries' => null]);
        $html = $mail->render();
        $text = view('emails.report-text', $mail->content()->with)->render();

        return [$mail, $html, $text];
    }

    private function weekly(array $payloadChanges = [], array $propertyOverrides = []): array
    {
        $property = $this->property($propertyOverrides);
        $payload = array_replace_recursive($this->fakePayload($property, ReportType::Weekly), $payloadChanges);

        return [$property, $this->report($property, $payload)];
    }

    public function test_subject_and_sections_in_order(): void
    {
        [, $report] = $this->weekly();

        [$mail, $html] = $this->render($report);

        $this->assertSame('Nandini Weekly Traffic Report: 9 to 15 Nov 2026', $mail->envelope()->subject);
        $this->assertStringContainsString('<title>Nandini Weekly Traffic Report: 9 to 15 Nov 2026</title>', $html);

        $order = ['Traffic Quality Insight', '>Sessions</td>', 'Sessions, last 8 weeks', 'Traffic by channel', 'Top countries by engaged sessions', 'Country performance',
            'Paid advertising', 'Traffic from AI assistants', 'padding:0 0 12px 0;">Search Console</td>', 'Data notes'];
        $last = -1;
        foreach ($order as $marker) {
            $pos = strpos($html, $marker);
            $this->assertNotFalse($pos, "missing: {$marker}");
            $this->assertGreaterThan($last, $pos, "out of order: {$marker}");
            $last = $pos;
        }
    }

    public function test_figures_are_formatted_and_changes_are_coloured_arrows(): void
    {
        [, $report] = $this->weekly();

        [, $html] = $this->render($report);

        $this->assertStringContainsString('10,000', $html);                // thousands separator
        $this->assertStringContainsString('62.0%', $html);                 // engagement rate, one decimal
        $this->assertStringContainsString('IDR 48,000,000', $html);        // IDR without decimals
        $this->assertStringContainsString('2.50%', $html);                 // Search Console CTR
        $this->assertStringContainsString('color:#15803D', $html);         // green (#15803D) for a positive change
        $this->assertStringContainsString('▲ +25.0%', $html);
        $this->assertStringContainsString('+2.0 pts', $html);              // engagement rate change in points
        $this->assertStringContainsString('vs last week', $html);
        $this->assertStringNotContainsString('vs Oct', $html);             // weekly: no year-ago line
        $this->assertStringContainsString('background:#8A2BE2', $html);    // brand color in the header
    }

    public function test_ad_cost_changes_are_neutral_and_campaign_noise_is_not_shown(): void
    {
        [, $report] = $this->weekly();

        [, $html] = $this->render($report);

        // Ad cost fell in the fixture (5.0M vs 4.8M is up): either way it is neither good nor bad, so gray.
        $flat = preg_replace('/\s+/', ' ', $html);
        $this->assertSame(1, preg_match('/>Cost<\/td>.*?font-weight:bold; (color:#[0-9A-F]{6})/', $flat, $m));
        $this->assertSame('color:#6B7280', $m[1]);

        // Cost per click is on the neutral list too (config report.styling.neutral_metrics): gray whatever its direction.
        $this->assertSame(1, preg_match('/>Cost per click<\/td>.*?font-weight:bold; (color:#[0-9A-F]{6})/', $flat, $m));
        $this->assertSame('color:#6B7280', $m[1]);

        // Every other paid metric keeps its green or red.
        $this->assertSame(1, preg_match('/>Clicks<\/td>.*?font-weight:bold; (color:#[0-9A-F]{6})/', $flat, $m));
        $this->assertContains($m[1], ['color:#15803D', 'color:#B91C1C']);

        // The fixture has campaigns "(not set)" and "brand-aus": only a real campaign name is shown.
        $this->assertStringNotContainsString('(not set)', $html);
        $this->assertStringContainsString('google / cpc · brand-aus', $html);
    }

    public function test_search_console_position_improvement_is_green(): void
    {
        [, $report] = $this->weekly();

        [, $html] = $this->render($report);

        // Position fell from 13.1 to 12.3: lower is better, so a down arrow in green.
        $this->assertMatchesRegularExpression('/color:#15803D[^>]*>▼ -6\.1%/', $html);
    }

    public function test_monthly_report_also_shows_year_ago(): void
    {
        $property = $this->property();
        $report = $this->report($property, $this->fakePayload($property, ReportType::Monthly), ReportType::Monthly);

        [$mail, $html, $text] = $this->render($report);

        $this->assertSame('Nandini Monthly Traffic Report: October 2026', $mail->envelope()->subject);
        $this->assertStringContainsString('vs Oct 2025', $html);
        $this->assertStringContainsString('vs last month', $html);
        $this->assertStringContainsString('Sessions, last 12 months', $html);
        $this->assertStringContainsString('vs Oct 2025', $text);
    }

    public function test_html_is_table_based_with_inline_css_only(): void
    {
        [, $report] = $this->weekly();

        [, $html] = $this->render($report);

        $this->assertStringContainsString('max-width:640px', $html);
        $this->assertStringContainsString('<table role="presentation"', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString(' class=', $html);
        $this->assertStringNotContainsString('display:flex', $html);
        $this->assertStringNotContainsString('display:grid', $html);
        $this->assertStringContainsString('Arial, Helvetica, sans-serif', $html);

        // The hidden preheader is 1px on purpose (it is not visible text), so it is left out of the font-size check.
        $visible = preg_replace('#<div style="display:none;.*?</div>#s', '', $html);
        preg_match_all('/font-size:(\d+)px/', $visible, $sizes);
        $body = array_filter($sizes[1], fn ($s) => (int) $s < 14);
        $this->assertNotEmpty($sizes[1]);
        // Anything under 14px is a label (13px) or a data note / footer (12px), never body text.
        $this->assertEmpty(array_filter($body, fn ($s) => (int) $s < 12));
    }

    public function test_without_charts_every_chart_section_has_a_table_fallback_and_a_note(): void
    {
        config(['report.charts.enabled' => true]);
        [, $report] = $this->weekly();

        [, $html, $text] = $this->render($report);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Week of', $html);                      // trend table
        $this->assertStringContainsString('Share</th>', $html);                   // countries table
        $this->assertStringContainsString('Channel</th>', $html);                 // channels table is always there
        $this->assertStringContainsString('The trend, channels, countries charts could not be generated, so the figures are shown as a table.', $html);
        $this->assertStringContainsString('could not be generated', $text);
    }

    public function test_charts_are_embedded_as_cid_images_when_sent(): void
    {
        $paths = [];
        foreach (['trend', 'channels', 'countries'] as $name) {
            $paths[$name] = "{$this->dir}/{$name}.png";
            file_put_contents($paths[$name], $this->tinyPng());
        }
        [, $report] = $this->weekly();
        config(['mail.default' => 'array']);

        Mail::to('someone@example.test')->send(new ReportMail($report->load('property'), $report->property, $paths));

        /** @var Email $email */
        $email = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $html = $email->getHtmlBody();
        $this->assertSame(3, substr_count($html, 'src="cid:'));
        $this->assertStringNotContainsString('http://', str_replace('http://www.w3.org', '', $html));   // no external image hosting
        $this->assertStringNotContainsString('quickchart.io', $html);
        $this->assertCount(3, $email->getAttachments());
        $this->assertStringNotContainsString('Week of', $html);                // charts replace the fallback table
        $this->assertStringContainsString('TRAFFIC QUALITY INSIGHT', (string) $email->getTextBody());
        $this->assertSame('Nandini Weekly Traffic Report: 9 to 15 Nov 2026', $email->getSubject());
    }

    public function test_an_error_section_shows_a_neutral_box_and_the_layout_survives(): void
    {
        $error = ['error' => ['type' => 'GoogleApiException', 'message' => 'INVALID_ARGUMENT secret details']];
        [, $report] = $this->weekly();
        $payload = $report->payload;
        foreach (['channels', 'countries', 'country_performance', 'paid', 'ai_traffic', 'trend'] as $key) {
            $payload[$key] = $error;
        }
        $report->update(['payload' => $payload]);

        [, $html, $text] = $this->render($report->fresh());

        $this->assertSame(6, substr_count($html, 'This section is temporarily unavailable.'));
        $this->assertSame(6, substr_count($text, 'This section is temporarily unavailable.'));
        $this->assertStringNotContainsString('INVALID_ARGUMENT', $html);      // the technical message is not shown
        $this->assertStringNotContainsString('INVALID_ARGUMENT', $text);
        $this->assertStringContainsString('Search Console', $html);           // later sections still render
        $this->assertStringContainsString('Data notes', $html);
        $this->assertSame(substr_count($html, '<table'), substr_count($html, '</table>'));
        $this->assertSame(substr_count($html, '<tr'), substr_count($html, '</tr>'));
    }

    public function test_empty_sections_are_omitted_not_blank(): void
    {
        $property = $this->property(['slug' => 'kamandalu', 'name' => 'Kamandalu', 'ga4_property_id' => FakeData::KAMANDALU_ID, 'ads_source' => 'none', 'currency' => 'USD', 'gsc_site_url' => 'https://kamandalu.test/']);
        $payload = $this->fakePayload($property, ReportType::Weekly);
        $payload['ai_traffic']['total'] = ['current' => 0.0, 'previous' => 0.0];
        $payload['country_performance'] = [];
        $payload['search_console'] = ['hasData' => false, 'totals' => null, 'truncated' => false, 'through' => null, 'topQueries' => [], 'topPages' => []];
        $report = $this->report($property, $payload);

        [, $html] = $this->render($report);

        $this->assertStringNotContainsString('Paid advertising', $html);       // ads_source none
        $this->assertStringNotContainsString('Traffic from AI assistants', $html);
        $this->assertStringNotContainsString('Country performance', $html);
        $this->assertStringNotContainsString('Search Console', $html);
        $this->assertStringContainsString('Traffic by channel', $html);
        $this->assertStringContainsString('USD', $html);
    }

    public function test_data_notes_cover_truncation_time_zone_and_quality_alerts(): void
    {
        $property = $this->property();
        $payload = $this->fakePayload($property, ReportType::Weekly, '2026-11-17 09:00:00');   // Search Console is a day short
        $payload['quality_alerts'] = [['code' => 'country_sum_mismatch', 'message' => 'Country rows exceed the site total.', 'details' => []]];
        $report = $this->report($property, $payload);

        [, $html, $text] = $this->render($report);

        foreach ([$html, $text] as $version) {
            $this->assertStringContainsString('Search Console data through 14 Nov 2026.', $version);
            $this->assertStringContainsString('Search Console may differ slightly from the web interface because of time zone.', $version);
            $this->assertStringContainsString('Country rows exceed the site total.', $version);
        }
    }

    public function test_no_truncation_note_when_search_console_is_complete(): void
    {
        [, $report] = $this->weekly();

        [, $html] = $this->render($report);

        $this->assertStringNotContainsString('Search Console data through', $html);
        $this->assertStringContainsString('because of time zone', $html);
    }

    public function test_the_plain_text_version_has_the_same_data(): void
    {
        [, $report] = $this->weekly();

        [, $html, $text] = $this->render($report);

        foreach (['10,000', '62.0%', 'IDR 48,000,000', '+25.0%', 'Organic Search', 'nandini ubud', 'Australia', 'chatgpt.com', '2.50%', 'Impressions'] as $needle) {
            $this->assertStringContainsString($needle, $html, "html: {$needle}");
            $this->assertStringContainsString($needle, $text, "text: {$needle}");
        }

        $this->assertStringContainsString('possible bots', $html);
        $this->assertStringContainsString('possible bots', $text);
        $this->assertStringContainsString('Generated', $html);
        $this->assertStringContainsString('Asia/Makassar', $text);
        $this->assertSame($text, strip_tags($text));   // no HTML tags in the text version
        $this->assertStringNotContainsString('&amp;', $text);
    }

    public function test_property_text_is_escaped_in_html(): void
    {
        [, $report] = $this->weekly([], ['name' => 'Nandini <script>alert(1)</script>']);

        [, $html] = $this->render($report);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_logo_is_shown_only_when_the_property_has_one(): void
    {
        [, $report] = $this->weekly();
        [, $without] = $this->render($report);
        $this->assertStringNotContainsString('<img', $without);

        Storage::fake('public');
        Storage::disk('public')->put('logos/nandini.png', $this->tinyPng());
        $report->property->update(['logo_path' => 'logos/nandini.png']);

        [, $with] = $this->render($report->fresh());
        $this->assertSame(1, substr_count($with, '<img'));
        $this->assertStringContainsString('alt="Nandini"', $with);

        $report->property->update(['logo_path' => 'logos/missing.png']);
        [, $missing] = $this->render($report->fresh());
        $this->assertStringNotContainsString('<img', $missing);   // a missing file means no logo, not an error
    }
}
