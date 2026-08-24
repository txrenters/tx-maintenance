<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { Head, Link } from "@inertiajs/vue3";
import axios from "axios";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import { Input } from "@/Components/ui/input";
import { Badge } from "@/Components/ui/badge";
import { Loader2, X } from "lucide-vue-next";

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

const totalProperties = computed(() =>
    (props.cities ?? []).reduce((sum, c) => sum + c.properties, 0)
);

const mappedCount = computed(() => (props.properties ?? []).length);

const search = ref("");
// "property" = one pin per property; "zone" = one territory per zone.
const viewMode = ref("property");

// Selected property panel state.
const selected = ref(null);
const selectedLoading = ref(false);
const selectedId = ref(null);

const mapElement = ref(null);
let map = null;
let themeObserver = null;

// Light theme: CARTO Voyager (soft colors). Dark theme: CARTO Dark Matter,
// pushed to black-with-white-streets by the CSS filter below.
const tileUrl = () =>
    document.documentElement.classList.contains("dark")
        ? "https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png"
        : "https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png";
let pinRecords = [];
let circleRecords = [];
let zoneAreaRecords = [];
let radiusCircle = null;
let pulseMarker = null;

// Andrew's monotone chain convex hull over [lat, lng] points.
const convexHull = (points) => {
    const sorted = [...points].sort((a, b) => a[0] - b[0] || a[1] - b[1]);
    if (sorted.length < 3) {
        return sorted;
    }
    const cross = (o, a, b) =>
        (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0]);
    const lower = [];
    for (const point of sorted) {
        while (
            lower.length >= 2 &&
            cross(lower[lower.length - 2], lower[lower.length - 1], point) <= 0
        ) {
            lower.pop();
        }
        lower.push(point);
    }
    const upper = [];
    for (const point of [...sorted].reverse()) {
        while (
            upper.length >= 2 &&
            cross(upper[upper.length - 2], upper[upper.length - 1], point) <= 0
        ) {
            upper.pop();
        }
        upper.push(point);
    }
    return [...lower.slice(0, -1), ...upper.slice(0, -1)];
};

const FIVE_MILES_IN_METERS = 8046.72;

// Yellow 5-mile radius + pulsing ring around the selected property — the
// radius the future TBP-filler rule is defined with.
const showRadius = (property) => {
    radiusCircle?.remove();
    pulseMarker?.remove();
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
    pulseMarker = L.marker([property.lat, property.lng], {
        icon: L.divIcon({
            className: "",
            html: '<span class="scheduler-pulse"></span>',
            iconSize: [0, 0],
        }),
        interactive: false,
    }).addTo(map);
};

const matchesFilters = (meta) => {
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
    const propertyMode = viewMode.value === "property";
    const setShown = (layer, show) => {
        if (show && !map.hasLayer(layer)) {
            layer.addTo(map);
        } else if (!show && map.hasLayer(layer)) {
            layer.remove();
        }
    };
    [...pinRecords, ...circleRecords].forEach((record) => {
        setShown(record.marker, propertyMode && matchesFilters(record.meta));
    });
    zoneAreaRecords.forEach((record) => {
        record.layers.forEach((layer) => {
            setShown(layer, !propertyMode);
        });
    });
};

watch(search, refreshVisibility);
watch(viewMode, (mode) => {
    if (mode === "zone") {
        clearSelection();
    }
    refreshVisibility();
});

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
    pulseMarker?.remove();
    pulseMarker = null;
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
        // The floating search card sits over Leaflet's default top-left spot.
        zoomControl: false,
    });
    L.control.zoom({ position: "bottomleft" }).addTo(map);

    const tiles = L.tileLayer(tileUrl(), {
        attribution:
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
    }).addTo(map);
    themeObserver = new MutationObserver(() => tiles.setUrl(tileUrl()));
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ["class"],
    });

    const allPoints = [...pins, ...circles].map((point) => [
        point.lat,
        point.lng,
    ]);
    // Open focused on the Houston metro; the Nacogdoches exclave (~140 mi
    // out) would otherwise zoom the default view out to half of East Texas.
    // It stays reachable — panning and zoom-out are only clamped to the
    // full coverage area.
    const houston = L.latLng(29.7604, -95.3698);
    const metroPoints = allPoints.filter(
        (point) => houston.distanceTo(point) < 130_000
    );
    map.fitBounds(L.latLngBounds(metroPoints.length ? metroPoints : allPoints), {
        // Keep pins clear of the floating search card.
        paddingTopLeft: [360, 24],
        paddingBottomRight: [24, 24],
    });
    map.setMaxBounds(L.latLngBounds(allPoints).pad(0.6));

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

    // Zone territories for the "By zone" view: a hull polygon plus a count
    // bubble at the centroid, built from every pin and the not-yet-geocoded
    // remainder (city circles). Hidden until that view is selected.
    const zoneGroups = {};
    const addZonePoint = (zone, lat, lng, count) => {
        const key = zone ?? "none";
        zoneGroups[key] ??= { points: [], count: 0 };
        zoneGroups[key].points.push([lat, lng]);
        zoneGroups[key].count += count;
    };
    pins.forEach((pin) => addZonePoint(pin.zone, pin.lat, pin.lng, 1));
    circles.forEach((city) =>
        addZonePoint(city.zone, city.lat, city.lng, city.ungeocoded)
    );

    zoneAreaRecords = Object.entries(zoneGroups).map(([zone, group]) => {
        const color = zoneColor(zone === "none" ? null : zone);
        const layers = [];
        if (group.points.length >= 3) {
            layers.push(
                L.polygon(convexHull(group.points), {
                    color,
                    weight: 2,
                    dashArray: "4 6",
                    fillColor: color,
                    fillOpacity: 0.12,
                })
            );
        }
        const centroid = [
            group.points.reduce((sum, point) => sum + point[0], 0) /
                group.points.length,
            group.points.reduce((sum, point) => sum + point[1], 0) /
                group.points.length,
        ];
        const label = ZONES.find((z) => z.zone === zone)?.label ?? "No zone";
        layers.push(
            L.circleMarker(centroid, {
                radius: 12 + Math.sqrt(group.count),
                color,
                weight: 2,
                fillColor: color,
                fillOpacity: 0.5,
            }).bindTooltip(
                `${label} — ${group.count} ${
                    group.count === 1 ? "property" : "properties"
                }`
            )
        );
        const zoneBounds = L.latLngBounds(group.points);
        layers.forEach((layer) =>
            layer.on("click", () => map.fitBounds(zoneBounds.pad(0.15)))
        );

        return { zone, layers };
    });
});

