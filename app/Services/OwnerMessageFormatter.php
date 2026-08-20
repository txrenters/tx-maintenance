<?php

namespace App\Services;

/**
 * One shape for every automated owner text, so the intake, vendor-assignment,
 * appointment and follow-up messages read as the same voice rather than four
 * different ones:
 *
 *     {body, its own paragraphs}
 *
 *     {portal link, when there is one}
 *
 *     - TX Maintenance Team
 *     (Ref: WO#43361)
 */
class OwnerMessageFormatter
{
    public const SIGN_OFF = '- TX Maintenance Team';

    /**
     * Deliberately makes no promise about photos: most work orders have none
     * when the owner is first messaged.
     */
    public const LINK_LEAD = 'Feel free to view your request or send us a message here anytime - no login needed: ';

    /**
     * Assemble a message from its body, an optional portal link, and the
     * standard sign-off + reference footer.
     */
    public static function compose(string $body, int|string|null $reference, ?string $link = null): string
    {
        $parts = [trim($body)];

        if (filled($link)) {
            $parts[] = self::LINK_LEAD.$link;
        }

        $parts[] = $reference !== null
            ? self::SIGN_OFF."\n(Ref: WO#{$reference})"
            : self::SIGN_OFF;

        return implode("\n\n", $parts);
    }

    /**
     * Join the given lines into body paragraphs, dropping any that are empty.
     *
     * @param  array<int, string|null>  $lines
     */
    public static function paragraphs(array $lines): string
    {
        return implode("\n\n", array_filter(array_map('trim', array_filter($lines, 'is_string'))));
    }
}
