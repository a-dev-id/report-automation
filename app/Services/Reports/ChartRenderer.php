<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Property;
use App\Support\ReportPeriod;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renders the three report charts as PNG with QuickChart (POST {base_url}/chart with a Chart.js config).
 *
 *   trend      sessions per week (8) or month (12), one series in the brand color, value labels on the first point,
 *              the last point and the peak (labels that would touch are moved apart)
 *   channels   top 6 channels by sessions, horizontal bars
 *   countries  top 8 countries by ENGAGED sessions, horizontal bars, titled "Top countries by engaged sessions"
 *
 * Files go to {report.reports_path}/{property_slug}/{type}/{period_start}/{name}.png and are embedded in the
 * email as CID images. Any failure (disabled, no data, QuickChart down, bad response) returns null for that
 * chart and logs a warning: a chart can never fail a report, the email shows a table instead.
 */
class ChartRenderer
{
    public const NAMES = ['trend', 'channels', 'countries'];

    public const COUNTRIES_TITLE = 'Top countries by engaged sessions';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string|null> chart name => absolute PNG path, or null
     */
    public function render(Property $property, ReportPeriod $period, array $payload): array
    {
        $result = array_fill_keys(self::NAMES, null);

        if (! config('report.charts.enabled')) {
            return $result;
        }

        $color = $this->brandColor($property);
        $weekly = $period->type->value === 'weekly';
        $builders = [
            'trend' => fn () => $this->trendConfig($payload, $color, $weekly),
            'channels' => fn () => $this->barConfig($this->channelBars($payload), $color),
            'countries' => fn () => $this->barConfig($this->countryBars($payload), $color, self::COUNTRIES_TITLE),
        ];

        foreach ($builders as $name => $build) {
            try {
                $built = $build();
                if ($built === null) {
                    continue;   // no data for this chart
                }

                $png = $this->fetch($built['config'], $built['height']);
                $result[$name] = $this->store($property, $period, $name, $png);
            } catch (Throwable $e) {
                Log::warning('Report chart failed.', ['chart' => $name, 'property' => $property->slug, 'exception' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /** Where a chart of this report is stored. */
    public function path(Property $property, ReportPeriod $period, string $name): string
    {
        return rtrim((string) config('report.reports_path'), '/\\')."/{$property->slug}/{$period->type->value}/{$period->startDate()}/{$name}.png";
    }

    // -------------------------------------------------------------------------------------------

    /** @return string PNG bytes */
    private function fetch(string $chartJs, int $height): string
    {
        $response = Http::timeout((int) config('report.charts.timeout'))
            ->retry((int) config('report.charts.retries') + 1, 300, throw: false)
            ->acceptJson()
            ->post(rtrim((string) config('report.charts.base_url'), '/').'/chart', [
                'chart' => $chartJs,
                'width' => (int) config('report.charts.width'),
                'height' => $height,
                'devicePixelRatio' => (int) config('report.charts.device_pixel_ratio'),
                'backgroundColor' => 'white',
                'format' => 'png',
                'version' => '3',
            ]);

        $body = $response->body();
        if (! $response->successful() || ! str_starts_with($body, "\x89PNG")) {
            throw new \RuntimeException('QuickChart returned HTTP '.$response->status().' without a PNG image.');
        }

        return $body;
    }

    private function store(Property $property, ReportPeriod $period, string $name, string $png): string
    {
        $path = $this->path($property, $period, $name);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $png);

        return $path;
    }

    private function brandColor(Property $property): string
    {
        $color = (string) $property->brand_color;

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : (string) config('report.charts.default_color');
    }

    // ---- chart definitions -------------------------------------------------------------------

    /** @return array{config: string, height: int}|null */
    private function trendConfig(array $payload, string $color, bool $weekly): ?array
    {
        $trend = $payload['trend'] ?? null;
        $points = is_array($trend) && ! isset($trend['error']) ? ($trend['points'] ?? []) : [];
        if (count($points) < 2) {
            return null;
        }

        $labels = array_map(fn (array $p) => (new DateTimeImmutable($p['start']))->format($weekly ? 'j M' : 'M y'), $points);
        $sessions = array_map(fn (array $p) => (float) $p['sessions'], $points);
        [$display, $align] = $this->labelPlan($sessions);

        $config = [
            'type' => 'line',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => $weekly ? 'Sessions per week' : 'Sessions per month',
                    'data' => $sessions,
                    'borderColor' => $color,
                    'backgroundColor' => $color,
                    'borderWidth' => 4,
                    'pointRadius' => 5,
                    'tension' => 0,
                    'fill' => false,
                ]],
            ],
            'options' => [
                'layout' => ['padding' => ['top' => 28, 'right' => 28, 'left' => 8, 'bottom' => 8]],
                'plugins' => [
                    'legend' => ['display' => false],
                    'datalabels' => [
                        'anchor' => 'end',
                        'clamp' => true,
                        'color' => '#1F2937',
                        'font' => ['size' => 14, 'weight' => 'bold'],
                        'display' => '__DISPLAY__',
                        'align' => '__ALIGN__',
                        'formatter' => '__NUMBER__',
                    ],
                ],
                'scales' => [
                    'x' => ['grid' => ['display' => false], 'ticks' => ['font' => ['size' => 14], 'maxRotation' => 0, 'autoSkip' => true]],
                    'y' => ['beginAtZero' => true, 'grace' => '12%', 'ticks' => ['font' => ['size' => 14]], 'grid' => ['color' => '#E5E7EB']],
                ],
            ],
        ];

        return [
            'config' => $this->toJs($config, [
                '"__DISPLAY__"' => 'function(c){return '.json_encode($display).'[c.dataIndex];}',
                '"__ALIGN__"' => 'function(c){return '.json_encode($align).'[c.dataIndex];}',
            ]),
            'height' => 300,
        ];
    }

    /**
     * Which points get a value label (first, last, peak) and where it sits. Two labels on neighbouring points would
     * touch, so the lower one of such a pair goes below its point instead of above it.
     *
     * @param  list<float>  $values
     * @return array{0: list<bool>, 1: list<string>} display flags and label alignment per point
     */
    public function labelPlan(array $values): array
    {
        $last = count($values) - 1;
        $peak = array_search(max($values), $values, true);

        $labelled = array_values(array_unique([0, $last, (int) $peak]));
        sort($labelled);

        $display = array_fill(0, count($values), false);
        $align = array_fill(0, count($values), 'top');
        foreach ($labelled as $i) {
            $display[$i] = true;
        }

        for ($k = 1; $k < count($labelled); $k++) {
            [$a, $b] = [$labelled[$k - 1], $labelled[$k]];
            if ($b - $a <= 1) {
                $align[$values[$a] <= $values[$b] ? $a : $b] = 'bottom';
            }
        }

        return [$display, $align];
    }

    /**
     * @param  list<array{label: string, value: float}>  $bars
     * @return array{config: string, height: int}|null
     */
    private function barConfig(array $bars, string $color, ?string $title = null): ?array
    {
        if ($bars === []) {
            return null;
        }

        $plugins = [
            'legend' => ['display' => false],
            'datalabels' => [
                'anchor' => 'end',
                'align' => 'right',
                'color' => '#1F2937',
                'font' => ['size' => 14, 'weight' => 'bold'],
                'formatter' => '__NUMBER__',
            ],
        ];
        if ($title !== null) {
            $plugins['title'] = ['display' => true, 'text' => $title, 'color' => '#111827', 'font' => ['size' => 16, 'weight' => 'bold'], 'padding' => ['bottom' => 12]];
        }

        $config = [
            'type' => 'bar',
            'data' => [
                'labels' => array_column($bars, 'label'),
                'datasets' => [[
                    'data' => array_column($bars, 'value'),
                    'backgroundColor' => $color,
                    'borderRadius' => 4,
                    'barPercentage' => 0.75,
                ]],
            ],
            'options' => [
                'indexAxis' => 'y',
                'layout' => ['padding' => ['right' => 56, 'left' => 8, 'top' => 8, 'bottom' => 8]],
                'plugins' => $plugins,
                'scales' => [
                    'x' => ['beginAtZero' => true, 'grace' => '10%', 'ticks' => ['font' => ['size' => 14]], 'grid' => ['color' => '#E5E7EB']],
                    'y' => ['grid' => ['display' => false], 'ticks' => ['font' => ['size' => 14]]],
                ],
            ],
        ];

        return ['config' => $this->toJs($config), 'height' => 70 + 44 * count($bars) + ($title !== null ? 34 : 0)];
    }

    /**
     * QuickChart accepts the chart as a JavaScript object string, which lets the label formatter and the per-point label
     * rules be functions. Only the placeholders are replaced, everything else is plain JSON.
     *
     * @param  array<string, string>  $functions  quoted placeholder => JavaScript function source
     */
    private function toJs(array $config, array $functions = []): string
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $functions += ['"__NUMBER__"' => 'function(v){return Math.round(v).toLocaleString("en-US");}'];

        return str_replace(array_keys($functions), array_values($functions), $json);
    }

    /** @return list<array{label: string, value: float}> */
    private function channelBars(array $payload): array
    {
        $channels = $payload['channels'] ?? null;
        if (! is_array($channels) || isset($channels['error'])) {
            return [];
        }

        $rows = array_filter($channels['rows'] ?? [], fn (array $r) => ! ($r['isOther'] ?? false));

        return array_map(
            fn (array $r) => ['label' => (string) $r['channel'], 'value' => (float) $r['sessions']['current']],
            array_slice(array_values($rows), 0, 6),
        );
    }

    /** Top 8 countries by engaged sessions (the payload already ranks them that way). */
    private function countryBars(array $payload): array
    {
        $countries = $payload['countries'] ?? null;
        if (! is_array($countries) || isset($countries['error'])) {
            return [];
        }

        $rows = array_filter($countries['rows'] ?? [], fn (array $r) => ! ($r['isOther'] ?? false));

        return array_map(
            fn (array $r) => ['label' => (string) $r['country'], 'value' => (float) ($r['engagedSessions']['current'] ?? 0)],
            array_slice(array_values($rows), 0, 8),
        );
    }
}
