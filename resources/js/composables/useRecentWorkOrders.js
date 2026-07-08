import { ref } from "vue";

const STORAGE_KEY = "recent_work_orders";
const MAX_RECENT = 10;

const loadStored = () => {
    try {
        const stored = JSON.parse(localStorage.getItem(STORAGE_KEY));

        return Array.isArray(stored) ? stored : [];
    } catch {
        return [];
    }
};

// Shared singleton so every page sees the same list.
const recentWorkOrders = ref(loadStored());

/**
 * Tracks the work orders the user opened most recently (per browser, via
 * localStorage). Lets staff jump straight back to a work order after it moved
 * to another column — e.g. when changing its service status sends the card to
 * the New column and scrolling around to find it again is a pain.
 */
export function useRecentWorkOrders() {
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

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(recentWorkOrders.value));
        } catch {
            // Storage full or unavailable — the in-memory list still works.
        }
    };

    return { recentWorkOrders, rememberWorkOrder };
}
