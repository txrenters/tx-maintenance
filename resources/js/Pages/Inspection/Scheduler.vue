<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { Head, Link } from "@inertiajs/vue3";
import axios from "axios";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import { Input } from "@/Components/ui/input";
import { Badge } from "@/Components/ui/badge";
import { Loader2, MapPin, X } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    cities: Array,
    properties: Array,
});

// PropertyWare zone -> color. Zone is what ends up as "Zone N" in Jobber job
// titles; the region labels are derived from where each zone's cities sit.
const ZONES = [
    { zone: "1", label: "Zone 1 · North", color: "#3b82f6" },
    { zone: "2", label: "Zone 2 · West", color: "#22c55e" },
    { zone: "3", label: "Zone 3 · Southwest", color: "#f59e0b" },
    { zone: "4", label: "Zone 4 · East / Southeast", color: "#ef4444" },
    { zone: "5", label: "Zone 5 · Nacogdoches", color: "#a855f7" },
];
const NO_ZONE_COLOR = "#9ca3af";

const zoneColor = (zone) =>
    ZONES.find((z) => z.zone === zone)?.color ?? NO_ZONE_COLOR;

const zoneChips = computed(() => [
    ...ZONES.map((z) => ({
        ...z,
        count: (props.properties ?? []).filter((p) => p.zone === z.zone)
            .length,
    })),
    {
        zone: "none",
        label: "No zone",
        color: NO_ZONE_COLOR,
        count: (props.properties ?? []).filter((p) => !p.zone).length,
    },
]);

const totalProperties = computed(() =>
    (props.cities ?? []).reduce((sum, c) => sum + c.properties, 0)
);

const mappedCount = computed(() => (props.properties ?? []).length);

const search = ref("");
const activeZones = ref(new Set(["1", "2", "3", "4", "5", "none"]));

const toggleZone = (zone) => {
    const next = new Set(activeZones.value);
    if (next.has(zone)) {
        next.delete(zone);
    } else {
        next.add(zone);
    }
    activeZones.value = next;
};

// Selected property panel state.
const selected = ref(null);
const selectedLoading = ref(false);
const selectedId = ref(null);

const mapElement = ref(null);
let map = null;
let pinRecords = [];
let circleRecords = [];
let radiusCircle = null;

const FIVE_MILES_IN_METERS = 8046.72;

// Yellow 5-mile radius around the selected property — the radius the
// future TBP-filler rule is defined with.
const showRadius = (property) => {
    radiusCircle?.remove();
    radiusCircle = L.circle([property.lat, property.lng], {
        radius: FIVE_MILES_IN_METERS,
        color: "#eab308",
        weight: 2,
        dashArray: "6 6",
        fillColor: "#eab308",
        fillOpacity: 0.08,
    })
        .bindTooltip(`5-mile radius around ${property.name}`)
        .addTo(map);
};

const matchesFilters = (meta) => {
    const zoneKey = meta.zone ?? "none";
    if (!activeZones.value.has(zoneKey)) {
        return false;
    }
    const needle = search.value.trim().toLowerCase();
    if (needle === "") {
        return true;
    }
    return meta.searchText.includes(needle);
};

const refreshVisibility = () => {
    if (!map) {
        return;
    }
    [...pinRecords, ...circleRecords].forEach((record) => {
        if (matchesFilters(record.meta)) {
            if (!map.hasLayer(record.marker)) {
                record.marker.addTo(map);
            }
        } else if (map.hasLayer(record.marker)) {
            record.marker.remove();
        }
    });
};

watch([search, activeZones], refreshVisibility);

const highlight = (id) => {
    pinRecords.forEach((record) => {
        record.marker.setStyle(
            record.meta.id === id
                ? { weight: 3, radius: 8, fillOpacity: 0.9 }
                : { weight: 1, radius: 5, fillOpacity: 0.7 }
        );
    });
};

