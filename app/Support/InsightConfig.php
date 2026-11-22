<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;

/** Reads config report.insight.{key}, letting report.insight.properties.{slug}.{key} override it for one hotel. */
final class InsightConfig
{
    public static function get(string $key, ?string $slug = null, mixed $default = null): mixed
    {
        if ($slug !== null && $slug !== '') {
            $override = Arr::get((array) config('report.insight.properties', []), $slug.'.'.$key);
            if ($override !== null) {
                return $override;
            }
        }

        return config("report.insight.{$key}", $default);
    }

    /** @return list<string> */
    public static function list(string $key, ?string $slug = null): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            (array) self::get($key, $slug, []),
        ), fn (string $v) => $v !== ''));
    }
}
