<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\Recipient;
use App\Models\Report;
use App\Models\ReportDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

/** report:send-test and POST /cron/send-test: one "[TEST] " email to the given address, nothing else touched. */
class ReportSendTestTest extends TestCase
{
    use BuildsFakePayloads;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
        $this->token = str_repeat('a', 48);
        config(['report.cron_token' => $this->token]);
    }

    /** A hotel with real recipients: the ones that must never receive a test. */
    private function hotel(): Property
    {
        $property = Property::create([
            'name' => 'Nandini', 'slug' => 'nandini', 'ga4_property_id' => FakeData::NANDINI_ID, 'gsc_site_url' => 'sc-domain:nandini.test',
            'booking_engine' => ['type' => 'hostname', 'value' => 'book.nandini.test'], 'ads_source' => 'ga4',
            'currency' => 'IDR', 'timezone' => 'Asia/Makassar', 'active' => false,
        ]);
        foreach (['gm@hotel.test', 'owner@hotel.test'] as $email) {
            Recipient::create(['property_id' => $property->id, 'email' => $email]);
        }

        return $property;
    }

    private function storedReport(Property $property): Report
    {
        return Report::create([
            'property_id' => $property->id, 'type' => 'weekly', 'period_start' => '2026-11-09', 'period_end' => '2026-11-15',
            'status' => 'pending', 'payload' => $this->fakePayload($property, ReportType::Weekly),
        ]);
    }

    private function sendTest(array $args): array
    {
        $exit = Artisan::call('report:send-test', $args);

        return [$exit, Artisan::output()];
    }

    /** @return list<string> */
    private function sentTo(): array
    {
        $addresses = [];
        Mail::assertSent(ReportMail::class, function (ReportMail $mail) use (&$addresses) {
            foreach ($mail->to as $to) {
                $addresses[] = $to['address'];
            }

            return true;
        });

        return $addresses;
    }

    // ---- command ----------------------------------------------------------------------------------

    public function test_the_command_refuses_to_run_without_to(): void
    {
        Mail::fake();
        $this->storedReport($this->hotel());

        foreach ([[], ['--to' => ''], ['--to' => '   '], ['--to' => ',']] as $extra) {
            [$exit, $output] = $this->sendTest(['property' => 'nandini', 'type' => 'weekly', 'periodStart' => '2026-11-09'] + $extra);

            $this->assertNotSame(0, $exit);
            $this->assertStringContainsString('--to', $output);
        }

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_the_command_sends_only_to_the_given_address_with_a_test_prefix(): void
    {
        Mail::fake();
        $this->storedReport($this->hotel());

        [$exit, $output] = $this->sendTest(['property' => 'nandini', 'type' => 'weekly', 'periodStart' => '2026-11-09', '--to' => 'me@example.test']);

        $this->assertSame(0, $exit);
        Mail::assertSent(ReportMail::class, 1);
        $this->assertSame(['me@example.test'], $this->sentTo());     // not the hotel's gm@ and owner@
        Mail::assertSent(ReportMail::class, function (ReportMail $m) {
            return $m->subjectPrefix === '[TEST] '
                && $m->envelope()->subject === '[TEST] Nandini Weekly Traffic Report: 9 to 15 Nov 2026'
                && $m->hasTo('me@example.test')
                && ! $m->hasTo('gm@hotel.test')
                && ! $m->hasTo('owner@hotel.test');
        });

        $this->assertStringContainsString('Mailer:  array', $output);
        $this->assertStringContainsString('Subject: [TEST] Nandini Weekly Traffic Report: 9 to 15 Nov 2026', $output);
        $this->assertStringContainsString('To:      me@example.test', $output);
    }

    public function test_it_creates_no_deliveries_and_does_not_touch_the_report_or_the_recipients_table(): void
    {
        Mail::fake();
        $property = $this->hotel();
        $report = $this->storedReport($property);
        $row = fn () => (array) DB::table('reports')->where('id', $report->id)->first();   // every column, as stored
        $before = $row();

        DB::enableQueryLog();
        $this->sendTest(['property' => 'nandini', 'type' => 'weekly', 'periodStart' => '2026-11-09', '--to' => 'me@example.test']);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame(0, ReportDelivery::count());
        $this->assertSame(1, Report::count());
        $this->assertSame($before, $row());   // identical, updated_at included
        $this->assertSame('pending', $report->fresh()->status->value);
        $this->assertNull($report->fresh()->sent_at);
        $this->assertSame(2, Recipient::count());
        $this->assertFalse($property->fresh()->active);

        // Not a single write, and the recipients table is never read.
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $sql, $sql);
            $this->assertStringNotContainsString('recipients', $sql, $sql);
            $this->assertStringNotContainsString('report_deliveries', $sql, $sql);
        }
    }

    public function test_without_a_stored_report_it_builds_and_still_creates_no_rows(): void
    {
        Mail::fake();
        $this->hotel();

        [$exit] = $this->sendTest(['property' => 'nandini', 'type' => 'weekly', 'periodStart' => '2026-11-09', '--to' => 'me@example.test']);

        $this->assertSame(0, $exit);
        $this->assertNotEmpty($this->ga4->calls);                // it built the payload from the (fake) Google clients
        $this->assertSame(0, Report::count());                   // and left no report record behind
        $this->assertSame(0, ReportDelivery::count());
        Mail::assertSent(ReportMail::class, 1);
    }

    public function test_several_addresses_get_one_message_and_nobody_else_does(): void
    {
        Mail::fake();
        $this->storedReport($this->hotel());

        [$exit, $output] = $this->sendTest(['property' => 'nandini', 'type' => 'weekly', 'periodStart' => '2026-11-09', '--to' => ' me@example.test , Other@Example.test,me@example.test ']);

        $this->assertSame(0, $exit);
        Mail::assertSent(ReportMail::class, 1);
        $this->assertSame(['me@example.test', 'Other@Example.test'], $this->sentTo());   // trimmed, de-duplicated
        $this->assertStringContainsString('To:      me@example.test', $output);
        $this->assertStringContainsString('To:      Other@Example.test', $output);
    }

    public function test_an_invalid_address_stops_everything(): void
    {
        Mail::fake();
        $this->storedReport($this->hotel());

        [$exit, $output] = $this->sendTest(['property' => 'nandini', 'type' => 'weekly', 'periodStart' => '2026-11-09', '--to' => 'me@example.test,not-an-email']);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString("'not-an-email' is not a valid email address", $output);
        Mail::assertNothingSent();   // not even to the valid one
    }

    public function test_bad_arguments_are_rejected_without_sending(): void
    {
        Mail::fake();
        $this->storedReport($this->hotel());

        [$exit, $output] = $this->sendTest(['property' => 'nope', 'type' => 'weekly', '--to' => 'me@example.test']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString("No property with slug 'nope'", $output);

        [$exit, $output] = $this->sendTest(['property' => 'nandini', 'type' => 'daily', '--to' => 'me@example.test']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('weekly or monthly', $output);

        [$exit, $output] = $this->sendTest(['property' => 'nandini', 'type' => 'monthly', 'periodStart' => '2026-10-02', '--to' => 'me@example.test']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('1st', $output);

        Mail::assertNothingSent();
    }

    public function test_the_command_never_prints_mail_credentials(): void
    {
        Mail::fake();
        $this->storedReport($this->hotel());
        config(['mail.mailers.smtp.password' => 'TOP-SECRET-PW', 'mail.mailers.smtp.username' => 'secret-user@example.test', 'mail.default' => 'smtp']);

        [, $output] = $this->sendTest(['property' => 'nandini', 'type' => 'weekly', 'periodStart' => '2026-11-09', '--to' => 'me@example.test']);

        $this->assertStringContainsString('Mailer:  smtp', $output);
        $this->assertStringNotContainsString('TOP-SECRET-PW', $output);
        $this->assertStringNotContainsString('secret-user', $output);
    }

    public function test_a_log_or_array_mailer_is_called_out_as_not_delivering(): void
    {
        Mail::fake();
        $this->storedReport($this->hotel());

        [, $output] = $this->sendTest(['property' => 'nandini', 'type' => 'weekly', 'periodStart' => '2026-11-09', '--to' => 'me@example.test']);

        $this->assertStringContainsString("Mailer 'array' does not deliver anything", $output);
    }

    public function test_the_message_really_built_has_the_test_subject_and_cid_images(): void
    {
        $this->storedReport($this->hotel());
        config(['report.charts.enabled' => true, 'mail.default' => 'array']);
        \Illuminate\Support\Facades\Http::fake(['quickchart.io/*' => \Illuminate\Support\Facades\Http::response($this->tinyPng(), 200)]);

        [$exit] = $this->sendTest(['property' => 'nandini', 'type' => 'weekly', 'periodStart' => '2026-11-09', '--to' => 'me@example.test']);

        $this->assertSame(0, $exit);
        $messages = app('mail.manager')->mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame('[TEST] Nandini Weekly Traffic Report: 9 to 15 Nov 2026', $email->getSubject());
        $this->assertSame(['me@example.test'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame(3, substr_count($email->getHtmlBody(), 'src="cid:'));   // same charts, same CID images as the real job
        $this->assertCount(3, $email->getAttachments());
        $this->assertSame(0, ReportDelivery::count());
    }

    public function test_a_monthly_test_for_a_given_month(): void
    {
        Mail::fake();
        $property = $this->hotel();
        Report::create([
            'property_id' => $property->id, 'type' => 'monthly', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'status' => 'pending', 'payload' => $this->fakePayload($property, ReportType::Monthly),
        ]);

        [$exit, $output] = $this->sendTest(['property' => 'nandini', 'type' => 'monthly', 'periodStart' => '2026-10-01', '--to' => 'me@example.test']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Subject: [TEST] Nandini Monthly Traffic Report: October 2026', $output);
    }

    // ---- route ------------------------------------------------------------------------------------

    private function postTest(array $body, ?string $token = null)
    {
        return $this->postJson('/cron/send-test', $body, $token === null ? [] : ['X-Cron-Token' => $token]);
    }

    public function test_a_wrong_or_missing_token_is_a_404_and_nothing_is_sent(): void
    {
        Mail::fake();
        $this->storedReport($this->hotel());
        $body = ['property' => 'nandini', 'type' => 'weekly', 'period_start' => '2026-11-09', 'to' => 'me@example.test'];

        $this->postTest($body)->assertNotFound();
        $this->postTest($body, 'wrong-token')->assertNotFound();
        $this->postTest($body, str_repeat('b', 48))->assertNotFound();

        Mail::assertNothingSent();
        $this->assertSame(0, ReportDelivery::count());
    }

    public function test_the_route_sends_one_test_email_to_the_given_address_only(): void
    {
        Mail::fake();
        $this->storedReport($this->hotel());

        $this->postTest(['property' => 'nandini', 'type' => 'weekly', 'period_start' => '2026-11-09', 'to' => 'me@example.test'], $this->token)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'subject' => '[TEST] Nandini Weekly Traffic Report: 9 to 15 Nov 2026',
                'to' => 'me@example.test',
            ]);

        Mail::assertSent(ReportMail::class, 1);
        $this->assertSame(['me@example.test'], $this->sentTo());
        $this->assertSame(0, ReportDelivery::count());
        $this->assertSame(1, Report::count());
        $this->assertSame('pending', Report::query()->first()->status->value);
    }

    public function test_period_start_is_optional(): void
    {
        Mail::fake();
        $this->hotel();

        $response = $this->postTest(['property' => 'nandini', 'type' => 'monthly', 'to' => 'me@example.test'], $this->token);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertStringStartsWith('[TEST] Nandini Monthly Traffic Report: ', $response->json('subject'));
        Mail::assertSent(ReportMail::class, 1);
        $this->assertSame(0, Report::count());
    }

    public function test_the_route_validates_its_input_and_sends_nothing_when_it_is_wrong(): void
    {
        Mail::fake();
        $this->storedReport($this->hotel());
        $valid = ['property' => 'nandini', 'type' => 'weekly', 'period_start' => '2026-11-09', 'to' => 'me@example.test'];

        foreach ([
            'to' => [null, '', 'not-an-email', ['a@example.test', 'b@example.test']],
            'property' => [null, 'unknown'],
            'type' => [null, 'daily'],
            'period_start' => ['09-11-2026', 'yesterday'],
        ] as $field => $badValues) {
            foreach ($badValues as $bad) {
                $body = $valid;
                if ($bad === null) {
                    unset($body[$field]);
                } else {
                    $body[$field] = $bad;
                }

                $this->postTest($body, $this->token)
                    ->assertStatus(422)
                    ->assertJsonPath('ok', false)
                    ->assertJsonValidationErrorFor($field, 'errors');
            }
        }

        // A monthly report must start on the 1st: rejected with a message, not a 500.
        $this->postTest(['period_start' => '2026-10-02', 'type' => 'monthly'] + $valid, $this->token)->assertStatus(422)->assertJsonPath('ok', false);

        Mail::assertNothingSent();
        $this->assertSame(0, ReportDelivery::count());
    }

    public function test_a_sending_failure_is_a_json_500_without_details_that_could_leak(): void
    {
        $this->storedReport($this->hotel());
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused for user secret-user password TOP-SECRET'));

        $response = $this->postTest(['property' => 'nandini', 'type' => 'weekly', 'period_start' => '2026-11-09', 'to' => 'me@example.test'], $this->token);

        $response->assertStatus(500)->assertJsonPath('ok', false);
        $this->assertSame('Sending failed: RuntimeException', $response->json('message'));
        $this->assertStringNotContainsString('TOP-SECRET', $response->getContent());
    }

    public function test_the_route_is_in_the_cron_group_with_throttle_before_the_token_check(): void
    {
        $route = app('router')->getRoutes()->match(app('request')->create('/cron/send-test', 'POST'));

        $this->assertSame(['throttle:cron', 'cron.token'], array_slice($route->gatherMiddleware(), -2));
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['OPTIONS'])));
    }
}
