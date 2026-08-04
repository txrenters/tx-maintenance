import { DateTime } from "luxon";

/**
 * Shared helpers for the work order conversation tabs: naming the people in a
 * thread, and spotting a thread the coordinator still owes a reply on.
 */

/** Reduce a phone number to its final 10 digits, mirroring the backend match. */
export function phoneKey(value) {
    const digits = String(value ?? "").replace(/\D+/g, "");
    return digits.length >= 10 ? digits.slice(-10) : "";
}

/**
 * Build the phone -> identity map MessageCard uses to label bubbles.
 *
 * @param {Array<{phone?: string, name?: string, role?: string, avatar?: string}>} people
 * @returns {Object<string, {name: string, role: string, avatar: string}>}
 */
export function buildParticipants(people) {
    const map = {};

    for (const person of people || []) {
        const key = phoneKey(person?.phone);
        const name = String(person?.name ?? "").trim();

        // Later entries win only when they actually carry a name, so a richer
        // record never gets overwritten by a bare number.
        if (!key || !name || (map[key] && !person.overwrite)) {
            continue;
        }

        map[key] = {
            name,
            role: person.role || "",
            avatar: person.avatar || "",
        };
    }

    return map;
}

/**
 * True when a message came from the other party rather than from us.
 *
 * `is_read` is the de facto direction column: every outbound writer sets it
 * true on insert and inbound writers leave it false (see BoardSummaryService).
 * That is a convention rather than a constraint, so the sender number is
 * checked too — both signals must agree before we call a message inbound.
 */
export function isInboundMessage(message, ourNumber) {
    if (!message) return false;

    const ours = phoneKey(ourNumber);
    const theirs = phoneKey(message.sender_number);

    if (!ours || !theirs) return false;

    return message.is_read === false && ours !== theirs;
}

/**
 * True when the newest message in a thread came from the other party, i.e. the
 * coordinator has not replied yet.
 */
export function isAwaitingReply(messages, ourNumber) {
    return isInboundMessage(lastMessage(messages), ourNumber);
}

export function lastMessage(messages) {
    if (!Array.isArray(messages) || messages.length === 0) return null;
    return messages[messages.length - 1];
}

/** Whole hours since a message arrived, floored, never negative. */
export function hoursSince(date) {
    if (!date) return 0;

    const parsed =
        typeof date === "string"
            ? DateTime.fromISO(date, { zone: "utc" })
            : DateTime.fromJSDate(date);

    if (!parsed.isValid) return 0;

    return Math.max(0, Math.floor(-parsed.diffNow("hours").hours));
}

/** Shared phrasing so the board summary and the thread banner read alike. */
export function waitedLabel(hours) {
    if (hours == null) return "";
    if (hours < 1) return "under an hour";
    if (hours < 24) return `${hours}h`;

    const days = Math.floor(hours / 24);
    const rest = hours % 24;

    return rest ? `${days}d ${rest}h` : `${days}d`;
}
