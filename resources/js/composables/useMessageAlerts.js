import { computed, onUnmounted, ref } from "vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";

/**
 * Chime and desktop notification when a message arrives.
 *
 * The server hands back only messages newer than the last one we announced, so
 * a page load never replays a backlog and a reconnect never double-alerts.
 */

const STORAGE_KEY = "tx.messageAlerts.enabled";

/** Matches the notification poll already running in AppLayout. */
const POLL_MS = 5000;

const readStoredPreference = () => {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === "1";
    } catch {
        // Private mode or blocked storage — alerting simply stays off.
        return false;
    }
};

const storePreference = (enabled) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, enabled ? "1" : "0");
    } catch {
        // Not worth surfacing; the toggle still works for this session.
    }
};

/**
 * A short two-note chime, synthesised rather than shipped as an audio file.
 *
 * Browsers only allow an AudioContext to start after a user gesture, which the
 * toggle provides — alerting cannot be enabled without one.
 */
const createChime = () => {
    let context = null;

    const ensureContext = () => {
        const AudioContextClass =
            window.AudioContext || window.webkitAudioContext;

        if (!AudioContextClass) return null;

        context = context ?? new AudioContextClass();

        if (context.state === "suspended") {
            context.resume();
        }

        return context;
    };

    const note = (ctx, frequency, startOffset) => {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();

        oscillator.type = "sine";
        oscillator.frequency.value = frequency;

        const start = ctx.currentTime + startOffset;

        // Ramp in and out so the note does not click.
        gain.gain.setValueAtTime(0, start);
        gain.gain.linearRampToValueAtTime(0.12, start + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.28);

        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.start(start);
        oscillator.stop(start + 0.3);
    };

    return {
        unlock: ensureContext,
        play: () => {
            const ctx = ensureContext();
            if (!ctx) return;

            note(ctx, 880, 0);
            note(ctx, 1174.7, 0.13);
        },
    };
};

export function useMessageAlerts(options = {}) {
    const { alertsUrl, inboxUrl } = options;

    const enabled = ref(readStoredPreference());
    const permission = ref(
        typeof Notification === "undefined" ? "unsupported" : Notification.permission
    );

    const chime = createChime();

    // Null until the first poll establishes the high water mark, which is what
    // stops a fresh session announcing everything already in the table.
    let cursor = null;
    let timer = null;
    let inFlight = false;

    const supported = computed(() => permission.value !== "unsupported");

    const poll = async () => {
        if (inFlight) return;
        inFlight = true;

        try {
            const { data } = await axios.get(alertsUrl, {
                params: cursor === null ? {} : { after_id: cursor },
            });

            const previousCursor = cursor;
            cursor = data.latest_id ?? cursor;

            // The very first response only sets the mark.
            if (previousCursor === null || !enabled.value) return;

            announce(data.alerts ?? [], data.new_count ?? 0);
        } catch {
            // A failed poll is not worth a toast; the next tick retries.
        } finally {
            inFlight = false;
        }
    };

    const announce = (alerts, newCount) => {
        if (!alerts.length) return;

        chime.play();

        if (permission.value !== "granted") return;

        // A burst gets one summary rather than a stack of popups.
        if (newCount > alerts.length) {
            show(
                `${newCount} new messages`,
                "Open the Inbox to see who is waiting.",
                null
            );

            return;
        }

        alerts.forEach((alert) => {
            show(
                `${alert.party} · WO#${alert.work_order_no ?? "—"}`,
                alert.preview || "New message",
                alert
            );
        });
    };

    const show = (title, body, alert) => {
        try {
            const notification = new Notification(title, {
                body,
                // One notification per thread: a chatty tenant replaces their
                // own popup instead of stacking several.
                tag: alert
                    ? `wo-${alert.work_order_id}-${alert.conversation_type}`
                    : "wo-messages",
                icon: "/tx-portal-logo.png",
            });

            notification.onclick = () => {
                window.focus();
                router.visit(inboxUrl);
                notification.close();
            };
        } catch {
            // Some browsers refuse construction outside a service worker; the
            // chime still played, which is the important half.
        }
    };

    const enable = async () => {
        chime.unlock();

        if (typeof Notification !== "undefined" && permission.value === "default") {
            permission.value = await Notification.requestPermission();
        }

        enabled.value = true;
        storePreference(true);
        chime.play();
    };

    const disable = () => {
        enabled.value = false;
        storePreference(false);
    };

    const toggle = () => (enabled.value ? disable() : enable());

    const start = () => {
        poll();
        timer = setInterval(poll, POLL_MS);
    };

    const stop = () => {
        if (timer) clearInterval(timer);
        timer = null;
    };

    onUnmounted(stop);

    return { enabled, permission, supported, toggle, enable, disable, start, stop };
}
