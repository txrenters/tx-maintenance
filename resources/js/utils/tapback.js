/**
 * Recognizes Apple iMessage "tapback" reactions that carriers deliver as
 * literal SMS text: `Liked "…the entire quoted message…"`, `Reacted 😂 to
 * "…"`, `Removed a like from "…"`. The quoted text is the full original
 * message, so render sites swap a detected tapback for a compact reaction
 * chip instead of the giant duplicate bubble.
 *
 * Matching is deliberately strict — anchored full-match, capitalized verb,
 * quoted tail — so a human sentence that happens to start with "Liked" never
 * gets swallowed. A miss simply renders as a normal message.
 *
 * Mirrored by app/Services/TapbackDetector.php — change both.
 */

// Disliked and Questioned still deserve a human reply; the rest close the
// thread.
const VERBS = {
    Liked: { emoji: "👍", needsReply: false },
    Loved: { emoji: "❤️", needsReply: false },
    Disliked: { emoji: "👎", needsReply: true },
    "Laughed at": { emoji: "😂", needsReply: false },
    Emphasized: { emoji: "‼️", needsReply: false },
    Questioned: { emoji: "❓", needsReply: true },
};

// "Removed <noun> from "…"" forms, mapped to the reaction they undo.
const REMOVAL_NOUNS = {
    "a like": "👍",
    "a heart": "❤️",
    "a dislike": "👎",
    "a laugh": "😂",
    "an exclamation": "‼️",
    "a question mark": "❓",
};

// Custom emoji reactions (iOS 18 "Reacted <emoji> to") that read as negative
// and should keep the thread flagged for a human.
const NEGATIVE_EMOJI = ["👎", "❓", "❔", "⁉️", "😡", "😠", "🤬", "💔", "😢", "😭"];

// Greedy (.+) runs to the LAST closing quote, so quoted text may itself
// contain quotes or our own "(Ref: WO#123)" footer. Verbs stay
// case-sensitive: Apple always capitalizes, and the strictness is free
// false-positive protection.
const PATTERN = new RegExp(
    "^(?:(" +
        Object.keys(VERBS).join("|") +
        ")|Removed (" +
        Object.keys(REMOVAL_NOUNS).join("|") +
        ") from|Reacted (\\S{1,16}) to|Removed (\\S{1,16}) from) [“\"](.+)[”\"]$",
    "u",
);

/**
 * @param {*} body
 * @returns {{emoji: string, label: string, quoted: string, removal: boolean, needsReply: boolean} | null}
 */
export function detectTapback(body) {
    if (typeof body !== "string") return null;

    // Collapsing whitespace lets one anchored pattern match multiline quoted
    // bodies without dotall loosening the anchors.
    const text = body.replace(/\s+/g, " ").trim();
    if (!text) return null;

    const match = PATTERN.exec(text);
    if (!match) return null;

    const quoted = match[5];

    if (match[1]) {
        const verb = VERBS[match[1]];
        return {
            emoji: verb.emoji,
            label: `${match[1]} a message`,
            quoted,
            removal: false,
            needsReply: verb.needsReply,
        };
    }

    if (match[2]) {
        return {
            emoji: REMOVAL_NOUNS[match[2]],
            label: "Removed a reaction",
            quoted,
            removal: true,
            needsReply: false,
        };
    }

    // Custom-emoji branches: anything with letters or digits is a human
    // sentence ("Reacted quickly to …"), not a reaction token.
    const token = match[3] || match[4];
    if (/[\p{L}\p{N}]/u.test(token)) return null;

    if (match[3]) {
        return {
            emoji: token,
            label: `Reacted ${token} to a message`,
            quoted,
            removal: false,
            needsReply: NEGATIVE_EMOJI.includes(token),
        };
    }

    return {
        emoji: token,
        label: "Removed a reaction",
        quoted,
        removal: true,
        needsReply: false,
    };
}

/** Uniform truncation of the quoted original across every render site. */
export function quotedExcerpt(tapback, max = 60) {
    const quoted = tapback?.quoted ?? "";
    if (quoted.length <= max) return quoted;
    return `${quoted.slice(0, max).trimEnd()}…`;
}
