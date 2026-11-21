<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ReportType;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\Report;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Sends one report email to explicit addresses only, for checking how it looks in a real inbox.
 *
 * It builds the email exactly like the real job (same payload source, same charts, same ReportMail with CID images) with
 * "[TEST] " in front of the subject, and then sends it to $to and nobody else.
 *
 * What it deliberately does not do: read the recipients table, write a reports row (nothing touches the idempotency
 * record), create report_deliveries, or mark anything sent. The report is an unsaved model. The payload is the stored one
 * if a report exists (read only), else a cached preview payload, else a real build (calls Google).
 */
class ReportTestSender
{
    public function __construct(
        private readonly ReportPreview $preview,
        private readonly ChartRenderer $charts,
    ) {
    }

    /**
     * @param  list<string>  $to
     * @return array{subject: string, mailer: string, to: list<string>}
     *
     * @throws InvalidArgumentException when there is no valid address or the period start is wrong
     */
    public function send(Property $property, ReportType $type, ?string $periodStart, array $to): array
    {
        $to = self::addresses($to);

        $tz = new DateTimeZone($property->timezone ?: 'UTC');
        $period = $periodStart !== null && $periodStart !== ''
            ? ReportPeriod::fromStart($type, $periodStart, $tz)
            : ReportPeriod::forType($type, CarbonImmutable::now(), $tz);

        [$payload] = $this->preview->payload($property, $period, false);
        $charts = $this->charts->render($property, $period, $payload);

        $report = new Report([
            'property_id' => $property->id,
            'type' => $type->value,
            'period_start' => $period->startDate(),
            'period_end' => $period->endDate(),
            'payload' => $payload,
        ]);
        $report->setRelation('property', $property);

        $mail = new ReportMail($report, $property, $charts, '[TEST] ');
        Mail::to($to)->send($mail);

        return ['subject' => $mail->envelope()->subject, 'mailer' => (string) config('mail.default'), 'to' => $to];
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string> trimmed, unique, all valid
     *
     * @throws InvalidArgumentException
     */
    public static function addresses(array $addresses): array
    {
        $clean = [];
        foreach ($addresses as $address) {
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException("'{$address}' is not a valid email address.");
            }
            $clean[strtolower($address)] = $address;
        }

        if ($clean === []) {
            throw new InvalidArgumentException('At least one recipient address is required.');
        }

        return array_values($clean);
    }
}
