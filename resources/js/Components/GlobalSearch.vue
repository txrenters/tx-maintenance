<script setup>
import { ref, watch, onMounted, onUnmounted, computed } from "vue";
import { useFilter } from "reka-ui";
import axios from "axios";
import { useWorkOrderModal } from "@/composables/useWorkOrderModal";
import { Search, Loader2, AlertCircle } from "lucide-vue-next";
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
} from "@/Components/ui/dialog";
import { Badge } from "@/Components/ui/badge";
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from "@/Components/ui/combobox";

const isOpen = ref(false);
const query = ref("");
const selectedBuilding = ref(null);
const results = ref([]);
const buildings = ref([]);
const isLoading = ref(false);
const buildingSearchTerm = ref("");

const { contains } = useFilter({ sensitivity: "base" });
const { open: openWorkOrderModal } = useWorkOrderModal();

const filteredBuildings = computed(() => {
    if (!buildingSearchTerm.value) {
        return buildings.value;
    }
    return buildings.value.filter((building) =>
        contains(building.name, buildingSearchTerm.value),
    );
});

let debounceTimer = null;

const open = () => {
    isOpen.value = true;
};

const close = () => {
    isOpen.value = false;
};

defineExpose({ open });

const fetchBuildings = async () => {
    try {
        const response = await axios.get("/search/buildings");
        buildings.value = response.data.buildings;
    } catch {
        // silently fail
    }
};

const performSearch = async () => {
    if (query.value.length < 2 && !selectedBuilding.value) {
        results.value = [];
        return;
    }

    isLoading.value = true;
    try {
        const params = {};
        if (query.value.length >= 2) {
            params.query = query.value;
        }
        if (selectedBuilding.value) {
            params.building_id = selectedBuilding.value;
        }
        const response = await axios.get("/search", { params });
        results.value = response.data.results;
    } catch {
        results.value = [];
    } finally {
        isLoading.value = false;
    }
};

watch([query, selectedBuilding], () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(performSearch, 300);
});

watch(isOpen, (value) => {
    if (!value) {
        query.value = "";
        selectedBuilding.value = null;
        results.value = [];
        buildingSearchTerm.value = "";
    }
});

const selectWorkOrder = (id) => {
    // Show the work order in the shared modal instead of navigating to a page.
    // Open the modal first, then close the search dialog so we never leave the
    // page without an active dialog (avoids a stuck scroll lock between the two).
    openWorkOrderModal(id);
    close();
};

const getPriorityVariant = (priority) => {
    if (!priority) {
        return "secondary";
    }
    const lower = priority.toLowerCase();
    if (lower === "high" || lower === "urgent") {
        return "destructive";
    }
    if (lower === "medium") {
        return "outline";
    }
    return "secondary";
};

const handleKeydown = (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key === "k") {
        e.preventDefault();
        open();
    }
};

const getBuildingLabel = (value) => {
    if (!value) {
        return "All Properties";
    }
    return (
        buildings.value.find((building) => building.propertyware_id === value)
            ?.name || "All Properties"
    );
};

onMounted(() => {
    fetchBuildings();
    document.addEventListener("keydown", handleKeydown);
});

