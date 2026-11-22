<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\Report;
use App\Services\Reports\ReportViewData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

/** Outlook (Word engine) and Gmail rules for the HTML email, checked on the real template output. */
class EmailRobustnessTest extends TestCase
{
    use BuildsFakePayloads;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
        $this->dir = $this->reportsPath('robust');
        File::ensureDirectoryExists($this->dir);
    }

    private function report(array $propertyOverrides = [], ReportType $type = ReportType::Weekly): Report
    {
        $property = Property::create($propertyOverrides + [
            'name' => 'Nandini', 'slug' => 'nandini', 'ga4_property_id' => FakeData::NANDINI_ID, 'gsc_site_url' => 'sc-domain:nandini.test',
            'booking_engine' => ['type' => 'hostname', 'value' => 'book.nandini.test'], 'ads_source' => 'ga4',
            'currency' => 'IDR', 'timezone' => 'Asia/Makassar', 'brand_color' => '#1F6F8B',
        ]);
        $period = $this->period($type);

        return Report::create([
            'property_id' => $property->id, 'type' => $type->value, 'period_start' => $period->startDate(), 'period_end' => $period->endDate(),
            'status' => 'pending', 'payload' => $this->fakePayload($property, $type),
        ]);
    }

    /** @param  array<string, string|null>|null  $charts */
    private function html(Report $report, ?array $charts = null): string
    {
        return (new ReportMail($report->load('property'), $report->property, $charts ?? ['trend' => null, 'channels' => null, 'countries' => null]))->render();
    }

    /** @return array<string, string> three real PNG files */
    private function chartFiles(): array
    {
        $paths = [];
        foreach (['trend', 'channels', 'countries'] as $name) {
            $paths[$name] = "{$this->dir}/{$name}.png";
            file_put_contents($paths[$name], $this->tinyPng());
        }

        return $paths;
    }

    public function test_the_document_has_a_doctype_a_viewport_and_a_hidden_preheader_from_the_insight_headline(): void
    {
        $report = $this->report();

        $html = $this->html($report);

        $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($html));
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('<meta name="format-detection"', $html);   // stops phones turning numbers into links

        $this->assertSame(1, preg_match('#<body[^>]*>\s*(?:<!--.*?-->\s*)*<div style="display:none;([^"]*)">(.*?)</div>#s', $html, $m));
        $this->assertStringContainsString('mso-hide:all', $m[1]);
        $this->assertStringContainsString('max-height:0', $m[1]);
        $this->assertStringContainsString('overflow:hidden', $m[1]);
        $preheader = html_entity_decode(trim(preg_replace('/(&nbsp;|&zwnj;)+/', '', $m[2])), ENT_QUOTES | ENT_HTML5);
        $this->assertNotSame('', $preheader);
        $this->assertLessThanOrEqual(90, mb_strlen($preheader));
        $this->assertStringStartsWith('This week is best described as healthy growth: sessions rose ▲ +25.0% to 10,000', $preheader);
    }

    public function test_the_preheader_is_at_most_90_characters_and_cut_at_a_word(): void
    {
        $short = 'This week, sessions rose 25.0% to 10,000.';
        $this->assertSame($short, ReportViewData::preheader($short));

        $long = 'This month, sessions rose 2.7% to 18,561 and key events fell 4.2% to 732, compared with last month. Against the same month last year, sessions are down 10.1%.';
        $cut = ReportViewData::preheader($long);
        $this->assertLessThanOrEqual(90, mb_strlen($cut));
        $this->assertStringEndsWith('…', $cut);
        $this->assertStringNotContainsString('  ', $cut);
        $this->assertStringStartsWith('This month, sessions rose 2.7% to 18,561 and key events fell 4.2% to 732, compared', $cut);
        $this->assertMatchesRegularExpression('/\w…$/u', $cut);                           // ends on a whole word

        $exactly90 = str_repeat('a', 90);
        $this->assertSame($exactly90, ReportViewData::preheader($exactly90));
        $this->assertSame(90, mb_strlen(ReportViewData::preheader(str_repeat('a', 91))));
        $this->assertSame('', ReportViewData::preheader(''));
        $this->assertSame('a b', ReportViewData::preheader("  a \n  b "));
    }

    public function test_every_image_has_width_height_alt_and_display_block(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/n.png', $this->tinyPng());
        $report = $this->report(['logo_path' => 'logos/n.png']);

        $html = $this->html($report, $this->chartFiles());

        preg_match_all('/<img\b[^>]*>/i', $html, $images);
        $this->assertCount(4, $images[0], 'logo and three charts');
        foreach ($images[0] as $img) {
            $this->assertMatchesRegularExpression('/\swidth="\d+"/', $img, $img);
            $this->assertMatchesRegularExpression('/\sheight="\d+"/', $img, $img);
            $this->assertMatchesRegularExpression('/\salt="[^"]+"/', $img, $img);
            $this->assertStringContainsString('display:block', $img);
            $this->assertStringContainsString('border:0', $img);
        }
    }

    public function test_image_heights_follow_the_real_aspect_ratio(): void
    {
        $paths = [];
        foreach (['trend' => [1200, 600], 'channels' => [1200, 480], 'countries' => [1200, 840]] as $name => [$w, $h]) {
            $paths[$name] = "{$this->dir}/{$name}.png";
            $img = imagecreatetruecolor($w, $h);
            imagepng($img, $paths[$name]);
        }
        $report = $this->report();

        $html = $this->html($report, $paths);

        $this->assertStringContainsString('alt="Sessions trend" width="592" height="296"', $html);          // 592 x 600/1200
        $this->assertStringContainsString('alt="Sessions by channel" width="592" height="237"', $html);     // 592 x 480/1200 = 236.8
        $this->assertStringContainsString('alt="Top countries by engaged sessions" width="592" height="414"', $html);   // 592 x 840/1200
    }

    public function test_layout_uses_nothing_outlook_ignores(): void
    {
        $html = $this->html($this->report(), $this->chartFiles());

        $this->assertDoesNotMatchRegularExpression('/\bfloat\s*:/', $html);
        $this->assertDoesNotMatchRegularExpression('/display\s*:\s*(flex|grid|inline-flex|inline-grid)/', $html);
        $this->assertDoesNotMatchRegularExpression('/position\s*:\s*(absolute|fixed|relative)/', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString('calc(', $html);
        $this->assertStringNotContainsString('var(--', $html);

        // No margin on tables.
        $this->assertDoesNotMatchRegularExpression('/<table\b[^>]*style="[^"]*margin/i', $html);
        // No padding on div or p (Outlook ignores it): spacing is td padding.
        $this->assertDoesNotMatchRegularExpression('/<(div|p|span|ul|li)\b[^>]*style="[^"]*padding/i', $html);
        // max-width only on a table or an image, never on a td.
        $this->assertDoesNotMatchRegularExpression('/<td\b[^>]*max-width/i', $html);
        // The wrapper table has a width attribute next to its CSS max-width.
        $this->assertStringContainsString('<table role="presentation" width="640"', $html);
        $this->assertStringContainsString('max-width:640px', $html);
        // Every table is a layout table.
        preg_match_all('/<table\b[^>]*>/', $html, $tables);
        foreach ($tables[0] as $table) {
            $this->assertStringContainsString('role="presentation"', $table);
            $this->assertStringContainsString('cellpadding="0"', $table);
            $this->assertStringContainsString('cellspacing="0"', $table);
            $this->assertStringContainsString('border="0"', $table);
        }
        $this->assertSame(substr_count($html, '<table'), substr_count($html, '</table>'));
    }

    public function test_every_css_background_has_a_bgcolor_attribute(): void
    {
        $html = $this->html($this->report(), $this->chartFiles());

        preg_match_all('/<(td|table|body)\b[^>]*background:\s*#[0-9A-Fa-f]{6}[^>]*>/', $html, $tags);
        $this->assertNotEmpty($tags[0]);
        foreach ($tags[0] as $tag) {
            $this->assertMatchesRegularExpression('/\sbgcolor="#[0-9A-Fa-f]{6}"/', $tag, "missing bgcolor: {$tag}");
            // ... and the two agree.
            preg_match('/bgcolor="(#[0-9A-Fa-f]{6})"/', $tag, $attr);
            preg_match('/background:\s*(#[0-9A-Fa-f]{6})/', $tag, $css);
            $this->assertSame(strtolower($attr[1]), strtolower($css[1]), $tag);
        }
        $this->assertStringContainsString('<body bgcolor="#F3F4F6"', $html);
    }

    public function test_numbers_are_aligned_with_attributes_as_well_as_css(): void
    {
        $html = $this->html($this->report());

        preg_match_all('/<(td|th)\b[^>]*text-align:\s*right[^>]*>/', $html, $cells);
        $this->assertNotEmpty($cells[0]);
        foreach ($cells[0] as $cell) {
            $this->assertStringContainsString('align="right"', $cell, $cell);
        }
    }

    public function test_fonts_are_arial_or_helvetica_and_no_links_are_underlined(): void
    {
        $html = $this->html($this->report(), $this->chartFiles());

        preg_match_all('/font-family:\s*([^;"]+)/', $html, $families);
        $this->assertNotEmpty($families[1]);
        foreach ($families[1] as $family) {
            $this->assertSame('Arial, Helvetica, sans-serif', trim($family));
        }

        $this->assertStringNotContainsString('<a ', $html);                      // nothing to underline
        $this->assertStringNotContainsString('text-decoration:underline', $html);
        $this->assertStringNotContainsString('href=', $html);
    }

    public function test_the_header_text_is_readable_on_any_brand_color(): void
    {
        $this->assertSame('#FFFFFF', ReportViewData::readableTextOn('#1F6F8B'));   // dark teal: white text
        $this->assertSame('#FFFFFF', ReportViewData::readableTextOn('#8A2BE2'));
        $this->assertSame('#FFFFFF', ReportViewData::readableTextOn('#000000'));
        $this->assertSame('#111827', ReportViewData::readableTextOn('#FFEB3B'));   // yellow: dark text
        $this->assertSame('#111827', ReportViewData::readableTextOn('#FFFFFF'));
        $this->assertSame('#111827', ReportViewData::readableTextOn('#A5D6A7'));

        $dark = $this->html($this->report());
        $this->assertSame(1, preg_match('#<td bgcolor="\#1F6F8B"[^>]*>.*?(?=Traffic Quality Insight)#s', $dark, $header));
        $this->assertStringContainsString('color:#FFFFFF', $header[0]);
        $this->assertStringNotContainsString('opacity', $header[0]);   // Outlook ignores it, and it can fade the text

        $light = $this->html($this->report(['brand_color' => '#FFEB3B', 'slug' => 'light', 'name' => 'Light']));
        $this->assertSame(1, preg_match('#<td bgcolor="\#FFEB3B"[^>]*>.*?(?=Traffic Quality Insight)#s', $light, $header));
        $this->assertStringContainsString('color:#111827', $header[0]);
        $this->assertStringNotContainsString('color:#FFFFFF', $header[0]);
    }

    public function test_the_sections_use_engaged_sessions(): void
    {
        $html = $this->html($this->report());

        $this->assertStringContainsString('Top countries by engaged sessions', $html);
        $this->assertStringContainsString('Engaged</th>', $html);
        $this->assertSame(1, preg_match('#Country performance.*?<th[^>]*>Country</th>.*?Sessions</th>.*?Engaged</th>.*?Engagement</th>.*?Booking visits</th>.*?Quality</th>#s', $html));
        $this->assertStringContainsString('check for bots', $html);                      // the flag stays
        // Fallback table (no chart) is ranked by engaged sessions: Indonesia (1,500), Australia (1,440), United States (840).
        $this->assertSame(1, preg_match('#Top countries by engaged sessions</td>(.*?)Country performance#s', $html, $card));
        $this->assertSame(1, preg_match('#Indonesia.*?1,500.*?Australia.*?1,440.*?United States.*?840.*?United Kingdom.*?270#s', $card[1]));
    }

    public function test_the_countries_heading_is_not_repeated_above_a_chart_that_has_the_same_title(): void
    {
        $report = $this->report();

        $withChart = $this->html($report, $this->chartFiles());
        $this->assertStringContainsString('>Top countries</td>', $withChart);
        $this->assertSame(1, substr_count($withChart, 'Top countries by engaged sessions'));   // only the image alt text

        $withoutChart = $this->html($report);
        $this->assertStringContainsString('>Top countries by engaged sessions</td>', $withoutChart);   // the table needs its own title
    }

    public function test_the_real_email_attaches_inline_png_images_with_a_filename_and_content_type(): void
    {
        $report = $this->report();
        config(['mail.default' => 'array']);

        Mail::to('someone@example.test')->send(new ReportMail($report->load('property'), $report->property, $this->chartFiles()));

        /** @var Email $email */
        $email = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $html = $email->getHtmlBody();

        $parts = $email->getAttachments();
        $this->assertCount(3, $parts);
        $names = [];
        foreach ($parts as $part) {
            $names[] = $part->getFilename();
            $this->assertSame('image', $part->getMediaType());
            $this->assertSame('png', $part->getMediaSubtype());
            $this->assertSame('inline', $part->getPreparedHeaders()->getHeaderBody('Content-Disposition'));
            $this->assertNotEmpty($part->getContentId());
            $this->assertStringContainsString('src="cid:'.$part->getContentId().'"', $html);
        }
        sort($names);
        $this->assertSame(['channels.png', 'countries.png', 'trend.png'], $names);
        $this->assertSame(3, substr_count($html, 'src="cid:'));
        $this->assertStringContainsString('TRAFFIC QUALITY INSIGHT', (string) $email->getTextBody());
    }

    public function test_a_jpeg_logo_keeps_its_own_content_type(): void
    {
        Storage::fake('public');
        $jpeg = "{$this->dir}/logo.jpg";
        imagejpeg(imagecreatetruecolor(40, 20), $jpeg);
        Storage::disk('public')->put('logos/n.jpg', file_get_contents($jpeg));
        $report = $this->report(['logo_path' => 'logos/n.jpg']);
        config(['mail.default' => 'array']);

        Mail::to('someone@example.test')->send(new ReportMail($report->load('property'), $report->property, ['trend' => null, 'channels' => null, 'countries' => null]));

        $email = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $logo = $email->getAttachments()[0];
        $this->assertSame('jpeg', $logo->getMediaSubtype());
        $this->assertSame('n.jpg', $logo->getFilename());
        $this->assertStringContainsString('height="28"', $email->getHtmlBody());   // 56 x 20/40
    }

    public function test_the_preview_still_inlines_images_as_data_uris(): void
    {
        $report = $this->report();

        $html = view('emails.report', [
            'view' => ReportViewData::make($report->property, $report->period(), $report->payload, ['headline' => 'x', 'bullets' => [], 'caution' => null], $this->chartFiles()),
            'charts' => $this->chartFiles(),
            'inline' => fn (string $path) => 'data:image/png;base64,'.base64_encode(file_get_contents($path)),
        ])->render();

        $this->assertSame(3, substr_count($html, 'src="data:image/png;base64,'));
        $this->assertStringNotContainsString('cid:', $html);
    }
}