const selectProperty = async (property) => {
    selectedId.value = property.id;
    highlight(property.id);
    showRadius(property);
    selectedLoading.value = true;
    selected.value = null;
    try {
        const { data } = await axios.get(
            route("scheduler.property", property.id)
        );
        selected.value = data;
    } catch (error) {
        selected.value = { error: true, name: property.name };
    } finally {
        selectedLoading.value = false;
    }
};

const clearSelection = () => {
    selected.value = null;
    selectedId.value = null;
    highlight(null);
    radiusCircle?.remove();
    radiusCircle = null;
};

onMounted(() => {
    const pins = (props.properties ?? []).filter((p) => p.lat && p.lng);
    // City circles only stand in for properties not geocoded yet; they
    // disappear on their own as the geocode backfill completes.
    const circles = (props.cities ?? []).filter(
        (c) => c.lat && c.lng && c.ungeocoded > 0
    );
    if (!mapElement.value || (pins.length === 0 && circles.length === 0)) {
        return;
    }

    map = L.map(mapElement.value, {
        minZoom: 7,
        maxZoom: 17,
        preferCanvas: true,
    });

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution:
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);

    const bounds = L.latLngBounds(
        [...pins, ...circles].map((point) => [point.lat, point.lng])
    );
    map.fitBounds(bounds.pad(0.08));
    // Keep the view on the coverage area only.
    map.setMaxBounds(bounds.pad(0.6));

    circleRecords = circles.map((city) => {
        const color = zoneColor(city.zone);
        const marker = L.circleMarker([city.lat, city.lng], {
            radius: 5 + Math.sqrt(city.ungeocoded) * 1.7,
            color,
            weight: 1.5,
            fillColor: color,
            fillOpacity: 0.25,
        }).bindTooltip(
            `${city.name} — ${city.ungeocoded} ${
                city.ungeocoded === 1 ? "property" : "properties"
            } not yet geocoded${city.zone ? ` — Zone ${city.zone}` : ""}`
        );
        marker.addTo(map);

        return {
            marker,
            meta: {
                id: null,
                zone: city.zone,
                searchText: city.name.toLowerCase(),
            },
        };
    });

    pinRecords = pins.map((property) => {
        const color = zoneColor(property.zone);
        const marker = L.circleMarker([property.lat, property.lng], {
            radius: 5,
            color,
            weight: 1,
            fillColor: color,
            fillOpacity: 0.7,
        }).bindTooltip(
            `${property.name} — ${property.address}${
                property.zone ? ` — Zone ${property.zone}` : ""
            }`
        );
        marker.on("click", () => selectProperty(property));
        marker.addTo(map);

        return {
            marker,
            meta: {
                id: property.id,
                zone: property.zone,
                searchText:
                    `${property.name} ${property.address}`.toLowerCase(),
            },
        };
    });
});

onBeforeUnmount(() => {
    map?.remove();
    map = null;
    pinRecords = [];
    circleRecords = [];
    radiusCircle = null;
});
</script>

