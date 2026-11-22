<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Support\Compare;
use App\Support\ReportFormat;
use App\Support\Rich;
use Tests\TestCase;

/** Number styling: the change formatter and the Rich markup used in the insight text. */
class StylingTest extends TestCase
{
    private function change(float|null $current, float|null $previous, ?string $metric = null, bool $lowerIsBetter = false): array
    {
        return ReportFormat::change(Compare::change($current, $previous, $lowerIsBetter), $metric);
    }

    public function test_a_positive_change_is_green_with_an_up_arrow_and_an_explicit_plus(): void
    {
        $c = $this->change(125, 100);

        $this->assertSame('+25.0%', $c['text']);
        $this->assertSame('▲', $c['arrow']);
        $this->assertSame('#15803D', $c['color']);
        $this->assertSame('pos', $c['kind']);
        $this->assertSame('up', $c['direction']);
    }

    public function test_a_negative_change_is_red_with_a_down_arrow_and_an_explicit_minus(): void
    {
        $c = $this->change(90.2, 100);

        $this->assertSame('-9.8%', $c['text']);
        $this->assertSame('▼', $c['arrow']);
        $this->assertSame('#B91C1C', $c['color']);
        $this->assertSame('neg', $c['kind']);
    }

    public function test_zero_is_gray_with_a_flat_marker(): void
    {
        foreach ([$this->change(100, 100), $this->change(1000, 1000.4)] as $c) {
            $this->assertSame('0.0%', $c['text']);
            $this->assertSame('▬', $c['arrow']);
            $this->assertSame('#6B7280', $c['color']);
            $this->assertSame('zero', $c['kind']);
        }
    }

    public function test_null_or_no_comparison_is_gray_and_says_n_a(): void
    {
        foreach ([ReportFormat::change(null), $this->change(null, 50), $this->change(0, 0), $this->change(0, null)] as $c) {
            $this->assertSame('n/a', $c['text']);
            $this->assertSame('', $c['arrow']);
            $this->assertSame('#6B7280', $c['color']);
            $this->assertSame('none', $c['kind']);
        }
    }

    public function test_new_is_blue_gray_and_says_new(): void
    {
        $c = $this->change(5, 0);

        $this->assertSame('New', $c['text']);
        $this->assertSame('#475569', $c['color']);
        $this->assertSame('new', $c['kind']);
        $this->assertSame('New', $this->change(5, null)['text']);
    }

    public function test_neutral_metrics_are_gray_in_both_directions_but_keep_arrow_and_sign(): void
    {
        $up = $this->change(120, 100, 'cost');
        $down = $this->change(80, 100, 'costPerClick');

        $this->assertSame(['+20.0%', '▲', '#6B7280', 'neutral'], [$up['text'], $up['arrow'], $up['color'], $up['kind']]);
        $this->assertSame(['-20.0%', '▼', '#6B7280', 'neutral'], [$down['text'], $down['arrow'], $down['color'], $down['kind']]);

        // The same numbers on a normal metric are colored.
        $this->assertSame('#15803D', $this->change(120, 100, 'clicks')['color']);
        $this->assertSame('#B91C1C', $this->change(80, 100, 'clicks')['color']);
    }

    public function test_the_neutral_list_is_configurable(): void
    {
        $this->assertSame('#6B7280', $this->change(120, 100, 'cost')['color']);

        config(['report.styling.neutral_metrics' => ['clicks']]);

        $this->assertSame('#15803D', $this->change(120, 100, 'cost')['color']);     // no longer neutral
        $this->assertSame('#6B7280', $this->change(120, 100, 'clicks')['color']);   // now neutral
        $this->assertTrue(ReportFormat::isNeutral('clicks'));
        $this->assertFalse(ReportFormat::isNeutral('cost'));
        $this->assertFalse(ReportFormat::isNeutral(null));

        config(['report.styling.neutral_metrics' => []]);
        $this->assertSame('#15803D', $this->change(120, 100, 'cost')['color']);
    }

    public function test_a_metric_where_lower_is_better_is_inverted_so_a_lower_position_is_green(): void
    {
        $better = $this->change(9.0, 10.0, 'position', lowerIsBetter: true);
        $worse = $this->change(12.0, 10.0, 'position', lowerIsBetter: true);

        $this->assertSame(['-10.0%', '▼', '#15803D'], [$better['text'], $better['arrow'], $better['color']]);   // the number fell: green
        $this->assertSame(['+20.0%', '▲', '#B91C1C'], [$worse['text'], $worse['arrow'], $worse['color']]);      // the number rose: red
    }

