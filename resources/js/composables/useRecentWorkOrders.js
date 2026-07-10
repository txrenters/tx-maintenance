import { ref } from "vue";
import { usePage } from "@inertiajs/vue3";

const BASE_STORAGE_KEY = "recent_work_orders";
const MAX_RECENT = 10;

// Shared singleton so every page sees the same list.
const recentWorkOrders = ref([]);
let loadedForKey = null;

const loadStored = (storageKey) => {
    try {
        const stored = JSON.parse(localStorage.getItem(storageKey));

        return Array.isArray(stored) ? stored : [];
    } catch {
        return [];
    }
};

const persist = (storageKey) => {
    try {
        localStorage.setItem(storageKey, JSON.stringify(recentWorkOrders.value));
    } catch {
        // Storage full or unavailable — the in-memory list still works.
    }
};

/**
 * Tracks the work orders the user opened most recently. Lets staff jump
 * straight back to a work order after it moved to another column — e.g. when
 * changing its service status sends the card to the New column and scrolling
 * around to find it again is a pain.
 *
 * The list is keyed by user id so accounts sharing a browser (a shared WOC
 * station, or a vendor logging in after staff) never see each other's list,
 * and it is hard-capped at MAX_RECENT entries so it can never grow unbounded.
 */
export function useRecentWorkOrders() {
    const userId = usePage().props.auth?.user?.id ?? "guest";
    const storageKey = `${BASE_STORAGE_KEY}:${userId}`;

    if (loadedForKey !== storageKey) {
        recentWorkOrders.value = loadStored(storageKey);
        loadedForKey = storageKey;
    }

    const rememberWorkOrder = (workOrder) => {
        if (!workOrder?.id) return;

        recentWorkOrders.value = [
            {
                id: workOrder.id,
                work_order_no: workOrder.work_order_no,
                location: workOrder.location ?? "",
                category: workOrder.category ?? "",
                opened_at: Date.now(),
            },
            ...recentWorkOrders.value.filter((recent) => recent.id !== workOrder.id),
        ].slice(0, MAX_RECENT);

        persist(storageKey);
    };

    /** Drop an entry, e.g. after finding out the work order was deleted. */
    const forgetWorkOrder = (workOrderId) => {
        recentWorkOrders.value = recentWorkOrders.value.filter(
            (recent) => recent.id !== workOrderId,
        );

        persist(storageKey);
    };

    const openedAgo = (recent) => {
        const minutes = Math.round((Date.now() - (recent.opened_at ?? 0)) / 60000);

        if (minutes < 1) return "just now";
        if (minutes < 60) return `${minutes}m ago`;
        if (minutes < 60 * 24) return `${Math.round(minutes / 60)}h ago`;

        return `${Math.round(minutes / (60 * 24))}d ago`;
    };

    return { recentWorkOrders, rememberWorkOrder, forgetWorkOrder, openedAgo };
}
