<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
use App\Exceptions\GoogleApiException;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\Report;
use App\Reports\Queries\PageViews;
use App\Services\Reports\Insight\WebsiteTrafficRules;
use App\Support\Rich;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Concerns\BuildsInsightPayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

/** "Most visited pages" and "Most visited offers": ranking, offer prefix, email tables and the insight line. */
class PageViewsTest extends TestCase
{
    use BuildsFakePayloads;
    use BuildsInsightPayloads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
    }

    public function test_pages_are_ranked_by_views_with_users_and_the_change_against_the_previous_period(): void
    {
        $pages = (new PageViews($this->ga4))->pages(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertSame(['/', '/offer/black-friday-limited-time-offer', '/holy-river', '/offer/last-minute-deal', '/experiences/day-pass'], array_column($pages['rows'], 'path'));
        $home = $pages['rows'][0];
        $this->assertSame('Home page', $home['name']);
        $this->assertSame(6682.0, $home['views']['current']);
        $this->assertSame(7000.0, $home['views']['previous']);
        $this->assertSame(-4.5, $home['views']['change']['percent']);
        $this->assertSame(5262.0, $home['users']);
        $this->assertSame('new', $pages['rows'][1]['views']['change']['direction']);      // Black Friday: no views the week before
        $this->assertSame(7824.0, $pages['total']['current']);                             // all pages, not only the top five
    }

    public function test_the_ranking_uses_current_views_and_asks_for_current_and_previous_only(): void
    {
        (new PageViews($this->ga4))->pages(FakeData::nandini(), $this->period(ReportType::Monthly));

        $request = $this->ga4->reportsFor(FakeData::NANDINI_ID, 'pages.views')[0];
        $this->assertSame(['current', 'previous'], array_keys($request->dateRanges));
        $this->assertSame(['screenPageViews', 'totalUsers'], $request->metrics);
        $this->assertNull($request->dimensionFilter);
    }

    public function test_offers_are_the_pages_under_the_offer_path_with_readable_names(): void
    {
        $offers = (new PageViews($this->ga4))->offers(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertSame(
            ['Black Friday Limited Time Offer', 'Last Minute Deal', 'Your Last Night Is On Us'],
            array_column($offers['rows'], 'name'),
        );
        $this->assertSame('/offer/black-friday-limited-time-offer', $offers['rows'][0]['path']);
        $this->assertSame(342.0, $offers['rows'][0]['views']['current']);
        $this->assertSame(32.7, $offers['rows'][1]['views']['change']['percent']);          // 199 vs 150
        $this->assertSame(570.0, $offers['total']['current']);                              // 342 + 199 + 29

        $request = $this->ga4->reportsFor(FakeData::NANDINI_ID, 'offers.views')[0];
        $this->assertSame(['field' => 'pagePath', 'op' => 'begins_with', 'value' => '/offer/'], $request->dimensionFilter);
    }

    public function test_the_offer_path_can_be_set_per_hotel_and_switched_off(): void
    {
        config(['report.insight.properties.nandini.offer_path_prefix' => '/deals/']);
        (new PageViews($this->ga4))->offers(FakeData::nandini(), $this->period(ReportType::Weekly));
        $this->assertSame('/deals/', $this->ga4->reportsFor(FakeData::NANDINI_ID, 'offers.views')[0]->dimensionFilter['value']);

        config(['report.insight.properties.nandini.offer_path_prefix' => '']);
        $none = (new PageViews($this->ga4))->offers(FakeData::nandini(), $this->period(ReportType::Weekly));
        $this->assertSame([], $none['rows']);
        $this->assertCount(1, $this->ga4->reportsFor(FakeData::NANDINI_ID, 'offers.views'), 'no query for a hotel without offers');
    }

    public function test_offer_names_come_from_the_path(): void
    {
        $this->assertSame('Early Bird Offer', PageViews::offerName('/offer/early-bird-offer', '/offer/'));
        $this->assertSame('Solo Renewal', PageViews::offerName('/offer/solo_renewal/', '/offer/'));
        $this->assertSame('/offer/', PageViews::offerName('/offer/', '/offer/'));
    }

    public function test_pages_with_no_views_this_period_are_not_listed(): void
    {
        $fixtures = FakeData::ga4();
        $fixtures[FakeData::NANDINI_ID]['pages.views']['current'][] = ['dimensions' => ['pagePath' => '/ghost'], 'metrics' => ['screenPageViews' => 0.0, 'totalUsers' => 0.0]];
        $ga4 = new \Tests\Fakes\FakeGa4Client($fixtures);

        $pages = (new PageViews($ga4))->pages(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertNotContains('/ghost', array_column($pages['rows'], 'path'));
    }

    public function test_the_payload_has_both_sections_and_a_failing_one_is_recorded_without_breaking_the_rest(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $this->assertSame(5, count($payload['top_pages']['rows']));
        $this->assertSame(3, count($payload['top_offers']['rows']));

        $this->ga4->failOn('offers.views', new GoogleApiException('boom'));
        $broken = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);

        $this->assertSame('boom', $broken['top_offers']['error']['message']);
        $this->assertCount(5, $broken['top_pages']['rows']);
        $this->assertSame(10000.0, $broken['site']['sessions']['current']);
    }

    // ---- insight -----------------------------------------------------------------------------------

    public function test_the_insight_names_the_most_visited_offer(): void
    {
        $payload = $this->payload();
        $payload['top_offers'] = ['total' => $this->m(570, 190), 'rows' => [
            ['path' => '/offer/black-friday-limited-time-offer', 'name' => 'Black Friday Limited Time Offer', 'views' => $this->m(342, 100), 'users' => 207.0],
        ]];

        $finding = (new WebsiteTrafficRules())->topOffer($this->context($payload), $payload['site']);

        $this->assertStringContainsString('The most visited offer was Black Friday Limited Time Offer (342 views', Rich::toPlain($finding->text));
    }

    public function test_a_huge_jump_in_offer_views_is_not_stated_as_a_percent(): void
    {
        $payload = $this->payload();
        $payload['top_offers'] = ['total' => $this->m(342, 10), 'rows' => [['path' => '/offer/x', 'name' => 'X', 'views' => $this->m(342, 10), 'users' => 1.0]]];

        $text = Rich::toPlain((new WebsiteTrafficRules())->topOffer($this->context($payload), $payload['site'])->text);

        $this->assertSame('The most visited offer was X (342 views).', $text);
    }

    public function test_no_offer_sentence_without_offers(): void
    {
        $rules = new WebsiteTrafficRules();
        $payload = $this->payload();

        $this->assertNull($rules->topOffer($this->context($payload), $payload['site']));
        $payload['top_offers'] = ['error' => ['message' => 'x']];
        $this->assertNull($rules->topOffer($this->context($payload), $payload['site']));
        $payload['top_offers'] = ['total' => $this->m(0, 0), 'rows' => []];
        $this->assertNull($rules->topOffer($this->context($payload), $payload['site']));
    }

    // ---- email -------------------------------------------------------------------------------------

    private function render(?callable $change = null): array
    {
        $property = Property::create([
            'name' => 'Nandini', 'slug' => 'nandini', 'ga4_property_id' => FakeData::NANDINI_ID, 'gsc_site_url' => 'sc-domain:nandini.test',
            'booking_engine' => ['type' => 'hostname', 'value' => 'book.nandini.test'], 'ads_source' => 'ga4', 'currency' => 'IDR', 'timezone' => 'Asia/Makassar',
        ]);
        $payload = $this->fakePayload($property, ReportType::Weekly);
        if ($change !== null) {
            $payload = $change($payload);
        }
        $period = $this->period(ReportType::Weekly);
        $report = Report::create(['property_id' => $property->id, 'type' => 'weekly', 'period_start' => $period->startDate(), 'period_end' => $period->endDate(), 'status' => 'pending', 'payload' => $payload]);
        $mail = new ReportMail($report->load('property'), $property, ['trend' => null, 'channels' => null, 'countries' => null]);

        return [preg_replace('/\s+/', ' ', $mail->render()), view('emails.report-text', $mail->content()->with)->render()];
    }

    public function test_the_email_has_a_most_visited_pages_and_a_most_visited_offers_table(): void
    {
        [$html, $text] = $this->render();

        $this->assertMatchesRegularExpression('/>Most visited pages<\/td>.*?<th[^>]*>Page<\/th>\s*<th[^>]*>Views<\/th>\s*<th[^>]*>Users<\/th>\s*<th[^>]*>Change<\/th>/', $html);
        $this->assertMatchesRegularExpression('/Home page.*?\/<\/span>\s*<\/td>\s*<td[^>]*font-weight:bold;">6,682<\/td>\s*<td[^>]*>5,262<\/td>/', $html);
        $this->assertMatchesRegularExpression('/>Most visited offers<\/td>.*?<th[^>]*>Offer<\/th>/', $html);
        $this->assertMatchesRegularExpression('/Black Friday Limited Time Offer.*?\/offer\/black-friday-limited-time-offer.*?font-weight:bold;">342<\/td>.*?▲ New/', $html);
        $this->assertStringContainsString('All offer pages together: 570 views.', $html);
        $this->assertLessThan(strpos($html, '>Most visited offers<'), strpos($html, '>Most visited pages<'));
        $this->assertLessThan(strpos($html, 'Paid advertising'), strpos($html, '>Most visited offers<'));

        $this->assertStringContainsString('MOST VISITED OFFERS', $text);
        $this->assertStringContainsString('Black Friday Limited Time Offer (/offer/black-friday-limited-time-offer): 342 views, 207 users (▲ New)', $text);
        $this->assertStringContainsString('Home page (/): 6,682 views, 5,262 users (▼ -4.5%)', $text);
    }

    public function test_a_hotel_without_offers_has_no_offers_section_and_an_error_shows_the_neutral_box(): void
    {
        [$html] = $this->render(function (array $p) {
            $p['top_offers'] = ['total' => ['current' => 0.0, 'previous' => 0.0], 'rows' => []];
            $p['top_pages'] = ['error' => ['type' => 'GoogleApiException', 'message' => 'secret']];

            return $p;
        });

        $this->assertStringNotContainsString('Most visited offers', $html);
        $this->assertMatchesRegularExpression('/>Most visited pages<\/td>.*?This section is temporarily unavailable\./', $html);
        $this->assertStringNotContainsString('secret', $html);
    }
}
