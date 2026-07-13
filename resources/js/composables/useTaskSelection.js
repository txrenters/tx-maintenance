import { computed, reactive, unref } from "vue";

// Per-scope task selection, shared at module level so a scope's checked tasks
// survive its component being unmounted (the modal Tasks tab uses v-if and
// remounts on tab switches). Each scope has its own bucket, so selections never
// bleed between independent lists — e.g. the Past Due / Due Today / Upcoming
// TaskCards on the /tasks page, or different work orders in the modal.
const buckets = reactive({});

const scopeKey = (scope) => String(scope ?? "__default__");

// `scope` may be a plain value, a ref, or a getter — so the bucket re-keys
// reactively when the scope changes (e.g. the modal opening a different work
// order), which starts that scope with a fresh, empty selection.
export function useTaskSelection(scope) {
    const key = computed(() =>
        scopeKey(typeof scope === "function" ? scope() : unref(scope))
    );

    const selectedTaskIds = computed(() => buckets[key.value] ?? []);

    const isSelected = (taskId) => (buckets[key.value] ?? []).includes(taskId);

    const toggle = (taskId, checked) => {
        const list = buckets[key.value] ?? [];
        if (checked) {
            if (!list.includes(taskId)) {
                buckets[key.value] = [...list, taskId];
            }
        } else {
            buckets[key.value] = list.filter((id) => id !== taskId);
        }
    };

    const setMany = (ids) => {
        buckets[key.value] = ids;
    };

    const clear = () => {
        buckets[key.value] = [];
    };

    // Drop any selected ids that no longer exist in the given task list (e.g. a
    // task was deleted or regenerated), keeping the count accurate.
    const prune = (availableTaskIds) => {
        const available = new Set(availableTaskIds);
        buckets[key.value] = (buckets[key.value] ?? []).filter((id) =>
            available.has(id)
        );
    };

    return { selectedTaskIds, isSelected, toggle, setMany, clear, prune };
}
