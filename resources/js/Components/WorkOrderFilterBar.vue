<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import SearchBar from "@/Components/SearchBar.vue";
import FilterChip from "@/Components/FilterChip.vue";
import { Button } from "@/Components/ui/button";
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from "@/Components/ui/tooltip";
import { Users2, Tag, TriangleAlert, Palette, MapPin, X } from "lucide-vue-next";
import { cityFilterOptions } from "@/composables/useCityFilter";

/**
 * The shared work-order board filter toolbar: search + faceted Vendor /
 * Category / Priority / Color chips + clear-all. All filtering is client-side;
 * the bound values are passed straight through to WorkOrderCard on the board.
 * Page-specific controls (date range, export, import, refresh) go in the
 * #actions slot so each board keeps its own action cluster.
 */
const props = defineProps({
    vendors: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    // Some boards (e.g. closed) don't paint status colors — hide the Color chip.
    showColor: { type: Boolean, default: true },
    // Opt-in: the City chip only renders on boards that pass their city list.
    cities: { type: Array, default: null },
});

const search = defineModel("search", { default: "" });
const vendor = defineModel("vendor", { default: "" });
const category = defineModel("category", { default: "" });
const emergency = defineModel("emergency", { default: "all" });
const color = defineModel("color", { default: "all" });
const city = defineModel("city", { default: "all" });

const page = usePage();
const canFilter = computed(() => {
    const roles = page.props.auth?.user?.roles ?? [];
    return roles.includes("admin") || roles.includes("woc");
});

const vendorOptions = computed(() =>
    (props.vendors ?? []).map((v) => ({ value: String(v.id), label: v.name })),
);
const categoryOptions = computed(() =>
    (props.categories ?? []).map((c) => ({
        value: String(c.name),
        label: c.name,
    })),
);
const emergencyOptions = [
    { value: "all", label: "All priorities" },
    { value: "emergency", label: "Emergency" },
    { value: "non_emergency", label: "Non-emergency" },
];
const colorOptions = [
    { value: "all", label: "All colors" },
    { value: "red", label: "Overdue", dot: "bg-destructive" },
    { value: "blue", label: "Due today", dot: "bg-primary" },
    { value: "green", label: "Upcoming", dot: "bg-green-500" },
];
const showCity = computed(() => Array.isArray(props.cities));
const cityOptions = computed(() => cityFilterOptions(props.cities ?? [], city.value));

const hasActiveFilters = computed(
    () =>
        !!search.value ||
        !!vendor.value ||
        !!category.value ||
        (emergency.value && emergency.value !== "all") ||
        (props.showColor && color.value && color.value !== "all") ||
        (showCity.value && city.value && city.value !== "all"),
);

const clearAllFilters = () => {
    search.value = "";
    vendor.value = "";
    category.value = "";
    emergency.value = "all";
    color.value = "all";
    city.value = "all";
};
</script>

<template>
    <div class="flex gap-3 flex-col sm:flex-row items-center">
        <SearchBar v-model="search" />

        <div
            v-if="canFilter"
            class="flex flex-1 min-w-0 flex-wrap items-center gap-2"
        >
            <FilterChip
                label="Vendor"
                :icon="Users2"
                :model-value="vendor"
                :options="vendorOptions"
                searchable
                search-placeholder="Search vendors…"
                @update:modelValue="(value) => (vendor = value)"
            />
            <FilterChip
                label="Category"
                :icon="Tag"
                :model-value="category"
                :options="categoryOptions"
                searchable
                search-placeholder="Search categories…"
                @update:modelValue="(value) => (category = value)"
            />
            <FilterChip
                v-if="showCity"
                label="City"
                :icon="MapPin"
                :model-value="city"
                :options="cityOptions"
                searchable
                search-placeholder="Search cities…"
                @update:modelValue="(value) => (city = value)"
            />
            <FilterChip
                label="Priority"
                :icon="TriangleAlert"
                :model-value="emergency"
                :options="emergencyOptions"
                @update:modelValue="(value) => (emergency = value)"
            />
            <FilterChip
                v-if="showColor"
                label="Color"
                :icon="Palette"
                :model-value="color"
                :options="colorOptions"
                @update:modelValue="(value) => (color = value)"
            />
            <TooltipProvider v-if="hasActiveFilters">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-9 w-9 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            @click="clearAllFilters"
                        >
                            <X class="h-4 w-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Clear all filters</TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>

        <slot name="actions" />
    </div>
</template>
