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
 *   trend      sessions per week (8) or month (12), brand color, with the previous period's level in gray
 *   channels   top 6 channels by sessions, horizontal bars
 *   countries  top 8 countries by sessions, horizontal bars
 *
 * Files go to storage/app/reports/{property_slug}/{type}/{period_start}/{name}.png and are embedded in the
 * email as CID images. Any failure (disabled, no data, QuickChart down, bad response) returns null for that
 * chart and logs a warning: a chart can never fail a report, the email shows a table instead.
 */
class ChartRenderer
{
    public const NAMES = ['trend', 'channels', 'countries'];

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
            'countries' => fn () => $this->barConfig($this->countryBars($payload), $color),
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
        $previous = $payload['site']['sessions']['previous'] ?? null;
        $last = count($sessions) - 1;
        $gray = (string) config('report.charts.neutral_color');

        $datasets = [[
            'label' => $weekly ? 'Sessions per week' : 'Sessions per month',
            'data' => $sessions,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'borderWidth' => 4,
            'pointRadius' => 5,
            'tension' => 0,
            'fill' => false,
        ]];

        if (is_numeric($previous)) {
            $datasets[] = [
                'label' => $weekly ? 'Previous week' : 'Previous month',
                'data' => array_fill(0, count($sessions), (float) $previous),
                'borderColor' => $gray,
                'backgroundColor' => $gray,
                'borderWidth' => 3,
                'borderDash' => [8, 6],
                'pointRadius' => 0,
                'datalabels' => ['display' => false],
            ];
        }

        $config = [
            'type' => 'line',
            'data' => ['labels' => $labels, 'datasets' => $datasets],
            'options' => [
                'layout' => ['padding' => ['top' => 24, 'right' => 24, 'left' => 8, 'bottom' => 8]],
                'plugins' => [
                    'legend' => ['display' => is_numeric($previous), 'position' => 'bottom', 'labels' => ['font' => ['size' => 14], 'usePointStyle' => true]],
                    // Value labels on the current period, the one before it and the peak, so a 12-point line stays readable.
                    'datalabels' => [
                        'align' => 'top',
                        'anchor' => 'end',
                        'color' => '#1F2937',
                        'font' => ['size' => 14, 'weight' => 'bold'],
                        'display' => '__SHOW_LABEL__',
                        'formatter' => '__NUMBER__',
                    ],
                ],
                'scales' => [
                    'x' => ['grid' => ['display' => false], 'ticks' => ['font' => ['size' => 14], 'maxRotation' => 0, 'autoSkip' => true]],
                    'y' => ['beginAtZero' => true, 'grace' => '12%', 'ticks' => ['font' => ['size' => 14]], 'grid' => ['color' => '#E5E7EB']],
                ],
            ],
        ];

        return ['config' => $this->toJs($config, $last), 'height' => 320];
    }

    /**
     * @param  list<array{label: string, value: float}>  $bars
     * @return array{config: string, height: int}|null
     */
    private function barConfig(array $bars, string $color): ?array
    {
        if ($bars === []) {
            return null;
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
                'plugins' => [
                    'legend' => ['display' => false],
                    'datalabels' => [
                        'anchor' => 'end',
                        'align' => 'right',
                        'color' => '#1F2937',
                        'font' => ['size' => 14, 'weight' => 'bold'],
                        'formatter' => '__NUMBER__',
                    ],
                ],
                'scales' => [
                    'x' => ['beginAtZero' => true, 'grace' => '10%', 'ticks' => ['font' => ['size' => 14]], 'grid' => ['color' => '#E5E7EB']],
                    'y' => ['grid' => ['display' => false], 'ticks' => ['font' => ['size' => 14]]],
                ],
            ],
        ];

        return ['config' => $this->toJs($config, 0), 'height' => 70 + 44 * count($bars)];
    }

    /**
     * QuickChart accepts the chart as a JavaScript object string, which lets the label formatter be a function.
     * Only the two placeholders below are replaced, everything else is plain JSON.
     */
    private function toJs(array $config, int $lastIndex): string
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        return str_replace(
            ['"__NUMBER__"', '"__SHOW_LABEL__"'],
            [
                'function(v){return Math.round(v).toLocaleString("en-US");}',
                "function(c){var d=c.dataset.data;var m=Math.max.apply(null,d);return c.datasetIndex===0&&(c.dataIndex>={$lastIndex}-1||d[c.dataIndex]===m);}",
            ],
            $json,
        );
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

    /** @return list<array{label: string, value: float}> */
    private function countryBars(array $payload): array
    {
        $countries = $payload['countries'] ?? null;
        if (! is_array($countries) || isset($countries['error'])) {
            return [];
        }

        $rows = array_filter($countries['rows'] ?? [], fn (array $r) => ! ($r['isOther'] ?? false));

        return array_map(
            fn (array $r) => ['label' => (string) $r['country'], 'value' => (float) $r['sessions']['current']],
            array_slice(array_values($rows), 0, 8),
        );
    }
}