onUnmounted(() => {
    document.removeEventListener("keydown", handleKeydown);
    clearTimeout(debounceTimer);
});
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent
            class="flex flex-col gap-0 p-0 max-w-2xl w-full max-h-[80vh] overflow-hidden border bg-background/95 shadow-xl backdrop-blur"
        >
            <DialogTitle class="sr-only">Search Work Orders</DialogTitle>
            <DialogDescription class="sr-only"
                >Search across all work orders by description, category,
                location, or work order number</DialogDescription
            >

            <!-- Search input -->
            <div class="flex items-center gap-3 px-4 py-3 border-b bg-muted/30">
                <Search class="w-4 h-4 text-muted-foreground shrink-0" />
                <input
                    v-model="query"
                    type="text"
                    placeholder="Search work orders, description, category or location..."
                    autofocus
                    class="flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground text-foreground"
                />
            </div>

            <!-- Building filter -->
            <div class="px-4 py-3 border-b bg-background">
                <Combobox v-model="selectedBuilding" :ignore-filter="true">
                    <ComboboxAnchor class="w-full">
                        <ComboboxInput
                            v-model="buildingSearchTerm"
                            :display-value="getBuildingLabel"
                            placeholder="Search properties..."
                            class="w-full rounded-md border border-border/60 bg-muted/30 px-3 py-2 text-sm text-foreground outline-none transition focus:border-primary/60 focus:ring-2 focus:ring-primary/10 dark:bg-neutral-950/60"
                        />
                    </ComboboxAnchor>
                    <ComboboxList class="w-[--reka-popper-anchor-width]">
                        <ComboboxEmpty>No properties found.</ComboboxEmpty>
                        <ComboboxGroup>
                            <ComboboxItem
                                :value="null"
                                text-value="All Properties"
                                @select="buildingSearchTerm = ''"
                            >
                                All Properties
                            </ComboboxItem>
                            <ComboboxItem
                                v-for="building in filteredBuildings"
                                :key="building.propertyware_id"
                                :value="building.propertyware_id"
                                :text-value="building.name"
                                @select="buildingSearchTerm = ''"
                            >
                                {{ building.name }}
                            </ComboboxItem>
                        </ComboboxGroup>
                    </ComboboxList>
                </Combobox>
            </div>

            <!-- Results area -->
            <div class="overflow-y-auto flex-1 min-h-0">
                <!-- Loading -->
                <div
                    v-if="isLoading"
                    class="flex items-center justify-center py-12"
                >
                    <Loader2
                        class="w-6 h-6 animate-spin text-muted-foreground"
                    />
                </div>

                <!-- Empty state -->
                <div
                    v-else-if="query.length < 2 && !selectedBuilding"
                    class="flex flex-col items-center justify-center py-12 gap-2 text-muted-foreground"
                >
                    <Search class="w-8 h-8" />
                    <p class="text-sm">Start typing to search work orders</p>
                </div>

                <!-- No results -->
                <div
                    v-else-if="results.length === 0"
                    class="flex flex-col items-center justify-center py-12 gap-2 text-muted-foreground"
                >
                    <AlertCircle class="w-8 h-8" />
                    <p class="text-sm">No work orders found</p>
                </div>

                <!-- Results list -->
                <ul v-else class="divide-y divide-border/60 p-2">
                    <li
                        v-for="result in results"
                        :key="result.id"
                        class="group relative flex flex-col gap-1.5 px-4 py-3 cursor-pointer transition-colors hover:bg-accent/60 hover:text-accent-foreground"
                        @click="selectWorkOrder(result.id)"
                    >
                        <span
                            class="pointer-events-none absolute left-0 top-0 h-full w-1 bg-primary opacity-0 transition-opacity group-hover:opacity-100"
                        ></span>
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <span
                                    class="text-xs font-mono text-muted-foreground shrink-0"
                                >
                                    #{{ result.work_order_no }}
                                </span>
                                <span
                                    v-if="result.building_name"
                                    class="text-sm font-medium truncate"
                                >
                                    {{ result.building_name }}
                                </span>
                            </div>
                            <Badge
                                v-if="result.is_emergency"
                                variant="destructive"
                                class="shrink-0 text-xs"
                            >
                                Emergency
                            </Badge>
                        </div>

                        <p
                            v-if="result.description"
                            class="text-sm text-muted-foreground line-clamp-1"
                        >
                            {{ result.description }}
                        </p>

                        <div class="flex flex-wrap items-center gap-1.5 mt-0.5">
                            <Badge
                                v-if="result.service_status"
                                variant="outline"
                                class="text-xs"
                            >
                                {{ result.service_status }}
                            </Badge>
                            <Badge
                                v-else-if="result.status"
                                variant="outline"
                                class="text-xs"
                            >
                                {{ result.status }}
                            </Badge>
                            <Badge
                                v-if="result.category"
                                variant="secondary"
                                class="text-xs"
                            >
                                {{ result.category }}
                            </Badge>
                            <Badge
                                v-if="result.priority"
                                :variant="getPriorityVariant(result.priority)"
                                class="text-xs"
                            >
                                {{ result.priority }}
                            </Badge>
                            <span
                                v-if="result.location"
                                class="text-xs text-muted-foreground"
                            >
                                {{ result.location }}
                            </span>
                        </div>
                    </li>
                </ul>
            </div>
        </DialogContent>
    </Dialog>
</template>
