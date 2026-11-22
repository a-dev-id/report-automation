<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\Report;
use App\Support\Compare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

/** What the email shows, against the owner's reference report: shares, New and dropped, paid purchases, AI countries, styling. */
class ReportContentParityTest extends TestCase
{
    use BuildsFakePayloads;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
        $this->dir = $this->reportsPath('parity');
        File::ensureDirectoryExists($this->dir);
    }

    private function property(): Property
    {
        return Property::create([
            'name' => 'Nandini', 'slug' => 'nandini', 'ga4_property_id' => FakeData::NANDINI_ID, 'gsc_site_url' => 'sc-domain:nandini.test',
            'booking_engine' => ['type' => 'hostname', 'value' => 'book.nandini.test'], 'ads_source' => 'ga4',
            'currency' => 'IDR', 'timezone' => 'Asia/Makassar', 'brand_color' => '#1F6F8B',
        ]);
    }

    /** @param  callable(array): array|null  $change  edits the payload before the email is built */
    private function report(?callable $change = null, ReportType $type = ReportType::Weekly): Report
    {
        $property = $this->property();
        $payload = $this->fakePayload($property, $type);
        if ($change !== null) {
            $payload = $change($payload);
        }
        $period = $this->period($type);

        return Report::create([
            'property_id' => $property->id, 'type' => $type->value, 'period_start' => $period->startDate(), 'period_end' => $period->endDate(),
            'status' => 'pending', 'payload' => $payload,
        ]);
    }

    /** @return array{0: string, 1: string} html and plain text */
    private function render(Report $report, bool $charts = false): array
    {
        $files = ['trend' => null, 'channels' => null, 'countries' => null];
        if ($charts) {
            foreach (array_keys($files) as $name) {
                $files[$name] = "{$this->dir}/{$name}.png";
                file_put_contents($files[$name], $this->tinyPng());
            }
        }

        $mail = new ReportMail($report->load('property'), $report->property, $files);

        return [$mail->render(), view('emails.report-text', $mail->content()->with)->render()];
    }

    private function flat(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    // ---- insight ------------------------------------------------------------------------------------

    public function test_the_insight_has_a_verdict_headline_bold_section_titles_and_colored_numbers(): void
    {
        [$html] = $this->render($this->report());
        $flat = $this->flat($html);

        $this->assertStringContainsString('Traffic grew healthily this week', $html);
        foreach (['Advertising', 'Website traffic', 'Where visitors came from', 'Countries', 'AI assistants', 'Search Console'] as $title) {
            $this->assertMatchesRegularExpression('/font-weight:bold; color:#1F6F8B; padding:12px 0 2px 0;">'.preg_quote($title, '/').'<\/td>/', $flat, $title);
        }
        // A value and its change are bold spans with an inline color: no classes.
        $this->assertStringContainsString('<span style="font-weight:bold; color:#15803D;">+25.0%</span>', $html);
        $this->assertStringContainsString('<span style="font-weight:bold; color:#111827;">10,000</span>', $html);
        $this->assertStringNotContainsString('class=', $html);
        $this->assertStringNotContainsString('⟦', $html);                       // no raw markup leaks
    }

    public function test_the_priority_line_is_a_bold_labelled_block_after_the_paragraphs(): void
    {
        [$html, $text] = $this->render($this->report(function (array $p) {
            $p['site']['bookingEngineVisits'] = Compare::metric(['current' => 30.0, 'previous' => 40.0]);   // sessions up, booking down

            return $p;
        }));

        $flat = $this->flat($html);
        $this->assertMatchesRegularExpression('/<span style="font-weight:bold; color:#111827;">Priority:<\/span> Check the booking button/', $flat);
        $this->assertGreaterThan(strpos($html, 'Search Console'), strpos($html, 'Priority:'));
        $this->assertStringContainsString('Priority: Check the booking button and the pages most visitors land on.', $text);
    }

    public function test_the_caution_is_one_neutral_box_at_the_end_of_the_insight(): void
    {
        [$html, $text] = $this->render($this->report(function (array $p) {
            $p['site']['keyEvents'] = Compare::metric(['current' => 100.0, 'previous' => 700.0]);

            return $p;
        }));

        $this->assertStringContainsString('Key events changed by -85.7% versus the previous week.', $html);
        $this->assertStringContainsString('please check the tracking setup before drawing conclusions', $text);
        $this->assertSame(1, substr_count($text, 'check the tracking setup'));
    }

    // ---- KPI tiles ----------------------------------------------------------------------------------

    public function test_kpi_tiles_include_total_users_and_average_session_duration_with_change(): void
    {
        [$html, $text] = $this->render($this->report());
        $flat = $this->flat($html);

        $this->assertMatchesRegularExpression('/>Users<\/td>.*?>7,000<\/td>/', $flat);
        $this->assertMatchesRegularExpression('/>Avg\. session duration<\/td>.*?>1m 36s<\/td>.*?▼ -4\.5%/', $flat);
        $this->assertStringContainsString('Avg. session duration: 1m 36s (▼ -4.5%)', $text);
    }

    public function test_a_metric_the_property_does_not_have_is_left_out_never_shown_as_zero(): void
    {
        [$html, $text] = $this->render($this->report(function (array $p) {
            unset($p['site']['keyEvents'], $p['site']['averageSessionDuration']);
            unset($p['paid']['metrics']['purchases'], $p['paid']['metrics']['revenue']);   // the paid table has its own Purchases row
            $p['site']['purchases'] = Compare::metric(['current' => 0.0, 'previous' => 0.0]);
            $p['site']['purchaseRevenue'] = Compare::metric(['current' => 0.0, 'previous' => 0.0]);

            return $p;
        }));

        foreach (['Key events', 'Avg. session duration', 'Purchases', 'Revenue'] as $tile) {
            $this->assertStringNotContainsString('line-height:18px; color:#6B7280;">'.$tile.'</td>', $html, $tile);   // the tile label cell
            $this->assertStringNotContainsString(" * {$tile}:", $text, $tile);
        }
        $this->assertStringContainsString('line-height:18px; color:#6B7280;">Sessions</td>', $html);
        $this->assertStringContainsString(' * Sessions: ', $text);
        $this->assertStringNotContainsString(' * Key events:', $text);
        $this->assertStringNotContainsString(' * Avg. session duration:', $text);
    }

    // ---- change colors in context --------------------------------------------------------------------

    public function test_positive_negative_zero_and_new_changes_use_the_agreed_colors_with_signs(): void
    {
        [$html] = $this->render($this->report(function (array $p) {
            $p['site']['sessions'] = Compare::metric(['current' => 900.0, 'previous' => 1000.0]);    // negative
            $p['site']['totalUsers'] = Compare::metric(['current' => 700.0, 'previous' => 700.0]);   // zero
            $p['site']['keyEvents'] = Compare::metric(['current' => 12.0, 'previous' => 0.0]);       // new

            return $p;
        }));
        $flat = $this->flat($html);

        $this->assertMatchesRegularExpression('/color:#B91C1C; white-space:nowrap;">▼ -10\.0%/', $flat);   // red, arrow and minus sign
        $this->assertMatchesRegularExpression('/color:#6B7280; white-space:nowrap;">▬ 0\.0%/', $flat);     // gray
        $this->assertMatchesRegularExpression('/color:#475569; white-space:nowrap;">▲ New/', $flat);       // blue-gray
        $this->assertMatchesRegularExpression('/color:#15803D; white-space:nowrap;">▲ \+/', $flat);        // green with a plus sign
    }

    public function test_the_plain_text_version_has_no_colors_but_every_change_has_a_sign(): void
    {
        [, $text] = $this->render($this->report());

        $this->assertStringNotContainsString('#', preg_replace('/[^#\w]#(?=\s)/', '', $text) ?? '');
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}/', $text);
        $this->assertStringNotContainsString('<span', $text);
        $this->assertStringNotContainsString('⟦', $text);
        $this->assertStringContainsString('Sessions: 10,000 (▲ +25.0%)', $text);
        $this->assertStringContainsString('Avg. session duration: 1m 36s (▼ -4.5%)', $text);
        $this->assertStringContainsString('▲ +25.0%', $text);
    }

    // ---- traffic sources ----------------------------------------------------------------------------

    public function test_the_channel_table_has_share_sessions_change_and_says_what_the_remaining_share_came_from(): void
    {
        [$html, $text] = $this->render($this->report());
        $flat = $this->flat($html);

        $this->assertMatchesRegularExpression('/Channel<\/th>\s*<th[^>]*>Share<\/th>\s*<th[^>]*>Sessions<\/th>\s*<th[^>]*>Change<\/th>\s*<th[^>]*>Engagement<\/th>/', $flat);
        $this->assertMatchesRegularExpression('/>Organic Search<\/td>\s*<td[^>]*>40\.0%<\/td>\s*<td[^>]*font-weight:bold;">4,000<\/td>/', $flat);   // the value next to a change is bold
        $this->assertStringContainsString('The remaining 5.0% (shown as Other) came from Display and Unassigned.', $html);
        $this->assertStringContainsString('The remaining 5.0% (shown as Other) came from Display and Unassigned.', $text);
        $this->assertStringContainsString('Organic Search: 40.0% of sessions, 4,000 sessions (▲ +25.0%)', $text);
    }

    // ---- countries ----------------------------------------------------------------------------------

    public function test_the_countries_section_has_the_chart_and_a_table_with_share_change_new_and_a_dropped_line(): void
    {
        [$html, $text] = $this->render($this->report(function (array $p) {
            $p['countries']['rows'][7]['engagedSessions'] = Compare::metric(['current' => 150.0, 'previous' => 0.0]);   // India: nothing before

            return $p;
        }), charts: true);
        $flat = $this->flat($html);

        $this->assertStringContainsString('alt="Top countries by engaged sessions"', $html);    // the chart ...
        $this->assertMatchesRegularExpression('/Country<\/th>\s*<th[^>]*>Engaged<\/th>\s*<th[^>]*>Share of engaged<\/th>\s*<th[^>]*>Engaged change<\/th>\s*<th[^>]*>Sessions<\/th>/', $flat);   // ... and the table
        $this->assertMatchesRegularExpression('/>Indonesia<\/td>\s*<td[^>]*font-weight:bold;">1,500<\/td>\s*<td[^>]*>28\.9%<\/td>/', $flat);
        $this->assertMatchesRegularExpression('/>India<\/td>.*?▲ New/', $flat);
        $this->assertStringContainsString('Dropped from the current top countries: Brazil.', $html);
        $this->assertStringContainsString('Dropped from the current top countries: Brazil.', $text);
        $this->assertStringContainsString('Indonesia: 1,500 engaged sessions (28.9% of engaged, ', $text);
    }

    public function test_the_country_performance_table_keeps_sessions_and_adds_share_and_engaged(): void
    {
        [$html] = $this->render($this->report());
        $flat = $this->flat($html);

        $this->assertSame(1, preg_match('#Country performance.*?<th[^>]*>Country</th>\s*<th[^>]*>Sessions</th>\s*<th[^>]*>Share of sessions</th>\s*<th[^>]*>Engaged</th>\s*<th[^>]*>Engaged rate</th>\s*<th[^>]*>Booking visits</th>\s*<th[^>]*>Quality</th>#', $flat));
        $this->assertMatchesRegularExpression('/Indonesia\s*(?:<br>\s*<span[^>]*>[^<]*<\/span>)?\s*<\/td>\s*<td[^>]*>3,000<\/td>\s*<td[^>]*>30\.0%<\/td>\s*<td[^>]*>1,500<\/td>/', $flat);   // 3000 of 10000 sessions, 1500 engaged
        $this->assertStringContainsString('possible bots', $html);
        $this->assertStringContainsString('strongest traffic source, so session totals can differ', $html);
    }

    // ---- paid ---------------------------------------------------------------------------------------

    public function test_the_paid_table_has_purchases_and_revenue_and_keeps_cost_gray(): void
    {
        [$html, $text] = $this->render($this->report());
        $flat = $this->flat($html);

        $this->assertMatchesRegularExpression('/>Purchases<\/td>\s*<td[^>]*font-weight:bold;">2<\/td>\s*<td[^>]*>0<\/td>.*?▲ New/', $flat);
        $this->assertMatchesRegularExpression('/>Revenue<\/td>\s*<td[^>]*font-weight:bold;">IDR 9,000,000<\/td>/', $flat);
        $this->assertSame(1, preg_match('/>Cost<\/td>.*?font-weight:bold; (color:#[0-9A-F]{6})/', $flat, $m));
        $this->assertSame('color:#6B7280', $m[1]);
        $this->assertStringContainsString('Purchases: 2 (previous 0, ▲ New)', $text);
    }

    public function test_paid_purchases_are_left_out_when_ga4_could_not_provide_them(): void
    {
        [$html, $text] = $this->render($this->report(function (array $p) {
            unset($p['paid']['metrics']['purchases'], $p['paid']['metrics']['revenue']);

            return $p;
        }));

        $this->assertSame(1, preg_match('#Paid advertising</td>(.*?)Traffic from AI assistants</td>#s', $html, $match));
        $paid = $match[1];
        $this->assertStringNotContainsString('>Purchases<', $paid);
        $this->assertStringNotContainsString('>Revenue<', $paid);
        $this->assertStringContainsString('>Impressions<', $paid);
        $this->assertStringNotContainsString('Purchases: 0', $text);
    }

    // ---- AI traffic ---------------------------------------------------------------------------------

    public function test_ai_traffic_shows_share_per_assistant_and_top_countries_with_new_and_dropped(): void
    {
        [$html, $text] = $this->render($this->report());
        $flat = $this->flat($html);

        $this->assertMatchesRegularExpression('/Assistant<\/th>\s*<th[^>]*>Share<\/th>\s*<th[^>]*>Sessions<\/th>\s*<th[^>]*>Change<\/th>/', $flat);
        $this->assertMatchesRegularExpression('/>chatgpt\.com<\/td>\s*<td[^>]*>68\.6%<\/td>\s*<td[^>]*font-weight:bold;">120<\/td>/', $flat);
        $this->assertMatchesRegularExpression('/>gemini\.google\.com<\/td>.*?▲ New/', $flat);
        $this->assertMatchesRegularExpression('/Top countries \(AI traffic\)<\/th>.*?>United States<\/td>\s*<td[^>]*>40\.0%<\/td>.*?>Indonesia<\/td>\s*<td[^>]*>28\.6%<\/td>.*?▲ New/', $flat);
        $this->assertStringContainsString('Dropped from the AI top countries: Germany.', $html);
        $this->assertStringContainsString('Dropped from the AI top countries: Germany.', $text);
        $this->assertStringContainsString('Indonesia: 28.6%, 50 sessions (▲ New)', $text);
    }

    // ---- Search Console -----------------------------------------------------------------------------

    public function test_search_console_shows_all_four_numbers_with_position_inverted_and_a_brand_line_when_set(): void
    {
        config(['report.insight.brand_terms' => ['nandini']]);

        [$html, $text] = $this->render($this->report());
        $flat = $this->flat($html);

        foreach (['Clicks', 'Impressions', 'CTR', 'Avg. position'] as $tile) {
            $this->assertStringContainsString(">{$tile}", $html, $tile);
        }
        $this->assertStringContainsString('Avg. position (lower is better)', $html);
        // The position number fell (13.1 -> 12.3): the arrow points down and it is green, because lower is better.
        $this->assertMatchesRegularExpression('/>12\.3<\/td>.*?color:#15803D; white-space:nowrap;">▼ -6\.1%/', $flat);
        $this->assertStringContainsString('Brand searches (terms: nandini) brought 46.0% of clicks (previous 45.0%).', $html);
        $this->assertStringContainsString('Avg. position (lower is better): 12.3 (▼ -6.1%)', $text);
        $this->assertStringContainsString('Brand searches (terms: nandini) brought 46.0% of clicks (previous 45.0%).', $text);
    }

    public function test_the_brand_line_is_not_shown_without_brand_terms(): void
    {
        [$html] = $this->render($this->report());

        $this->assertStringNotContainsString('Brand searches', $html);
    }

    // ---- both versions say the same -----------------------------------------------------------------

    public function test_the_html_and_the_text_version_carry_the_same_insight_and_numbers(): void
    {
        [$html, $text] = $this->render($this->report());

        foreach (['Traffic grew healthily this week', 'Paid impressions rose', 'The remaining 5.0%', 'Ranked by engaged sessions', '10,000', 'IDR 9,000,000', '46.0%'] as $needle) {
            if ($needle === '46.0%') {
                continue;   // brand share only with brand terms
            }
            $this->assertStringContainsString($needle, html_entity_decode(strip_tags($html)), "html: {$needle}");
            $this->assertStringContainsString($needle, $text, "text: {$needle}");
        }
        foreach (['ADVERTISING', 'WEBSITE TRAFFIC', 'WHERE VISITORS CAME FROM', 'COUNTRIES', 'AI ASSISTANTS', 'SEARCH CONSOLE'] as $title) {
            $this->assertStringContainsString($title, $text);
        }
    }
}
