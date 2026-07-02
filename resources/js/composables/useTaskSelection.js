import { reactive } from "vue";

// Shared, module-level task selection so checked tasks survive the Tasks tab
// being unmounted (tabs use v-if). Scoped to a single work order: opening a
// different work order resets the selection.
const state = reactive({
    workOrderId: null,
    selectedTaskIds: [],
});

export function useTaskSelection() {
    // Bind the selection to a work order; switching work orders clears it.
    const ensureWorkOrder = (workOrderId) => {
        if (state.workOrderId !== workOrderId) {
            state.workOrderId = workOrderId;
            state.selectedTaskIds = [];
        }
    };

    const isSelected = (taskId) => state.selectedTaskIds.includes(taskId);

    const toggle = (taskId, checked) => {
        if (checked) {
            if (!state.selectedTaskIds.includes(taskId)) {
                state.selectedTaskIds.push(taskId);
            }
        } else {
            state.selectedTaskIds = state.selectedTaskIds.filter(
                (id) => id !== taskId
            );
        }
    };

    const setMany = (ids) => {
        state.selectedTaskIds = ids;
    };

    const clear = () => {
        state.selectedTaskIds = [];
    };

    // Drop any selected ids that no longer exist in the given task list (e.g. a
    // task was deleted or regenerated), keeping the count accurate.
    const prune = (availableTaskIds) => {
        const available = new Set(availableTaskIds);
        state.selectedTaskIds = state.selectedTaskIds.filter((id) =>
            available.has(id)
        );
    };

    return { state, ensureWorkOrder, isSelected, toggle, setMany, clear, prune };
}
