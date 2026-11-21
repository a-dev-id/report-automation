<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReportType;
use App\Models\Property;
use App\Services\Reports\ReportTestSender;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ReportSendTestCommand extends Command
{
    protected $signature = 'report:send-test
        {property : Property slug}
        {type : weekly or monthly}
        {periodStart? : Period start, Y-m-d (a Monday for weekly, the 1st for monthly). Default: the latest complete period}
        {--to= : Address to send to (required). Several addresses can be separated by commas}';

    protected $description = 'Send one report email, prefixed "[TEST] ", ONLY to the address(es) in --to. No recipients table, no deliveries, no report record';

    public function handle(ReportTestSender $sender): int
    {
        $to = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('to'))), fn (string $a) => $a !== ''));
        if ($to === []) {
            $this->error('Refusing to run without --to=address@example.com. A test email is only ever sent to the address you give.');

            return self::INVALID;
        }

        $type = ReportType::tryFrom((string) $this->argument('type'));
        if ($type === null) {
            $this->error('Type must be weekly or monthly.');

            return self::INVALID;
        }

        $property = Property::query()->where('slug', $this->argument('property'))->first();
        if ($property === null) {
            $this->error("No property with slug '{$this->argument('property')}'.");

            return self::FAILURE;
        }

        try {
            $result = $sender->send($property, $type, $this->argument('periodStart') ?: null, $to);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (Throwable $e) {
            // Class and message only: transport errors can be long, but never include the configured password.
            $this->error('Sending failed: '.class_basename($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mailer:  '.$result['mailer']);
        $this->line('Subject: '.$result['subject']);
        foreach ($result['to'] as $address) {
            $this->line('To:      '.$address);
        }
        if ($result['mailer'] === 'log' || $result['mailer'] === 'array') {
            $this->warn("Mailer '{$result['mailer']}' does not deliver anything: the email was only written to the log. Set MAIL_MAILER=smtp to receive it.");
        }

        return self::SUCCESS;
    }
}