onBeforeUnmount(() => {
    themeObserver?.disconnect();
    themeObserver = null;
    map?.remove();
    map = null;
    pinRecords = [];
    circleRecords = [];
    zoneAreaRecords = [];
    radiusCircle = null;
    pulseMarker = null;
});
</script>

<template>
    <Head :title="title" />

    <div
        class="scheduler-map relative -m-4 h-[calc(100dvh-4rem-1px)] overflow-hidden"
    >
        <div ref="mapElement" class="absolute inset-0 z-0" />

        <!-- Floating search + zone filter card -->
        <div
            class="scheduler-drop absolute left-4 top-4 z-[1001] w-[21rem] max-w-[calc(100%-2rem)] space-y-2.5 rounded-lg border bg-background/90 p-3 shadow-lg backdrop-blur"
        >
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="font-semibold leading-none">Scheduler</h2>
                <span class="text-xs text-muted-foreground">
                    {{ mappedCount }} of {{ totalProperties }} mapped
                </span>
            </div>
            <div class="flex rounded-md border p-0.5">
                <button
                    type="button"
                    class="flex-1 rounded px-2 py-1 text-xs transition-colors"
                    :class="
                        viewMode === 'property'
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-accent'
                    "
                    @click="viewMode = 'property'"
                >
                    By property
                </button>
                <button
                    type="button"
                    class="flex-1 rounded px-2 py-1 text-xs transition-colors"
                    :class="
                        viewMode === 'zone'
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-accent'
                    "
                    @click="viewMode = 'zone'"
                >
                    By zone
                </button>
            </div>
            <Input
                v-if="viewMode === 'property'"
                v-model="search"
                placeholder="Search property, address or city..."
                class="h-8"
            />
        </div>

        <!-- Floating property panel -->
        <Transition name="panel">
        <div
            v-if="selectedId"
            class="absolute bottom-4 right-4 top-4 z-[1001] flex w-96 max-w-[calc(100%-2rem)] flex-col overflow-hidden rounded-lg border bg-background/95 shadow-lg backdrop-blur"
        >
            <div
                v-if="selectedLoading"
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
        </Transition>
    </div>
</template>

<style>
/* Dark mode: Apple-Maps-style monochrome — near-black ground with white
   streets and labels. Light mode renders Voyager untouched. */
.dark .scheduler-map .leaflet-tile-pane {
    filter: grayscale(1) brightness(1.9) contrast(1.2);
}

.scheduler-map .leaflet-control-zoom a {
    transition: background-color 0.15s ease;
}

/* Pulsing ring on the selected property. */
.scheduler-pulse {
    position: absolute;
    left: -22px;
    top: -22px;
    width: 44px;
    height: 44px;
    border-radius: 9999px;
    border: 2px solid #eab308;
    animation: scheduler-ping 1.6s cubic-bezier(0, 0, 0.2, 1) infinite;
}

@keyframes scheduler-ping {
    0% {
        transform: scale(0.2);
        opacity: 0.9;
    }
    80%,
    100% {
        transform: scale(1);
        opacity: 0;
    }
}

/* Search card drops in on load. */
.scheduler-drop {
    animation: scheduler-drop-in 0.35s ease-out both;
}

@keyframes scheduler-drop-in {
    from {
        transform: translateY(-10px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Property panel slides in from the right. */
.panel-enter-active,
.panel-leave-active {
    transition:
        transform 0.3s ease,
        opacity 0.3s ease;
}

.panel-enter-from,
.panel-leave-to {
    transform: translateX(1.5rem);
    opacity: 0;
}
</style>
