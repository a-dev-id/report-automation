<?php

declare(strict_types=1);

namespace App\Services\Reports\Insight;

/**
 * "Advertising" paragraph. Needs payload.paid with GA4 data (a property without paid data, or with the Google Ads API
 * source, has no paragraph). Every rule is a public method returning a Finding or null:
 *
 *   overview              what changed: impressions, clicks, CTR, cost and (when GA4 has them) purchases from paid channels
 *   reachUpResponseDown   impressions up, clicks down, CTR down: reached more people, fewer responded
 *   reachOutpacingResponse impressions up and clicks up, but CTR down: clicks grew more slowly than impressions
 *   reachAndResponseDown  impressions down and clicks down: advertising reach shrank
 *   spendWithoutPurchases cost up and no purchases from paid channels
 *   efficiency            clicks up and cost per click down
 *   lessEfficient         cost per click up: each click cost more
 *   awareness             the biggest campaigns are display or awareness campaigns (config report.insight.awareness_terms)
 *
 * A rule never fires on a metric whose change is beyond the large-change guard.
 */
final class AdvertisingRules
{
    /** @return list<Finding> */
    public function findings(InsightContext $c): array
    {
        $paid = $this->paid($c);
        if ($paid === null) {
            return [];
        }

        $out = array_filter([$this->overview($c, $paid)]);
        foreach (['reachUpResponseDown', 'reachOutpacingResponse', 'reachAndResponseDown', 'spendWithoutPurchases', 'efficiency', 'lessEfficient', 'awareness'] as $rule) {
            $finding = $this->{$rule}($c, $paid);
            if ($finding !== null) {
                $out[] = $finding->withRule($rule);
            }
        }

        return array_values($out);
    }

    /** @return array<string, mixed>|null the paid section when it has GA4 metrics */
    public function paid(InsightContext $c): ?array
    {
        $paid = $c->section('paid');

        return $paid !== null && ($paid['available'] ?? false) === true && is_array($paid['metrics'] ?? null) ? $paid : null;
    }

    public function overview(InsightContext $c, array $paid): ?Finding
    {
        $m = $paid['metrics'];
        $impressions = $c->phrase($m['impressions'] ?? null, 'n', 'Paid impressions');
        $clicks = $c->phrase($m['clicks'] ?? null, 'n', 'Paid clicks');
        $ctr = $c->phrase($m['ctr'] ?? null, 'rate', 'Click-through rate');
        $cost = $c->phrase($m['cost'] ?? null, 'money', 'Advertising cost', 'cost');

        if ($impressions === null || $clicks === null) {
            return null;
        }

        $text = "Paid impressions {$impressions}, clicks {$clicks}".($ctr !== null ? " and click-through rate {$ctr}" : '').'.';

        if ($cost !== null) {
            $text .= " Advertising cost {$cost}";
            $purchases = $m['purchases'] ?? null;
            if (is_array($purchases) && ($purchases['current'] ?? null) !== null) {
                $text .= (float) $purchases['current'] <= 0
                    ? ', while no purchases were recorded from paid channels'
                    : ', with '.$c->value('n', $purchases['current']).((float) $purchases['current'] === 1.0 ? ' purchase' : ' purchases').' recorded from paid channels';
            }
            $text .= '.';
        }

        return new Finding($text, weight: 100, essential: true, rule: 'overview');
    }

    public function reachUpResponseDown(InsightContext $c, array $paid): ?Finding
    {
        $m = $paid['metrics'];

        if ($c->dir($m['impressions'] ?? null) === 'up' && $c->dir($m['clicks'] ?? null) === 'down' && $c->dir($m['ctr'] ?? null) === 'down') {
            return new Finding(
                'The ads reached more people but fewer responded, so the audience or the creative may be less relevant.',
                weight: 90,
                action: 'Review the ad placements and the ad creative.',
                actionPriority: 1,
            );
        }

        return null;
    }

    public function reachOutpacingResponse(InsightContext $c, array $paid): ?Finding
    {
        $m = $paid['metrics'];

        if ($c->dir($m['impressions'] ?? null) === 'up' && $c->dir($m['clicks'] ?? null) === 'up' && $c->dir($m['ctr'] ?? null) === 'down') {
            return new Finding('Clicks grew more slowly than impressions, so a smaller share of the people who saw the ads clicked.', weight: 77);
        }

        return null;
    }

    public function reachAndResponseDown(InsightContext $c, array $paid): ?Finding
    {
        $m = $paid['metrics'];

        if ($c->dir($m['impressions'] ?? null) === 'down' && $c->dir($m['clicks'] ?? null) === 'down') {
            return new Finding(
                'Fewer people saw the ads and fewer clicked, so the advertising reach shrank.',
                weight: 78,
                action: 'Check campaign budgets, schedules and any paused campaigns.',
                actionPriority: 3,
            );
        }

        return null;
    }

    public function lessEfficient(InsightContext $c, array $paid): ?Finding
    {
        $m = $paid['metrics'];

        if ($c->dir($m['costPerClick'] ?? null) === 'up') {
            return new Finding('Advertising became less efficient: each click cost more.', weight: 76);
        }

        return null;
    }

    public function spendWithoutPurchases(InsightContext $c, array $paid): ?Finding
    {
        $m = $paid['metrics'];
        $purchases = $m['purchases']['current'] ?? null;

        if ($c->dir($m['cost'] ?? null) === 'up' && $purchases !== null && (float) $purchases <= 0) {
            return new Finding(
                'Spend rose without recorded bookings. Hotel booking decisions are slow, so also judge the advertising by booking-engine visits.',
                weight: 72,
                action: 'Judge the advertising by booking-engine visits as well as purchases.',
                actionPriority: 3,
            );
        }

        return null;
    }

    public function efficiency(InsightContext $c, array $paid): ?Finding
    {
        $m = $paid['metrics'];

        if ($c->dir($m['clicks'] ?? null) === 'up' && $c->dir($m['costPerClick'] ?? null) === 'down') {
            return new Finding('Advertising became more efficient: more clicks at a lower cost per click.', weight: 60);
        }

        return null;
    }

    public function awareness(InsightContext $c, array $paid): ?Finding
    {
        $terms = array_map('mb_strtolower', array_map('strval', (array) $c->config('awareness_terms', [])));
        $campaigns = array_slice((array) ($paid['campaigns'] ?? []), 0, 3);   // already the biggest by cost

        foreach ($campaigns as $campaign) {
            foreach ($terms as $term) {
                if ($term !== '' && mb_stripos((string) ($campaign['campaign'] ?? ''), $term) !== false) {
                    return new Finding(
                        'Display and awareness campaigns build brand awareness rather than direct demand, so judge them on reach, not on bookings.',
                        weight: 50,
                    );
                }
            }
        }

        return null;
    }
}
