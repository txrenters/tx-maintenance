/**
 * Human-readable explanations for the Twilio error codes we see most often
 * on SMS sends. The map intentionally favors plain language a property
 * manager or vendor can act on over Twilio's internal phrasing.
 *
 * Reference: https://www.twilio.com/docs/api/errors
 *
 * Ported from tx-chatbot/resources/js/utils/twilioErrorCatalog.ts.
 */
const TWILIO_ERROR_MESSAGES = {
    21211: 'The phone number on file looks invalid. Double-check the digits with the contact.',
    21408: 'SMS to this country is not enabled on the Twilio account. Contact an admin to enable it.',
    21606: 'Our Twilio sender number is not valid or not SMS-capable. An admin needs to check the number configuration.',
    21610: 'The recipient texted STOP and is unsubscribed. They need to text START before we can SMS them again.',
    21611: 'The Twilio queue is full right now. Try again in a few minutes.',
    21612: 'This phone number cannot be reached from our Twilio number. The carrier blocks our number, or the destination is invalid.',
    21614: 'This number cannot receive SMS — it is likely a landline or VoIP without messaging support.',
    21617: 'The message body is too long (over 1600 characters). Shorten it and try again.',
    30001: 'Twilio queue overflow — too many messages in flight. Try again shortly.',
    30002: 'The Twilio account is suspended. An admin needs to resolve the account status.',
    30003: 'The destination handset is unreachable — phone is off, out of coverage, or has lost service.',
    30004: 'The carrier blocked this message (spam/content filter). Try rephrasing or contact carrier support.',
    30005: 'Unknown destination handset — the number does not appear to be a real, active mobile line.',
    30006: 'This number cannot receive SMS — it is a landline or the carrier is unreachable. Ask the contact for a mobile number.',
    30007: 'The carrier flagged the content as spam and blocked it. Rephrase or split the message and try again.',
    30008: 'Twilio reported an unknown delivery error. Try resending; if it keeps failing, escalate.',
    30009: 'A message segment was lost in transit. Resend the message.',
    30010: 'The message price exceeded the configured maximum. An admin needs to raise the price ceiling.',
    30011: 'The recipient cannot receive MMS. Send the text content without the attachment.',
    30032: 'Our Toll-Free number is not yet verified for carrier delivery. An admin must complete Toll-Free verification.',
    30034: 'Our 10DLC number is not registered for A2P with US carriers. An admin must register the brand/campaign.',
    30035: 'Our 10DLC campaign hit its daily message cap. Wait until tomorrow or upgrade the tier.',
    30036: 'The message expired before the carrier could deliver it. Try resending.',
    30037: 'This Twilio number hit its daily message cap. Try a different number or wait until tomorrow.',
    30038: 'Toll-Free verification was rejected. An admin must resubmit verification in the Twilio console.',
    30410: 'The provider timed out delivering this message. Try resending.',
    30450: 'Twilio trial account restriction — only verified numbers can receive messages.',
    30500: 'The carrier returned an unknown error. Try resending; if it persists, escalate.',
    30600: 'Twilio rejected this message before sending. Check that the account and number are in good standing.',
};

/**
 * Returns a plain-English explanation for a Twilio SMS error code, or null
 * when the code is unknown.
 */
export const describeTwilioError = (code) => {
    if (code === null || code === undefined) {
        return null;
    }

    const normalized = String(code).trim();
    if (normalized === '') {
        return null;
    }

    return TWILIO_ERROR_MESSAGES[normalized] ?? null;
};

/**
 * Returns the best human-readable description of a delivery failure:
 *  1. The friendly catalog entry for the code (if known)
 *  2. The raw Twilio error message (if Twilio gave one)
 *  3. null
 */
export const friendlyTwilioError = (code, rawMessage) => {
    return describeTwilioError(code) ?? (rawMessage || null);
};

/**
 * Twilio error codes where retrying the same message will not help — either
 * the recipient cannot receive it, the content is the issue, or the account
 * needs admin work. Hide the Retry button for these.
 */
const NON_RETRYABLE_CODES = new Set([
    '21211', // invalid phone number
    '21408', // SMS not enabled for country
    '21606', // From number invalid / not SMS-capable
    '21610', // recipient texted STOP
    '21614', // destination not a mobile line
    '21617', // body too long
    '30002', // account suspended
    '30005', // unknown destination handset
    '30006', // landline / unreachable carrier
    '30011', // recipient cannot receive MMS
    '30032', // toll-free unverified
    '30034', // 10DLC unregistered
    '30038', // toll-free verification rejected
    '30450', // trial account restriction
]);

/**
 * Did the recipient opt out (texted STOP)? Twilio reports this as 21610; the
 * Chat Support hub reports it only in the message text.
 */
export const isUnsubscribedTwilioError = (code, rawMessage) =>
    String(code ?? '').trim() === '21610' ||
    String(rawMessage ?? '').toLowerCase().includes('unsubscribed');

/**
 * Should we offer a Retry button for a message that failed with this code?
 * Unknown codes default to retryable so the user always has an option.
 */
export const isRetryableTwilioError = (code) => {
    if (code === null || code === undefined) {
        return true;
    }

    const normalized = String(code).trim();
    if (normalized === '') {
        return true;
    }

    return ! NON_RETRYABLE_CODES.has(normalized);
};
