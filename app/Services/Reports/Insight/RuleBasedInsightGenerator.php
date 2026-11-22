<?php

declare(strict_types=1);

namespace App\Services\Reports\Insight;

use App\Contracts\InsightGenerator;
use App\Support\InsightConfig;
use App\Support\Rich;

/**
 * The rule-based Traffic Quality Insight (no external API). It explains meaning, not just numbers: every paragraph says what
 * changed, what it means and, when useful, one thing to check.
 *
 * Output (see App\Contracts\InsightGenerator): a verdict headline, short titled paragraphs in a fixed order (Advertising, Website
 * traffic, Where visitors came from, Countries, AI assistants, Search Console), a "Priority:" line and one neutral caution.
 *
 * Each section is a small rules class returning Findings. A section with no data gives no paragraph. When the text is longer
 * than report.insight.max_words, the least important optional sentences are dropped (essential ones never are).
 */
class RuleBasedInsightGenerator implements InsightGenerator
{
    public function __construct(
        private readonly Verdict $verdict = new Verdict(),
        private readonly AdvertisingRules $advertising = new AdvertisingRules(),
        private readonly WebsiteTrafficRules $website = new WebsiteTrafficRules(),
        private readonly SourceRules $sources = new SourceRules(),
        private readonly CountryRules $countries = new CountryRules(),
        private readonly AiRules $ai = new AiRules(),
        private readonly SearchConsoleRules $searchConsole = new SearchConsoleRules(),
    ) {
    }

    public function generate(array $payload): array
    {
        $c = new InsightContext($payload);   // fresh state (guard notes) for every report

        $verdict = $this->verdict->evaluate($c);

        /** @var array<string, list<Finding>> $sections */
        $sections = array_filter([
            'Advertising' => $this->advertising->findings($c),
            'Website traffic' => $this->website->findings($c),
            'Where visitors came from' => $this->sources->findings($c),
            'Countries' => $this->countries->findings($c),
            'AI assistants' => $this->ai->findings($c),
            'Search Console' => $this->searchConsole->findings($c),
        ], fn (array $findings) => $findings !== []);

        $sections = $this->fit($sections, $verdict['headline'], $c);

        $paragraphs = [];
        foreach ($sections as $title => $findings) {
            $paragraphs[] = ['title' => $title, 'text' => implode(' ', array_map(fn (Finding $f) => $f->text, $findings))];
        }

        return [
            'verdict' => $verdict['key'],
            'headline' => $verdict['headline'],
            'paragraphs' => $paragraphs,
            'priority' => $this->priority($sections),
            'caution' => $this->caution($c),
        ];
    }

    /**
     * Drops the least important optional sentences until the insight fits the word limit.
     *
     * @param  array<string, list<Finding>>  $sections
     * @return array<string, list<Finding>>
     */
    private function fit(array $sections, string $headline, InsightContext $c): array
    {
        $limit = (int) InsightConfig::get('max_words.'.($c->monthly ? 'monthly' : 'weekly'), $c->slug, $c->monthly ? 400 : 350);
        if ($limit <= 0) {
            return $sections;
        }

        while ($this->words($sections, $headline) > $limit) {
            $victim = null;   // [section, index, weight]
            foreach ($sections as $title => $findings) {
                foreach ($findings as $i => $f) {
                    // Equal weights: the later sentence goes first.
                    if (! $f->essential && ($victim === null || $f->weight <= $victim[2])) {
                        $victim = [$title, $i, $f->weight];
                    }
                }
            }

            if ($victim === null) {
                break;
            }

            unset($sections[$victim[0]][$victim[1]]);
            $sections[$victim[0]] = array_values($sections[$victim[0]]);
        }

        return $sections;
    }

    /** @param  array<string, list<Finding>>  $sections */
    private function words(array $sections, string $headline): int
    {
        $total = Rich::words($headline) + Rich::words($this->priority($sections) ?? '');
        foreach ($sections as $title => $findings) {
            $total += Rich::words($title);
            foreach ($findings as $f) {
                $total += Rich::words($f->text);
            }
        }

        return $total;
    }

    /** "Priority: ..." from the actions of the sentences that were kept: the one or two most useful, most urgent first. */
    private function priority(array $sections): ?string
    {
        $actions = [];
        foreach ($sections as $findings) {
            foreach ($findings as $f) {
                if ($f->action !== null && ! isset($actions[$f->action])) {
                    $actions[$f->action] = [$f->actionPriority, -$f->weight, $f->action];
                }
            }
        }

        if ($actions === []) {
            return null;
        }

        $sorted = array_values($actions);
        usort($sorted, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return 'Priority: '.implode(' ', array_column(array_slice($sorted, 0, 2), 2));
    }

    /** One neutral paragraph: large-change tracking note, then the data-quality note from payload.quality_alerts. */
    private function caution(InsightContext $c): ?string
    {
        $parts = array_filter([$c->largeChangeSentence(), $this->qualitySentence($c->payload['quality_alerts'] ?? [])]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** @param  mixed  $alerts */
    private function qualitySentence(mixed $alerts): ?string
    {
        if (! is_array($alerts) || $alerts === []) {
            return null;
        }

        $clauses = [];
        foreach ($alerts as $alert) {
            $clauses[] = match ($alert['code'] ?? null) {
                'country_sum_mismatch' => 'some sessions are counted in more than one country, so the country figures add up to more than the site total',
                'direct_low_engagement' => 'a large share of Direct traffic has very short sessions, which can lower averages',
                'currency_mismatch' => 'Google Analytics reports money in '.($alert['details']['ga4Currency'] ?? 'another currency').', which differs from the hotel setting',
                default => rtrim(lcfirst((string) ($alert['message'] ?? 'a data-quality note was recorded')), '. '),
            };
        }

        return 'Data note: '.implode('; ', array_unique($clauses)).'. Please read these figures with care; they are shown as reported.';
    }
}
