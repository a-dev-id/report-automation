<?php

declare(strict_types=1);

namespace App\Services\Reports\Insight;

/**
 * "AI assistants" paragraph, from payload.ai_traffic. Written only when AI sessions reach config report.insight.ai.min_sessions.
 *
 *   overview   the top assistant and its share, and assistants that are new this period
 *   meaning    what it means for the hotel (kept even when the text has to be shortened)
 *   countries  the top countries sending AI traffic with their share and change ("new" when absent before), and those that dropped out
 */
final class AiRules
{
    /** @return list<Finding> */
    public function findings(InsightContext $c): array
    {
        $ai = $c->section('ai_traffic');
        $sessions = (float) ($ai['total']['current'] ?? 0);
        if ($ai === null || $sessions < (float) $c->config('ai.min_sessions', 20) || ($ai['sources'] ?? []) === []) {
            return [];
        }

        return array_values(array_filter([
            $this->overview($c, $ai)?->withRule('overview'),
            $this->meaning($c, $ai)?->withRule('meaning'),
            $this->countries($c, $ai)?->withRule('countries'),
        ]));
    }

    public function overview(InsightContext $c, array $ai): ?Finding
    {
        $top = $ai['sources'][0];
        $text = 'AI assistants referred '.$c->value('n', $ai['total']['current']).' sessions ('
            .($c->dir($ai['total'], 'AI traffic') === 'guarded' ? 'see the note below on the size of the change' : $c->change($ai['total']['change'])).')';
        $share = $top['share']['current'] ?? null;
        $text .= $share !== null && $share >= 99.95
            ? ', all from '.$top['source'].'.'
            : ', led by '.$top['source'].($share !== null ? ' with '.$c->value('pct', $share).' of them' : '').'.';

        $new = array_values(array_filter($ai['sources'], fn (array $s) => ($s['sessions']['change']['direction'] ?? '') === 'new'));
        if ($new !== []) {
            $text .= ' New this period: '.$c->join(array_slice(array_column($new, 'source'), 0, 3)).'.';
        }

        return new Finding($text, weight: 80, essential: true);
    }

    public function meaning(InsightContext $c, array $ai): ?Finding
    {
        return new Finding('This suggests guests are researching with AI assistants, so keep key information on the site clear and easy to quote.', weight: 74, essential: true);
    }

    public function countries(InsightContext $c, array $ai): ?Finding
    {
        $rows = array_slice((array) ($ai['topCountries'] ?? []), 0, 3);
        if ($rows === []) {
            return null;
        }

        $parts = [];
        foreach ($rows as $i => $r) {
            $change = ($r['isNew'] ?? false)
                ? $c->change(['direction' => 'new'])
                : ($c->guarded($r['change'] ?? null) ? null : $c->change($r['change'] ?? null));

            $parts[] = $r['country'].' ('.$c->value('pct', $r['share'] ?? null).($i === 0 ? ' of AI sessions' : '').($change !== null ? ', '.$change : '').')';
        }

        $text = 'Most AI visitors came from '.$c->join($parts).'.';
        $dropped = array_slice((array) ($ai['droppedCountries'] ?? []), 0, 3);
        if ($dropped !== []) {
            $text .= ' Dropped out of the AI top list: '.$c->join($dropped).'.';
        }

        return new Finding($text, weight: 60);
    }
}
