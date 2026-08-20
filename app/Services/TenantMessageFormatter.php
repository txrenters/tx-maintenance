<?php

namespace App\Services;

/**
 * One shape for every automated tenant text, so the vendor-assignment,
 * appointment, follow-up and portal messages read as the same voice rather than
 * four different ones. The tenant counterpart of OwnerMessageFormatter:
 *
 *     {body, its own paragraphs}
 *
 *     {portal link, when there is one}
 *
 *     - TX Maintenance Team
 *     (Ref: WO#43361)
 */
class TenantMessageFormatter
{
    public const SIGN_OFF = '- TX Maintenance Team';

    /**
     * Phrased around what the tenant actually wants from the portal — the state
     * of their own request — rather than around uploading, which only some of
     * the messages are asking for.
     */
    public const LINK_LEAD = 'You can check on your request, send us a message, or add photos here anytime - no login needed: ';

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
