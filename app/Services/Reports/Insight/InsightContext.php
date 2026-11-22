<?php

declare(strict_types=1);

namespace App\Services\Reports\Insight;

use App\Support\InsightConfig;
use App\Support\ReportFormat;
use App\Support\Rich;
use DateTimeImmutable;
use Throwable;

/**
 * Everything the insight rules share: the payload, week or month wording, the large-change guard and number formatting.
 *
 * Guard: a metric that moved by more than report.insight.large_drop_percent (down) or large_rise_percent (up) is not stated
 * as a fact. phrase() gives only its value, dir() answers "guarded" so no rule interprets it, and the metric is remembered so
 * the caution can ask for a tracking check. A zero or missing previous value has no percent and is never guarded.
 */
final class InsightContext
{
    public readonly bool $monthly;

    /** "This week" or "This month". */
    public readonly string $thisPeriod;

    /** "the previous week" or "last month". */
    public readonly string $previous;

    /** "week" or "month". */
    public readonly string $unit;

    /** "October 2025" for a monthly report, else null. */
    public readonly ?string $yearAgoName;

    public readonly string $currency;

    public readonly string $slug;

    /** @var array<string, array{label: string, percent: float, against: string}> */
    private array $flagged = [];

    /** @param  array<string, mixed>  $payload */
    public function __construct(public readonly array $payload)
    {
        $this->monthly = ($payload['meta']['type'] ?? 'weekly') === 'monthly';
        $this->thisPeriod = $this->monthly ? 'This month' : 'This week';
        $this->previous = $this->monthly ? 'last month' : 'the previous week';
        $this->unit = $this->monthly ? 'month' : 'week';
        $this->yearAgoName = $this->monthly ? $this->yearAgoLabel() : null;
        $this->currency = (string) ($payload['meta']['currency'] ?? '');
        $this->slug = (string) ($payload['meta']['slug'] ?? '');
    }

    /** @return array<string, mixed>|null the section, or null when it is missing or an {error} object */
    public function section(string $key): ?array
    {
        $section = $this->payload[$key] ?? null;

        return is_array($section) && ! isset($section['error']) ? $section : null;
    }

    /** A threshold from config report.insight, with the per-property override. */
    public function config(string $key, mixed $default = null): mixed
    {
        return InsightConfig::get($key, $this->slug, $default);
    }

    // ---- the guard ---------------------------------------------------------------------------------

    public function guarded(?array $change): bool
    {
        $percent = $change['percent'] ?? null;
        if ($percent === null) {
            return false;
        }

        return $percent < -(float) $this->config('large_drop_percent', 60) || $percent > (float) $this->config('large_rise_percent', 200);
    }

    /**
     * up, down, flat, new, none, or "guarded" when the change is too large to state. With a label the guarded metric is
     * remembered for the caution.
     */
    public function dir(?array $metric, ?string $label = null, bool $yearAgo = false): string
    {
        if ($metric === null) {
            return 'none';
        }

        $change = $metric[$yearAgo ? 'yearAgoChange' : 'change'] ?? null;
        if ($this->guarded($change)) {
            if ($label !== null) {
                $this->flag($label, $change, $yearAgo ? ($this->yearAgoName ?? 'the same month last year') : $this->previous);
            }

            return 'guarded';
        }

        return (string) ($change['direction'] ?? 'none');
    }

    public function flag(string $label, array $change, string $against): void
    {
        $this->flagged["{$label}|{$against}"] = ['label' => $label, 'percent' => (float) $change['percent'], 'against' => $against];
    }

    /** One neutral sentence for every metric the guard held back, or null. */
    public function largeChangeSentence(): ?string
    {
        if ($this->flagged === []) {
            return null;
        }

        $items = [];
        foreach (array_values($this->flagged) as $i => $f) {
            $items[] = sprintf(
                '%s changed by %s%s versus %s',
                $i === 0 ? $f['label'] : lcfirst($f['label']),
                $f['percent'] > 0 ? '+' : '',
                ReportFormat::percent($f['percent']),
                $f['against'],
            );
        }

        return implode(' and ', $items).'. '
            .(count($items) > 1 ? 'Changes this large often come' : 'A change this large often comes')
            .' from a change in how events are tracked, so please check the tracking setup before drawing conclusions.';
    }

