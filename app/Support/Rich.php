<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tiny markup for text that carries bold and colored numbers, so the insight can be written once and rendered for HTML
 * (inline spans, no CSS classes, safe for Outlook) and for plain text (no color, explicit signs).
 *
 * A token is "⟦kind|text⟧" where kind is b (bold value), pos, neg, zero, new, neutral or none (a change, see
 * ReportFormat::change). Everything outside tokens is plain text and is HTML-escaped by toHtml().
 */
final class Rich
{
    private const PATTERN = '/⟦(b|pos|neg|zero|new|neutral|none)\|([^⟧]*)⟧/u';

    private const COLORS = [
        'b' => '#111827',
        'pos' => ReportFormat::GREEN,
        'neg' => ReportFormat::RED,
        'zero' => ReportFormat::GRAY,
        'neutral' => ReportFormat::GRAY,
        'none' => ReportFormat::GRAY,
        'new' => ReportFormat::NEW,
    ];

    /** A value shown in bold, for example the number a change belongs to. */
    public static function value(string $text): string
    {
        return "⟦b|{$text}⟧";
    }

    /**
     * A change as a bold colored token with its arrow and sign.
     *
     * @param  array{text: string, arrow: string, kind: string}  $change  from ReportFormat::change()
     * @param  bool  $arrow  false inside sentences ("fell -9.8%"): the sign carries the direction, arrows stay in tiles and tables
     */
    public static function change(array $change, bool $arrow = true): string
    {
        $text = trim(($arrow && $change['arrow'] !== '' ? $change['arrow'].' ' : '').$change['text']);

        return "⟦{$change['kind']}|{$text}⟧";
    }

    /** HTML for the text: tokens become inline-styled spans, everything else is escaped. */
    public static function toHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace_callback(
            self::PATTERN,
            fn (array $m) => '<span style="font-weight:bold; color:'.self::COLORS[$m[1]].';">'.$m[2].'</span>',
            $escaped,
        );
    }

    /** The text without markup or color. Values and changes keep their explicit signs. */
    public static function toPlain(string $text): string
    {
        return (string) preg_replace(self::PATTERN, '$2', $text);
    }

    /** Number of words a reader sees (markup removed). */
    public static function words(string $text): int
    {
        $plain = trim(self::toPlain($text));

        return $plain === '' ? 0 : count(preg_split('/\s+/u', $plain) ?: []);
    }
}