<template>
    <Head :title="title" />

    <div class="bg-background border rounded-lg p-4">
        <h2 class="text-lg font-semibold">Scheduler</h2>
        <p class="text-sm text-muted-foreground">
            Coverage area: Greater Houston, TX + Nacogdoches, TX —
            {{ mappedCount }} of {{ totalProperties }} active properties mapped
            to exact addresses. Click a pin for the property's work orders.
        </p>
    </div>

    <div class="bg-background border rounded-lg p-4 space-y-3">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
            <Input
                v-model="search"
                placeholder="Search property, address or city..."
                class="sm:max-w-xs"
            />
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="chip in zoneChips"
                    :key="chip.zone"
                    type="button"
                    class="flex items-center gap-2 rounded-full border px-3 py-1 text-sm transition-opacity"
                    :class="
                        activeZones.has(chip.zone)
                            ? ''
                            : 'opacity-40 line-through'
                    "
                    @click="toggleZone(chip.zone)"
                >
                    <span
                        class="inline-block h-3 w-3 rounded-full"
                        :style="{ backgroundColor: chip.color }"
                    />
                    <span>{{ chip.label }}</span>
                    <span class="text-muted-foreground">{{ chip.count }}</span>
                </button>
            </div>
        </div>
        <p class="text-xs text-muted-foreground">
            Zones come from the PropertyWare "Zone" field on work orders (the
            same "Zone N" that appears in Jobber job titles). Each pin is
            colored by the property's most common zone. Click a chip to hide or
            show a zone.
        </p>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div
            class="bg-background border rounded-lg overflow-hidden lg:col-span-2"
        >
            <div ref="mapElement" class="h-[32rem] w-full z-0" />
        </div>

        <div class="bg-background border rounded-lg flex flex-col h-[32rem]">
            <div
                v-if="!selectedId"
                class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-muted-foreground"
            >
                <MapPin class="h-8 w-8" />
                <p class="text-sm text-center">
                    Click a property pin to see its details and work orders.
                </p>
            </div>

            <div
                v-else-if="selectedLoading"
                class="flex flex-1 items-center justify-center"
            >
                <Loader2 class="h-6 w-6 animate-spin text-muted-foreground" />
            </div>

            <template v-else-if="selected">
                <div class="flex items-start justify-between gap-2 border-b p-4">
                    <div>
                        <h3 class="font-semibold leading-tight">
                            {{ selected.name }}
                        </h3>
                        <p
                            v-if="!selected.error"
                            class="text-sm text-muted-foreground"
                        >
                            {{ selected.address }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground"
                        @click="clearSelection"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <p v-if="selected.error" class="p-4 text-sm text-destructive">
                    Could not load this property. Try again.
                </p>

                <template v-else>
                    <div class="flex flex-wrap items-center gap-2 border-b p-4">
                        <Badge
                            v-if="selected.zone"
                            variant="outline"
                            class="gap-1.5"
                        >
                            <span
                                class="inline-block h-2.5 w-2.5 rounded-full"
                                :style="{
                                    backgroundColor: zoneColor(selected.zone),
                                }"
                            />
                            Zone {{ selected.zone }}
                        </Badge>
                        <Badge variant="outline">
                            {{ selected.open_work_orders }} open /
                            {{ selected.total_work_orders }} total WOs
                        </Badge>
                        <Badge v-if="!selected.active" variant="destructive">
                            Inactive
                        </Badge>
                    </div>

                    <div class="flex-1 overflow-y-auto p-2">
                        <p
                            v-if="selected.work_orders.length === 0"
                            class="p-3 text-sm text-muted-foreground"
                        >
                            No work orders for this property yet.
                        </p>
                        <Link
                            v-for="workOrder in selected.work_orders"
                            :key="workOrder.id"
                            :href="`/work_orders?search=${workOrder.work_order_no}`"
                            class="block rounded-md p-3 hover:bg-accent"
                        >
                            <div
                                class="flex items-center justify-between gap-2 text-sm"
                            >
                                <span class="font-medium">
                                    #{{ workOrder.work_order_no }}
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    {{ workOrder.created_date }}
                                </span>
                            </div>
                            <p
                                class="mt-0.5 text-xs text-muted-foreground line-clamp-2"
                            >
                                {{ workOrder.description }}
                            </p>
                            <div
                                class="mt-1 flex flex-wrap items-center gap-1.5"
                            >
                                <Badge
                                    v-if="workOrder.status"
                                    variant="secondary"
                                    class="text-[10px]"
                                >
                                    {{ workOrder.status }}
                                </Badge>
                                <Badge
                                    v-if="workOrder.category"
                                    variant="outline"
                                    class="text-[10px]"
                                >
                                    {{ workOrder.category }}
                                </Badge>
                            </div>
                        </Link>
                    </div>
                </template>
            </template>
        </div>
    </div>
</template>