    // ---- formatting --------------------------------------------------------------------------------

    /** A value as a bold token. kind: n (number), rate (fraction), money, dur (seconds), pos (one decimal). */
    public function value(string $kind, int|float|null $v): string
    {
        return Rich::value($this->format($kind, $v));
    }

    public function format(string $kind, int|float|null $v): string
    {
        return match ($kind) {
            'rate' => ReportFormat::rate($v),
            'money' => ReportFormat::money($v, $this->currency),
            'dur' => ReportFormat::duration($v),
            'pos' => ReportFormat::number($v, 1),
            'pct' => ReportFormat::percent($v),
            default => ReportFormat::number($v),
        };
    }

    /** A change as a bold colored token (arrow and sign included). */
    public function change(?array $change, ?string $metricKey = null, bool $rate = false): string
    {
        return Rich::change($rate ? ReportFormat::rateChange($change, $metricKey) : ReportFormat::change($change, $metricKey));
    }

    /**
     * "rose ▲ +25.0% to 10,000", "fell ▼ -9.8% to 6,545", "held at 62.0%", "reached 5 (new this period)",
     * "was 7 (no comparison available)", or for a guarded change "was 148 (see the note below on the size of the change)".
     */
    public function phrase(?array $metric, string $kind = 'n', ?string $label = null, ?string $metricKey = null): ?string
    {
        if ($metric === null || ($metric['current'] ?? null) === null) {
            return null;
        }

        $value = $this->value($kind, $metric['current']);
        $change = $metric['change'] ?? [];

        if ($this->guarded($change)) {
            if ($label !== null) {
                $this->flag($label, $change, $this->previous);
            }

            return "was {$value} (see the note below on the size of the change)";
        }

        $styled = $kind === 'rate' ? ReportFormat::rateChange($change, $metricKey) : ReportFormat::change($change, $metricKey);
        $token = Rich::change($styled);

        return match ($styled['direction']) {
            'up' => "rose {$token} to {$value}",
            'down' => "fell {$token} to {$value}",
            'flat' => "held at {$value}",
            'new' => "reached {$value} (new this period)",
            default => "was {$value} (no comparison available)",
        };
    }

    /** "up ▲ +100.0%" for the year-ago comparison, or null (not monthly, no data, flat/none, or guarded and noted). */
    public function yearAgoPhrase(?array $metric, ?string $label = null, ?string $metricKey = null): ?string
    {
        if ($metric === null || ! array_key_exists('yearAgoChange', $metric)) {
            return null;
        }

        $direction = $this->dir($metric, $label, yearAgo: true);
        if (! in_array($direction, ['up', 'down'], true)) {
            return null;
        }

        return ($direction === 'up' ? 'up ' : 'down ').$this->change($metric['yearAgoChange'], $metricKey);
    }

    /** Percentage-point move of a rate metric (fractions): current minus previous, or null without both values. */
    public function points(?array $metric): ?float
    {
        if ($metric === null || ($metric['current'] ?? null) === null || ($metric['previous'] ?? null) === null) {
            return null;
        }

        return ((float) $metric['current'] - (float) $metric['previous']) * 100;
    }

    /** Percent change of a metric, or null when there is none (new, none) or it is guarded. */
    public function percent(?array $metric): ?float
    {
        $change = $metric['change'] ?? null;

        return $change === null || $this->guarded($change) ? null : ($change['percent'] ?? null);
    }

    /** "1, 2 and 3". */
    public function join(array $items): string
    {
        $items = array_values($items);
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }

    private function yearAgoLabel(): ?string
    {
        $start = $this->payload['meta']['dateRanges']['yearAgo'][0] ?? null;
        if (! is_string($start)) {
            return null;
        }

        try {
            return (new DateTimeImmutable($start))->format('F Y');
        } catch (Throwable) {
            return null;
        }
    }
}
