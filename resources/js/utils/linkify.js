/**
 * Split plain message text into renderable parts so a chat bubble can show
 * clickable links without handing attacker-controlled SMS content to `v-html`.
 *
 * Inbound SMS bodies come straight from Twilio, so they must never be treated
 * as markup. Returning parts lets the template render text as text and links as
 * anchors.
 */

// Trailing punctuation is almost always sentence punctuation, not part of the
// URL — "see https://example.com." should not link the full stop.
const TRAILING_PUNCTUATION = /[.,;:!?)\]}'"]+$/;

const URL_PATTERN = /(https?:\/\/[^\s<]+|www\.[^\s<]+)/gi;

/**
 * @param {string|null|undefined} text
 * @returns {Array<{type: 'text'|'link', value: string, href?: string}>}
 */
export function linkifyParts(text) {
    const source = text == null ? "" : String(text);

    if (source === "") {
        return [];
    }

    const parts = [];
    let lastIndex = 0;

    // `matchAll` needs the global flag, which `URL_PATTERN` carries; build a
    // fresh regex per call so the shared `lastIndex` can never leak between
    // messages.
    const pattern = new RegExp(URL_PATTERN.source, "gi");
    let match;

    while ((match = pattern.exec(source)) !== null) {
        let value = match[0];
        const start = match.index;

        const trailing = value.match(TRAILING_PUNCTUATION);
        if (trailing) {
            value = value.slice(0, value.length - trailing[0].length);
        }

        if (value === "") {
            continue;
        }

        if (start > lastIndex) {
            parts.push({ type: "text", value: source.slice(lastIndex, start) });
        }

        parts.push({
            type: "link",
            value,
            href: value.toLowerCase().startsWith("www.")
                ? `https://${value}`
                : value,
        });

        lastIndex = start + value.length;
    }

    if (lastIndex < source.length) {
        parts.push({ type: "text", value: source.slice(lastIndex) });
    }

    return parts;
}