    public function test_a_rate_change_is_shown_in_points_with_the_same_colors(): void
    {
        $up = ReportFormat::rateChange(Compare::change(0.62, 0.60));
        $down = ReportFormat::rateChange(Compare::change(0.541, 0.60));
        $flat = ReportFormat::rateChange(Compare::change(0.6001, 0.60));

        $this->assertSame(['+2.0 pts', '▲', '#15803D'], [$up['text'], $up['arrow'], $up['color']]);
        $this->assertSame(['-5.9 pts', '▼', '#B91C1C'], [$down['text'], $down['arrow'], $down['color']]);
        $this->assertSame(['0.0%', '#6B7280'], [$flat['text'], $flat['color']]);
        $this->assertSame('New', ReportFormat::rateChange(Compare::change(0.5, 0.0))['text']);
        $this->assertSame('#6B7280', ReportFormat::rateChange(Compare::change(0.7, 0.6), 'cost')['color']);
    }

    public function test_the_color_constants_are_the_ones_the_owner_asked_for(): void
    {
        $this->assertSame('#15803D', ReportFormat::GREEN);
        $this->assertSame('#B91C1C', ReportFormat::RED);
        $this->assertSame('#6B7280', ReportFormat::GRAY);
    }

    // ---- Rich -------------------------------------------------------------------------------------

    public function test_values_and_changes_become_bold_colored_inline_spans(): void
    {
        $text = 'Sessions rose '.Rich::change(ReportFormat::change(Compare::change(125, 100))).' to '.Rich::value('10,000').'.';

        $html = Rich::toHtml($text);

        $this->assertSame(
            'Sessions rose <span style="font-weight:bold; color:#15803D;">▲ +25.0%</span> to <span style="font-weight:bold; color:#111827;">10,000</span>.',
            $html,
        );
        $this->assertStringNotContainsString('class=', $html);   // inline styles only, Outlook-safe
    }

    public function test_every_kind_has_its_color(): void
    {
        $color = fn (string $kind) => preg_match('/color:(#[0-9A-F]{6})/', Rich::toHtml("⟦{$kind}|x⟧"), $m) ? $m[1] : null;

        $this->assertSame('#15803D', $color('pos'));
        $this->assertSame('#B91C1C', $color('neg'));
        $this->assertSame('#6B7280', $color('zero'));
        $this->assertSame('#6B7280', $color('neutral'));
        $this->assertSame('#475569', $color('new'));
        $this->assertSame('#6B7280', $color('none'));
        $this->assertSame('#111827', $color('b'));
    }

    public function test_text_and_token_contents_are_escaped(): void
    {
        $html = Rich::toHtml('A <script>alert(1)</script> & '.Rich::value('<b>5</b>'));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;5&lt;/b&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    public function test_the_plain_version_has_no_markup_no_color_and_keeps_signs_and_arrows(): void
    {
        $text = 'Sessions fell '.Rich::change(ReportFormat::change(Compare::change(90, 100))).' to '.Rich::value('90').', cost '.Rich::change(ReportFormat::change(Compare::change(120, 100), 'cost')).'.';

        $plain = Rich::toPlain($text);

        $this->assertSame('Sessions fell ▼ -10.0% to 90, cost ▲ +20.0%.', $plain);
        $this->assertStringNotContainsString('⟦', $plain);
        $this->assertStringNotContainsString('#', $plain);
    }

    public function test_the_word_count_ignores_markup_and_counts_numbers_as_one_word(): void
    {
        $this->assertSame(0, Rich::words(''));
        $this->assertSame(6, Rich::words('Sessions rose '.Rich::change(ReportFormat::change(Compare::change(125, 100))).' to '.Rich::value('10,000')));   // "▲ +25.0%" is two words
        $this->assertSame(3, Rich::words('  one   two three '));
    }

    public function test_text_without_tokens_is_unchanged_except_for_escaping(): void
    {
        $this->assertSame('Plain text.', Rich::toHtml('Plain text.'));
        $this->assertSame('Plain text.', Rich::toPlain('Plain text.'));
        $this->assertSame('a ⟦unknown|x⟧ b', Rich::toPlain('a ⟦unknown|x⟧ b'));   // only known kinds are markup
    }
}
