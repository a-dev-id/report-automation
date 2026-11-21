<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReportType;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One reporting period (a week or a calendar month) and the periods it is compared with.
 *
 * All dates are calendar days in the property's timezone. Start and end are both inclusive.
 * Weekly: Monday to Sunday, compared with the week before.
 * Monthly: 1st to last day, compared with the month before and the same month last year.
 *
 * This class uses only native PHP date classes so it can be tested without Laravel.
 */
final class ReportPeriod
{
    private function __construct(
        public readonly ReportType $type,
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly DateTimeImmutable $previousStart,
        public readonly DateTimeImmutable $previousEnd,
        public readonly ?DateTimeImmutable $yearAgoStart,
        public readonly ?DateTimeImmutable $yearAgoEnd,
    ) {
    }

    /** The most recent complete period of the given type, as seen from $now in $tz. */
    public static function forType(ReportType $type, DateTimeInterface $now, DateTimeZone $tz): self
    {
        return match ($type) {
            ReportType::Weekly => self::lastCompleteWeek($now, $tz),
            ReportType::Monthly => self::lastCompleteMonth($now, $tz),
        };
    }

    public static function lastCompleteWeek(DateTimeInterface $now, DateTimeZone $tz): self
    {
        $today = self::startOfDay($now, $tz);
        $thisMonday = $today->modify('-'.((int) $today->format('N') - 1).' days');

        return self::weekFrom($thisMonday->modify('-7 days'));
    }

    public static function lastCompleteMonth(DateTimeInterface $now, DateTimeZone $tz): self
    {
        $today = self::startOfDay($now, $tz);
        $firstOfThisMonth = $today->modify('first day of this month');

        return self::monthFrom($firstOfThisMonth->modify('first day of last month'));
    }

    /** Rebuild a period from its stored start date (Y-m-d). Used by queued jobs. */
    public static function fromStart(ReportType $type, string $startDate, DateTimeZone $tz): self
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $tz);
        if ($start === false || $start->format('Y-m-d') !== $startDate) {
            throw new InvalidArgumentException("Invalid period start date: {$startDate}");
        }

        if ($type === ReportType::Weekly) {
            if ($start->format('N') !== '1') {
                throw new InvalidArgumentException("A weekly period must start on a Monday: {$startDate}");
            }

            return self::weekFrom($start);
        }

        if ($start->format('j') !== '1') {
            throw new InvalidArgumentException("A monthly period must start on the 1st: {$startDate}");
        }

        return self::monthFrom($start);
    }

    private static function weekFrom(DateTimeImmutable $start): self
    {
        return new self(
            ReportType::Weekly,
            $start,
            $start->modify('+6 days'),
            $start->modify('-7 days'),
            $start->modify('-1 day'),
            null,
            null,
        );
    }

    private static function monthFrom(DateTimeImmutable $start): self
    {
        $yearAgoStart = $start->modify('-1 year');

        return new self(
            ReportType::Monthly,
            $start,
            $start->modify('last day of this month'),
            $start->modify('first day of last month'),
            $start->modify('-1 day'),
            $yearAgoStart,
            $yearAgoStart->modify('last day of this month'),
        );
    }

    public function startDate(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function endDate(): string
    {
        return $this->end->format('Y-m-d');
    }

    /**
     * Date ranges as Y-m-d pairs, ready for the GA4 runReport dateRanges field.
     *
     * @return array{current: array{0: string, 1: string}, previous: array{0: string, 1: string}, yearAgo: array{0: string, 1: string}|null}
     */
    public function dateRanges(): array
    {
        return [
            'current' => [$this->startDate(), $this->endDate()],
            'previous' => [$this->previousStart->format('Y-m-d'), $this->previousEnd->format('Y-m-d')],
            'yearAgo' => $this->yearAgoStart === null
                ? null
                : [$this->yearAgoStart->format('Y-m-d'), $this->yearAgoEnd->format('Y-m-d')],
        ];
    }

    /** Short label for subject lines: "9 to 15 Nov 2026", "30 Nov to 6 Dec 2026" or "October 2026". */
    public function label(): string
    {
        if ($this->type === ReportType::Monthly) {
            return $this->start->format('F Y');
        }

        if ($this->start->format('Y') !== $this->end->format('Y')) {
            return $this->start->format('j M Y').' to '.$this->end->format('j M Y');
        }

        if ($this->start->format('n') !== $this->end->format('n')) {
            return $this->start->format('j M').' to '.$this->end->format('j M Y');
        }

        return $this->start->format('j').' to '.$this->end->format('j M Y');
    }

    /**
     * Search Console data is usually 2 to 3 days behind. This returns the part of the period
     * that is ready at $now, plus the comparison period cut by the same number of days so the
     * two are the same length.
     *
     * @return array{hasData: bool, truncated: bool, daysMissing: int, start: DateTimeImmutable, end: DateTimeImmutable, previousStart: DateTimeImmutable, previousEnd: DateTimeImmutable}
     */
    public function searchConsoleWindow(DateTimeInterface $now, int $delayDays = 3): array
    {
        $cutoff = self::startOfDay($now, $this->start->getTimezone())->modify("-{$delayDays} days");
        $missing = max(0, self::daysBetween($cutoff, $this->end));
        $end = $this->end->modify("-{$missing} days");

        return [
            'hasData' => $end >= $this->start,
            'truncated' => $missing > 0,
            'daysMissing' => $missing,
            'start' => $this->start,
            'end' => $end,
            'previousStart' => $this->previousStart,
            'previousEnd' => $this->previousEnd->modify("-{$missing} days"),
        ];
    }

    private static function startOfDay(DateTimeInterface $now, DateTimeZone $tz): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($now)->setTimezone($tz)->setTime(0, 0);
    }

    /** Signed number of calendar days from $from to $to, ignoring clock time and DST. */
    private static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $utc = new DateTimeZone('UTC');
        $a = new DateTimeImmutable($from->format('Y-m-d'), $utc);
        $b = new DateTimeImmutable($to->format('Y-m-d'), $utc);

        return (int) $a->diff($b)->format('%r%a');
    }
}
