<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Property;

/**
 * Informational data-quality alerts for a finished payload. They describe, they never change numbers
 * and never fail a build. Thresholds are in config report.quality_alerts.
 *
 * Each alert is ['code' => string, 'message' => string, 'details' => array]:
 *   country_sum_mismatch   the country rows add up to more than the site total (countries.warning)
 *   direct_low_engagement  Direct is a big share of sessions and its sessions are very short
 *   currency_mismatch      GA4 reports money in another currency than properties.currency
 */
final class QualityAlerts
{
    /**
     * @param  array<string, mixed>  $payload  countries and channels may be error arrays or absent
     * @return list<array{code: string, message: string, details: array<string, mixed>}>
     */
    public static function evaluate(Property $property, array $payload, ?string $ga4Currency): array
    {
        $alerts = [];
        $config = config('report.quality_alerts');

        $countries = $payload['countries'] ?? null;
        if (is_array($countries) && ($countries['warning'] ?? false) === true) {
            $sum = (float) $countries['sessionsSum'];
            $total = (float) $countries['sessionsTotal'];
            $excess = $total > 0 ? round(($sum / $total - 1) * 100, 1) : null;

            $alerts[] = [
                'code' => 'country_sum_mismatch',
                'message' => 'The country rows add up to more sessions than the site total, so GA4 counts some sessions in several countries '
                    .'(often proxy or bot traffic). Country figures are shown as GA4 returns them.',
                'details' => [
                    'sessionsSum' => $sum,
                    'sessionsTotal' => $total,
                    'excessPercent' => $excess,
                    'tolerancePercent' => (float) $config['country_sum_tolerance_percent'],
                ],
            ];
        }

        $channels = $payload['channels'] ?? null;
        if (is_array($channels) && isset($channels['rows'], $channels['total']['sessions']['current'])) {
            $total = (float) $channels['total']['sessions']['current'];
            foreach ($channels['rows'] as $row) {
                if (($row['channel'] ?? null) !== 'Direct' || $total <= 0) {
                    continue;
                }

                $sessions = (float) ($row['sessions']['current'] ?? 0);
                $share = round($sessions / $total * 100, 1);
                $duration = $row['averageSessionDuration']['current'] ?? null;

                if ($duration !== null
                    && $share > (float) $config['direct_min_share_percent']
                    && (float) $duration < (float) $config['direct_max_avg_session_seconds']) {
                    $alerts[] = [
                        'code' => 'direct_low_engagement',
                        'message' => 'More than half of the sessions are Direct with a very short average session. '
                            .'This is often automated or low-quality traffic and can distort the averages.',
                        'details' => [
                            'directSessions' => $sessions,
                            'directSharePercent' => $share,
                            'directAverageSessionDuration' => round((float) $duration, 1),
                            'minSharePercent' => (float) $config['direct_min_share_percent'],
                            'maxAverageSessionSeconds' => (float) $config['direct_max_avg_session_seconds'],
                        ],
                    ];
                }
            }
        }

        if ($ga4Currency !== null && strcasecmp($ga4Currency, (string) $property->currency) !== 0) {
            $alerts[] = [
                'code' => 'currency_mismatch',
                'message' => "GA4 reports money in {$ga4Currency}, but this property is set to {$property->currency}. "
                    .'Revenue and ad cost are shown in the GA4 currency.',
                'details' => ['ga4Currency' => $ga4Currency, 'propertyCurrency' => $property->currency],
            ];
        }

        return $alerts;
    }
}
