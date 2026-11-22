<?php

declare(strict_types=1);

namespace App\Services\Reports\Insight;

use App\Support\ReportFormat;
use App\Support\Rich;
use DateTimeImmutable;
use Throwable;

/**
 * "Search Console" paragraph, from payload.search_console (only when it has data).
 *
 *   overview            impressions, clicks, CTR and average position (a lower position number is better)
 *   shownNotClicked     impressions up and clicks down: shown more often, clicked less
 *   visibilityMoved     impressions and clicks both up (more visible) or both down (less visible)
 *   brandShare          share of clicks from queries with a brand term (config report.insight.brand_terms; skipped when empty)
 *   dataThrough         the last day with data, when the last days are not available yet
 */
final class SearchConsoleRules
{
    /** @return list<Finding> */
    public function findings(InsightContext $c): array
    {
        $sc = $this->section($c);
        if ($sc === null) {
            return [];
        }

        $out = array_filter([$this->overview($c, $sc)]);
        foreach (['shownNotClicked', 'visibilityMoved', 'brandShare', 'dataThrough'] as $rule) {
            $finding = $this->{$rule}($c, $sc);
            if ($finding !== null) {
                $out[] = $finding->withRule($rule);
            }
        }

        return array_values($out);
    }

    /** @return array<string, mixed>|null */
    public function section(InsightContext $c): ?array
    {
        $sc = $c->section('search_console');

        return $sc !== null && ($sc['hasData'] ?? false) && is_array($sc['totals'] ?? null) ? $sc : null;
    }

    public function overview(InsightContext $c, array $sc): ?Finding
    {
        $t = $sc['totals'];
        $impressions = $c->phrase($t['impressions'] ?? null, 'n', 'Search impressions');
        $clicks = $c->phrase($t['clicks'] ?? null, 'n', 'Search clicks');
        if ($impressions === null || $clicks === null) {
            return null;
        }

        $ctr = $c->phrase($t['ctr'] ?? null, 'rate', 'Search click-through rate');
        $text = "In Google Search, impressions {$impressions}, clicks {$clicks}".($ctr !== null ? " and the click-through rate {$ctr}" : '').'.';

        if (($position = $this->positionPhrase($c, $t['position'] ?? null)) !== null) {
            $text .= " {$position}";
        }

        return new Finding($text, weight: 100, essential: true, rule: 'overview');
    }

    public function shownNotClicked(InsightContext $c, array $sc): ?Finding
    {
        $t = $sc['totals'];
        if ($c->dir($t['impressions'] ?? null) !== 'up' || $c->dir($t['clicks'] ?? null) !== 'down') {
            return null;
        }

        $slipped = ($t['position']['change']['direction'] ?? '') === 'up';   // a higher number is a worse position

        return new Finding(
            'The site appeared more often but fewer people clicked'
            .($slipped ? ' and the average position slipped' : '')
            .': the page title or description may not be convincing'.($slipped ? '' : ', or the position may have slipped').'.',
            weight: 85,
            action: 'Review the titles and descriptions of the pages that get the most impressions.',
            actionPriority: 2,
        );
    }

    public function visibilityMoved(InsightContext $c, array $sc): ?Finding
    {
        $t = $sc['totals'];
        $impressions = $c->dir($t['impressions'] ?? null);
        $clicks = $c->dir($t['clicks'] ?? null);

        if ($impressions === 'up' && $clicks === 'up') {
            return new Finding('The site was found more often and more people clicked, so its visibility in search improved.', weight: 86);
        }
        if ($impressions === 'down' && $clicks === 'down') {
            return new Finding(
                'The site appeared less often and fewer people clicked, so its visibility in search weakened.',
                weight: 86,
                action: 'Check whether key pages lost rankings or were changed recently.',
                actionPriority: 3,
            );
        }

        return null;
    }

    public function brandShare(InsightContext $c, array $sc): ?Finding
    {
        $brand = $sc['brand'] ?? null;
        $share = $brand['share']['current'] ?? null;
        if (! is_array($brand) || $share === null || (float) ($sc['totals']['clicks']['current'] ?? 0) <= 0) {
            return null;
        }

        $before = $brand['share']['previous'] ?? null;
        $text = $c->value('pct', $share).' of clicks came from searches that contain a brand term ('.implode(', ', array_slice((array) $brand['terms'], 0, 3)).')';
        if ($before !== null && abs($share - $before) >= 0.1) {
            $text .= ', '.($share > $before ? 'up' : 'down').' from '.$c->value('pct', $before);
        }
        $text .= '. '.($share >= 50 ? 'Most' : 'Many').' clicks come from people who already know the hotel; growth from non-brand searches is where new guests come from.';

        return new Finding($text, weight: 82);
    }

    public function dataThrough(InsightContext $c, array $sc): ?Finding
    {
        if (! ($sc['truncated'] ?? false) || empty($sc['through'])) {
            return null;
        }

        try {
            $date = (new DateTimeImmutable($sc['through']))->format('j M Y');
        } catch (Throwable) {
            return null;
        }

        return new Finding("Search Console data runs through {$date}.", weight: 65);
    }

    // ----------------------------------------------------------------------------------------------

    /** "The average position improved from 10.8 to 10.3 (a lower number is better)." */
    private function positionPhrase(InsightContext $c, ?array $position): ?string
    {
        if ($position === null || ($position['current'] ?? null) === null) {
            return null;
        }

        $now = $c->value('pos', $position['current']);
        $before = $position['previous'] ?? null;
        $change = $position['change'] ?? [];

        if ($before === null || $c->guarded($change) || ! in_array($change['direction'] ?? 'none', ['up', 'down'], true)) {
            return "The average position was {$now}.";
        }

        // Position is "lower is better": Compare::change marks a drop in the number as improved, so ReportFormat colors it green.
        $word = ($change['improved'] ?? null) === true ? 'improved' : 'slipped';
        $token = Rich::change(ReportFormat::change($change), arrow: false);

        return "The average position {$word} from ".$c->value('pos', $before)." to {$now} ({$token}); a lower number is better.";
    }
}
