<?php

namespace App\Services;

/**
 * Recognizes Apple iMessage "tapback" reactions that carriers deliver as
 * literal SMS text: `Liked "…the entire quoted message…"`, `Reacted 😂 to
 * "…"`, `Removed a like from "…"`, and so on. The quoted text is the full
 * original message, so these read as giant duplicate bubbles unless callers
 * swap them for a compact reaction chip.
 *
 * Matching is deliberately strict — anchored full-match, capitalized verb,
 * quoted tail — so a human sentence that happens to start with "Liked" never
 * gets swallowed. A miss simply renders and counts as a normal message.
 *
 * Mirrored by resources/js/utils/tapback.js — change both.
 */
final class TapbackDetector
{
    /**
     * Named tapback verbs. Disliked and Questioned still deserve a human
     * reply; the rest close the thread.
     *
     * @var array<string, array{emoji: string, needs_reply: bool}>
     */
    private const VERBS = [
        'Liked' => ['emoji' => '👍', 'needs_reply' => false],
        'Loved' => ['emoji' => '❤️', 'needs_reply' => false],
        'Disliked' => ['emoji' => '👎', 'needs_reply' => true],
        'Laughed at' => ['emoji' => '😂', 'needs_reply' => false],
        'Emphasized' => ['emoji' => '‼️', 'needs_reply' => false],
        'Questioned' => ['emoji' => '❓', 'needs_reply' => true],
    ];

    /**
     * "Removed <noun> from "…"" forms, mapped to the reaction they undo.
     *
     * @var array<string, string>
     */
    private const REMOVAL_NOUNS = [
        'a like' => '👍',
        'a heart' => '❤️',
        'a dislike' => '👎',
        'a laugh' => '😂',
        'an exclamation' => '‼️',
        'a question mark' => '❓',
    ];

    /**
     * Custom emoji reactions (iOS 18 "Reacted <emoji> to") that read as
     * negative and should keep the thread flagged for a human.
     *
     * @var list<string>
     */
    private const NEGATIVE_EMOJI = ['👎', '❓', '❔', '⁉️', '😡', '😠', '🤬', '💔', '😢', '😭'];

    /**
     * @return array{emoji: string, label: string, quoted: string, removal: bool, needs_reply: bool}|null
     */
    public static function detect(?string $body): ?array
    {
        if ($body === null) {
            return null;
        }

        // Collapsing whitespace lets one anchored pattern match multiline
        // quoted bodies without dotall loosening the anchors.
        $text = trim((string) preg_replace('/\s+/u', ' ', $body));

        if ($text === '' || preg_match(self::pattern(), $text, $matches) !== 1) {
            return null;
        }

        $quoted = $matches[5];

        if ($matches[1] !== '') {
            $verb = self::VERBS[$matches[1]];

            return [
                'emoji' => $verb['emoji'],
                'label' => $matches[1].' a message',
                'quoted' => $quoted,
                'removal' => false,
                'needs_reply' => $verb['needs_reply'],
            ];
        }

        if ($matches[2] !== '') {
            return [
                'emoji' => self::REMOVAL_NOUNS[$matches[2]],
                'label' => 'Removed a reaction',
                'quoted' => $quoted,
                'removal' => true,
                'needs_reply' => false,
            ];
        }

        // Custom-emoji branches: anything with letters or digits is a human
        // sentence ("Reacted quickly to …"), not a reaction token.
        $token = $matches[3] !== '' ? $matches[3] : $matches[4];

        if (preg_match('/[\p{L}\p{N}]/u', $token) === 1) {
            return null;
        }

        if ($matches[3] !== '') {
            return [
                'emoji' => $token,
                'label' => 'Reacted '.$token.' to a message',
                'quoted' => $quoted,
                'removal' => false,
                'needs_reply' => in_array($token, self::NEGATIVE_EMOJI, true),
            ];
        }

        return [
            'emoji' => $token,
            'label' => 'Removed a reaction',
            'quoted' => $quoted,
            'removal' => true,
            'needs_reply' => false,
        ];
    }

    private static function pattern(): string
    {
        $verbs = implode('|', array_map(
            static fn (string $verb): string => preg_quote($verb, '/'),
            array_keys(self::VERBS)
        ));
        $nouns = implode('|', array_keys(self::REMOVAL_NOUNS));

        // Greedy (.+) runs to the LAST closing quote, so quoted text may
        // itself contain quotes or our own "(Ref: WO#123)" footer. Verbs stay
        // case-sensitive: Apple always capitalizes, and the strictness is
        // free false-positive protection.
        return '/^(?:('.$verbs.')|Removed ('.$nouns.') from|Reacted (\S{1,16}) to|Removed (\S{1,16}) from) [“"](.+)[”"]$/u';
    }
}
