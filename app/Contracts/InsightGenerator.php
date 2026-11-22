<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Writes the "Traffic Quality Insight" for one report payload (docs/payload.md).
 *
 * The rule-based implementation is App\Services\Reports\Insight\RuleBasedInsightGenerator. It sits behind this interface so
 * a version written by an AI model can replace it later by changing one binding in ReportServiceProvider. Any implementation
 * must return the shape below and must only use numbers that are in the payload.
 *
 * Text fields use the markup of App\Support\Rich (bold values, colored changes). Rich::toHtml() and Rich::toPlain() render it.
 */
interface InsightGenerator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     verdict: string|null,
     *     headline: string,
     *     paragraphs: list<array{title: string, text: string}>,
     *     priority: string|null,
     *     caution: string|null
     * }
     *   verdict    : healthy growth | growth with weaker quality | a decline | stable | mixed, or null when it cannot be judged
     *   headline   : one sentence verdict with the key numbers
     *   paragraphs : short titled paragraphs, in report order; a paragraph with no data is left out
     *   priority   : "Priority: ..." with the one or two most useful actions, or null
     *   caution    : one neutral note (tracking checks, data quality), or null
     */
    public function generate(array $payload): array;
}
