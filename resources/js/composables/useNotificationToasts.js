import { h } from "vue";
import { router } from "@inertiajs/vue3";

import { useToast } from "@/Components/ui/toast/use-toast";
import ToastAction from "@/Components/ui/toast/ToastAction.vue";

/**
 * Toasts a notification the moment it is broadcast, rather than waiting for
 * the bell's five second poll.
 *
 * The server sends these on the recipient's own private channel — the same one
 * the TexasRenters Desktop client subscribes to — so a user signed in here and
 * running the desktop app sees both. Nothing is fetched: the payload the
 * desktop reads carries everything the toast needs.
 */

/** Anything at or above this shows in the alarming variant. */
const LOUD_PRIORITIES = ["high", "urgent"];

/**
 * Laravel names the broadcast after the notification's broadcastType(), and
 * Echo hands it over with a leading dot on custom names.
 */
const normaliseEventName = (name) => String(name ?? "").replace(/^\./, "");

export function useNotificationToasts(options = {}) {
    const { userId, skipEvents = [], onNotification } = options;

    const { toast } = useToast();

    let channel = null;

    // Reverb redelivers on reconnect, and the bell poll can race the socket —
    // both would toast the same notification twice without this.
    const seen = new Set();

    const shouldToast = (eventName, payload) => {
        if (!payload || typeof payload !== "object") return false;

        if (skipEvents.includes(normaliseEventName(eventName))) return false;

        // Laravel stamps every broadcast notification with its uuid.
        const id = payload.id ?? payload.activity_id ?? null;

        if (id === null) return true;

        if (seen.has(id)) return false;

        seen.add(id);

        return true;
    };

    const announce = (eventName, payload) => {
        if (!shouldToast(eventName, payload)) return;

        const url = payload.url;

        const options = {
            title: payload.title || "New notification",
            description: payload.message || undefined,
            variant: LOUD_PRIORITIES.includes(payload.priority)
                ? "destructive"
                : undefined,
        };

        // Only ever an absolute http(s) link, matching what the desktop client
        // accepts. Left off entirely when there is nowhere to go — Toaster
        // renders <component :is="toast.action" />, which warns on undefined.
        if (typeof url === "string" && /^https?:\/\//.test(url)) {
            options.action = h(
                ToastAction,
                { altText: "Open", onClick: () => router.visit(url) },
                () => "Open"
            );
        }

        toast(options);

        // Let the bell's badge catch up straight away rather than on its next
        // poll, so the count never lags the toast the user is looking at.
        onNotification?.(payload);
    };

    const start = () => {
        if (channel || !userId || !window.Echo) return;

        try {
            channel = window.Echo.private(`App.Models.User.${userId}`);

            // listenToAll, not notification(): these notifications override
            // broadcastType(), so each arrives under its own event name rather
            // than the BroadcastNotificationCreated name Echo's notification()
            // helper binds to.
            channel.listenToAll(announce);
        } catch {
            // Reverb unreachable. The bell keeps polling, so notifications are
            // late rather than lost, and a toast is not worth breaking a page.
            channel = null;
        }
    };

    const stop = () => {
        if (!channel) return;

        try {
            channel.stopListeningToAll();
            window.Echo.leave(`App.Models.User.${userId}`);
        } catch {
            // Already torn down.
        }

        channel = null;
        seen.clear();
    };

    return { start, stop };
}
