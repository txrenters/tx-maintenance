<script setup>
import { computed } from "vue";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-vue-next";

const props = defineProps({
    column: { type: String, required: true },
    sort: String,
    direction: String,
    // What the next click does, e.g. "A to Z" / "Z to A".
    ascLabel: { type: String, default: "ascending" },
    descLabel: { type: String, default: "descending" },
});

const emit = defineEmits(["sort"]);

const isActive = computed(() => props.sort === props.column);

const icon = computed(() => {
    if (!isActive.value) return ArrowUpDown;
    return props.direction === "asc" ? ArrowUp : ArrowDown;
});

const ariaSort = computed(() => {
    if (!isActive.value) return "none";
    return props.direction === "asc" ? "ascending" : "descending";
});

// Name what the click will do, so the control is not icon-only.
const title = computed(() => {
    if (!isActive.value) return `Sort by ${props.ascLabel}`;
    return props.direction === "asc"
        ? `Sort by ${props.descLabel}`
        : `Sort by ${props.ascLabel}`;
});
</script>
<template>
    <TableHead :aria-sort="ariaSort">
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            :title="title"
            @click="emit('sort', column)"
        >
            <slot />
            <component
                :is="icon"
                class="h-3.5 w-3.5"
                :class="isActive ? '' : 'opacity-50'"
            />
        </button>
    </TableHead>
</template>