<?php

/**
 * Standalone checks for App\Support\ReportPeriod. No Laravel or PHPUnit needed.
 * Run from the project root:  php tests/standalone/period_check.php
 */

declare(strict_types=1);

require __DIR__.'/../../app/Enums/ReportType.php';
require __DIR__.'/../../app/Support/ReportPeriod.php';

use App\Enums\ReportType;
use App\Support\ReportPeriod;

$failures = 0;
$checks = 0;

function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual === $expected) {
        return;
    }
    $failures++;
    echo "FAIL  {$name}\n      expected: ".var_export($expected, true)."\n      actual:   ".var_export($actual, true)."\n";
}

function ranges(ReportPeriod $p): array
{
    return $p->dateRanges();
}

$mak = new DateTimeZone('Asia/Makassar');
$utc = new DateTimeZone('UTC');
$ny = new DateTimeZone('America/New_York');

// ---- Weekly ----
$p = ReportPeriod::forType(ReportType::Weekly, new DateTimeImmutable('2026-11-23 08:00', $mak), $mak);
check('weekly: run on Monday 23 Nov', ranges($p), [
    'current' => ['2026-11-16', '2026-11-22'],
    'previous' => ['2026-11-09', '2026-11-15'],
    'yearAgo' => null,
]);
check('weekly: label', $p->label(), '16 to 22 Nov 2026');

$p = ReportPeriod::forType(ReportType::Weekly, new DateTimeImmutable('2026-11-21 07:30', $mak), $mak);
check('weekly: run on Saturday 21 Nov uses the last complete week', ranges($p)['current'], ['2026-11-09', '2026-11-15']);

$p = ReportPeriod::forType(ReportType::Weekly, new DateTimeImmutable('2026-12-07 08:00', $mak), $mak);
check('weekly: crosses a month', [ranges($p)['current'], $p->label()], [['2026-11-30', '2026-12-06'], '30 Nov to 6 Dec 2026']);

$p = ReportPeriod::forType(ReportType::Weekly, new DateTimeImmutable('2027-01-04 08:00', $mak), $mak);
check('weekly: crosses a year', [ranges($p)['current'], $p->label()], [['2026-12-28', '2027-01-03'], '28 Dec 2026 to 3 Jan 2027']);

// Same instant, different timezone: 2026-11-22 23:30 UTC
$instant = new DateTimeImmutable('2026-11-22 23:30:00', $utc);
check('weekly: Makassar is already Monday', ranges(ReportPeriod::forType(ReportType::Weekly, $instant, $mak))['current'], ['2026-11-16', '2026-11-22']);
check('weekly: New York is still Sunday', ranges(ReportPeriod::forType(ReportType::Weekly, $instant, $ny))['current'], ['2026-11-09', '2026-11-15']);

// ---- Monthly ----
$p = ReportPeriod::forType(ReportType::Monthly, new DateTimeImmutable('2026-11-03 08:00', $mak), $mak);
check('monthly: run on 3 Nov', ranges($p), [
    'current' => ['2026-10-01', '2026-10-31'],
    'previous' => ['2026-09-01', '2026-09-30'],
    'yearAgo' => ['2025-10-01', '2025-10-31'],
]);
check('monthly: label', $p->label(), 'October 2026');

$p = ReportPeriod::forType(ReportType::Monthly, new DateTimeImmutable('2027-01-03 08:00', $mak), $mak);
check('monthly: January run reports December', ranges($p), [
    'current' => ['2026-12-01', '2026-12-31'],
    'previous' => ['2026-11-01', '2026-11-30'],
    'yearAgo' => ['2025-12-01', '2025-12-31'],
]);

$p = ReportPeriod::forType(ReportType::Monthly, new DateTimeImmutable('2026-03-31 08:00', $mak), $mak);
check('monthly: run on the 31st reports February (no overflow)', ranges($p), [
    'current' => ['2026-02-01', '2026-02-28'],
    'previous' => ['2026-01-01', '2026-01-31'],
    'yearAgo' => ['2025-02-01', '2025-02-28'],
]);

$p = ReportPeriod::forType(ReportType::Monthly, new DateTimeImmutable('2028-03-03 08:00', $mak), $mak);
check('monthly: leap year February', ranges($p), [
    'current' => ['2028-02-01', '2028-02-29'],
    'previous' => ['2028-01-01', '2028-01-31'],
    'yearAgo' => ['2027-02-01', '2027-02-28'],
]);

$p = ReportPeriod::forType(ReportType::Monthly, new DateTimeImmutable('2026-03-01 00:10', $mak), $mak);
check('monthly: run just after midnight on the 1st', ranges($p)['current'], ['2026-02-01', '2026-02-28']);

// ---- fromStart ----
check('fromStart weekly', ranges(ReportPeriod::fromStart(ReportType::Weekly, '2026-11-16', $mak))['current'], ['2026-11-16', '2026-11-22']);
check('fromStart monthly', ranges(ReportPeriod::fromStart(ReportType::Monthly, '2026-10-01', $mak))['current'], ['2026-10-01', '2026-10-31']);

foreach ([
    'weekly start not Monday' => [ReportType::Weekly, '2026-11-17'],
    'monthly start not the 1st' => [ReportType::Monthly, '2026-10-02'],
    'impossible date' => [ReportType::Monthly, '2026-02-30'],
    'bad format' => [ReportType::Weekly, '16-11-2026'],
] as $name => [$type, $date]) {
    $threw = false;
    try {
        ReportPeriod::fromStart($type, $date, $mak);
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    check("fromStart rejects: {$name}", $threw, true);
}

// ---- Search Console delay ----
function gsc(ReportPeriod $p, string $now, DateTimeZone $tz): array
{
    $w = $p->searchConsoleWindow(new DateTimeImmutable($now, $tz));

    return [$w['hasData'], $w['truncated'], $w['daysMissing'], $w['end']->format('Y-m-d'), $w['previousEnd']->format('Y-m-d')];
}

$week = ReportPeriod::fromStart(ReportType::Weekly, '2026-11-16', $mak);
check('gsc: weekly report on Monday is missing the weekend', gsc($week, '2026-11-23 08:00', $mak), [true, true, 2, '2026-11-20', '2026-11-13']);
check('gsc: weekly report on Wednesday is complete', gsc($week, '2026-11-25 08:00', $mak), [true, false, 0, '2026-11-22', '2026-11-15']);

$month = ReportPeriod::fromStart(ReportType::Monthly, '2026-10-01', $mak);
check('gsc: monthly report on the 3rd is complete', gsc($month, '2026-11-03 08:00', $mak), [true, false, 0, '2026-10-31', '2026-09-30']);
check('gsc: monthly report on the 2nd is missing 1 day', gsc($month, '2026-11-02 08:00', $mak), [true, true, 1, '2026-10-30', '2026-09-29']);
check('gsc: run too early has no data', gsc($week, '2026-11-17 08:00', $mak)[0], false);

echo "\n{$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
