import { reactive } from "vue";

// Singleton state shared across the whole app so any component (the notification
// panel, a work order card, etc.) can open the same work order modal by id.
const state = reactive({
    isOpen: false,
    workOrderId: null,
});

export function useWorkOrderModal() {
    const open = (workOrderId) => {
        state.workOrderId = workOrderId;
        state.isOpen = true;
    };

    const close = () => {
        state.isOpen = false;
    };

    return { state, open, close };
}
