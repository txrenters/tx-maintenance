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
// "property" = one pin per property; "zone" = one territory per zone;
// "calendar" = month of Jobber visits, day click pins them on the map.
const viewMode = ref("property");
const VIEW_MODES = [
    { key: "property", label: "By property" },
    { key: "zone", label: "By zone" },
    { key: "calendar", label: "Calendar" },
];
// Which properties to show: "all", "thmp" (an open THMP work order), or
// "unscheduled" (an open work order with no service schedule yet).
const propertyFilter = ref("all");

const PROPERTY_FILTERS = [
    { key: "all", label: "All", hint: "Every mapped property" },
    { key: "thmp", label: "THMP", hint: "Buildings with an open THMP work order" },
    { key: "unscheduled", label: "Unscheduled", hint: "Buildings with an open work order not yet scheduled" },
];

const filterCount = (key) =>
    key === "all"
        ? (props.properties ?? []).length
        : (props.properties ?? []).filter((p) => p[key]).length;

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
    if (propertyFilter.value !== "all" && !meta[propertyFilter.value]) {
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
    const propertyMode = viewMode.value === "property";
    const zoneMode = viewMode.value === "zone";
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
            setShown(layer, zoneMode);
        });
    });
};

// ---- Calendar tab: a month of Jobber visits -------------------------------

const calendarMonth = ref(new Date().toISOString().slice(0, 7));
const calendarData = ref({}); // "YYYY-MM" -> { "YYYY-MM-DD": [visit, ...] }
const calendarLoading = ref(false);
const calendarError = ref(false);
const selectedDay = ref(null);
let visitMarkers = [];

const WEEKDAYS = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];

const monthLabel = computed(() => {
    const [year, month] = calendarMonth.value.split("-").map(Number);
    return new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString("en-US", {
        month: "long",
        year: "numeric",
        timeZone: "UTC",
    });
});

// Sunday-first grid; leading nulls pad the first week.
const calendarCells = computed(() => {
    const [year, month] = calendarMonth.value.split("-").map(Number);
    const cells = Array.from(
        { length: new Date(Date.UTC(year, month - 1, 1)).getUTCDay() },
        () => null
    );
    const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
    for (let day = 1; day <= daysInMonth; day++) {
        cells.push(
            `${calendarMonth.value}-${String(day).padStart(2, "0")}`
        );
    }
    return cells;
});

const monthDays = computed(
    () => calendarData.value[calendarMonth.value] ?? {}
);

// Category filter over the month's visits, classified server-side from the
// job titles. These colors are the calendar's visual language.
const VISIT_CATEGORIES = [
    { key: "move_in", label: "Move in", color: "#34d399" },
    { key: "move_out", label: "Move out", color: "#f87171" },
    { key: "tbp", label: "TBP", color: "#38bdf8" },
    { key: "maintenance", label: "Maintenance", color: "#c084fc" },
];
const calendarCategory = ref("all");
const categoriesInMonth = computed(() => {
    const present = new Set();
    Object.values(monthDays.value).forEach((visits) =>
        visits.forEach((visit) => present.add(visit.category))
    );
    return VISIT_CATEGORIES.filter((category) => present.has(category.key));
});
const categoryColor = (key) =>
    VISIT_CATEGORIES.find((category) => category.key === key)?.color ??
    NO_ZONE_COLOR;

// Technician filter, from the visit assignees the Jobber sync stores. Each
// technician keeps a stable color for chips and map dots.
const calendarTech = ref("all");
const TECH_COLORS = [
    "#38bdf8",
    "#34d399",
    "#fbbf24",
    "#f87171",
    "#c084fc",
    "#f472b6",
    "#a3e635",
    "#2dd4bf",
];
const techniciansInMonth = computed(() => {
    const present = new Set();
    Object.values(monthDays.value).forEach((visits) =>
        visits.forEach((visit) =>
            (visit.technicians ?? []).forEach((name) => present.add(name))
        )
    );
    return [...present].sort();
});
const techColor = (name) => {
    const index = techniciansInMonth.value.indexOf(name);
    return index === -1
        ? NO_ZONE_COLOR
        : TECH_COLORS[index % TECH_COLORS.length];
};
const visitColor = (visit) => categoryColor(visit.category);

const dayVisits = (date) =>
    (monthDays.value[date] ?? []).filter(
        (visit) =>
            (calendarCategory.value === "all" ||
                visit.category === calendarCategory.value) &&
            (calendarTech.value === "all" ||
                (visit.technicians ?? []).includes(calendarTech.value))
    );
const today = new Date().toISOString().slice(0, 10);

// This quarter's Tenant Benefit Package jobs with no visit date yet — the
// pool the boss's fill rule draws from ("TBPs within 5 miles of the
// inspection").
const tbpBacklog = ref([]);
const tbpQuarter = ref("");

const loadCalendarMonth = async () => {
    if (calendarData.value[calendarMonth.value]) {
        return;
    }
    calendarLoading.value = true;
    calendarError.value = false;
    try {
        const { data } = await axios.get(route("scheduler.visits"), {
            params: { month: calendarMonth.value },
        });
        calendarData.value = {
            ...calendarData.value,
            [data.month]: data.days,
        };
        tbpBacklog.value = data.tbp_backlog ?? [];
        tbpQuarter.value = data.tbp_quarter ?? "";
    } catch (error) {
        calendarError.value = true;
    } finally {
        calendarLoading.value = false;
    }
};

const shiftMonth = (delta) => {
    const [year, month] = calendarMonth.value.split("-").map(Number);
    const shifted = new Date(Date.UTC(year, month - 1 + delta, 1));
    calendarMonth.value = shifted.toISOString().slice(0, 7);
    selectDay(null);
    loadCalendarMonth();
};

const clearVisitMarkers = () => {
    visitMarkers.forEach((marker) => marker.remove());
    visitMarkers = [];
};

// One focused visit: yellow 5-mile radius + the unscheduled TBP jobs inside
// it — the boss's fill rule ("give the inspector TBPs within 5 miles of the
// move in / move out inspection").
const focusedVisitKey = ref(null);
const nearbyTbpCount = ref(0);
const nearbyTbpSpots = ref(0);
let nearbyMarkers = [];

const escapeHtml = (value) =>
    String(value).replace(
        /[&<>"']/g,
        (ch) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            })[ch]
    );

const visitKey = (visit) => `${visit.job_number}-${visit.street}`;

const clearVisitFocus = () => {
    focusedVisitKey.value = null;
    nearbyTbpCount.value = 0;
    nearbyTbpSpots.value = 0;
    nearbyMarkers.forEach((marker) => marker.remove());
    nearbyMarkers = [];
    radiusCircle?.remove();
    radiusCircle = null;
    pulseMarker?.remove();
    pulseMarker = null;
};

const focusVisit = (visit) => {
    if (!map || !visit.lat || !visit.lng) {
        return;
    }
    if (focusedVisitKey.value === visitKey(visit)) {
        clearVisitFocus();
        return;
    }
    clearVisitFocus();
    focusedVisitKey.value = visitKey(visit);
    showRadius({
        lat: visit.lat,
        lng: visit.lng,
        name: `visit #${visit.job_number}`,
    });

    const center = L.latLng(visit.lat, visit.lng);
    const nearby = tbpBacklog.value.filter(
        (job) =>
            job.lat &&
            job.lng &&
            center.distanceTo([job.lat, job.lng]) <= FIVE_MILES_IN_METERS
    );
    nearbyTbpCount.value = nearby.length;

    // Several TBP jobs often live at one property (filter change + pest
    // control + inspection); one ring per property with a count badge, or
    // five rings stack into what looks like one.
    const byLocation = new Map();
    nearby.forEach((job) => {
        const key = `${job.lat},${job.lng}`;
        byLocation.set(key, [...(byLocation.get(key) ?? []), job]);
    });
    nearbyTbpSpots.value = byLocation.size;

    nearbyMarkers = [...byLocation.values()].flatMap((jobs) => {
        const { lat, lng } = jobs[0];
        const markers = [
            L.circleMarker([lat, lng], {
                radius: 9,
                color: "#38bdf8",
                weight: 2.5,
                fillColor: "#38bdf8",
                fillOpacity: 0.15,
            }).bindTooltip(
                jobs
                    .map(
                        (job) =>
                            `#${escapeHtml(job.job_number)} — ${escapeHtml(job.title)}`
                    )
                    .join("<br>")
            ),
        ];
        if (jobs.length > 1) {
            markers.push(
                L.marker([lat, lng], {
                    icon: L.divIcon({
                        className: "",
                        html: `<span class="scheduler-tbp-count">${jobs.length}</span>`,
                        iconSize: [0, 0],
                    }),
                    interactive: false,
                })
            );
        }
        return markers.map((marker) => marker.addTo(map));
    });

    map.fitBounds(radiusCircle.getBounds().pad(0.1), {
        paddingTopLeft: [380, 24],
        paddingBottomRight: [24, 24],
    });
};

// White outline keeps the zone-colored dots readable on the dark basemap.
const visitDotOutline = () =>
    document.documentElement.classList.contains("dark") ? "#ffffff" : "#111111";

const selectDay = (date) => {
    selectedDay.value = date;
    clearVisitMarkers();
    clearVisitFocus();
    if (!map || !date) {
        return;
    }
    const outline = visitDotOutline();
    const located = dayVisits(date).filter((visit) => visit.lat && visit.lng);
    visitMarkers = located.map((visit) =>
        L.circleMarker([visit.lat, visit.lng], {
            radius: 7,
            color: outline,
            weight: 1.5,
            fillColor: visitColor(visit),
            fillOpacity: 0.95,
        })
            .bindTooltip(
                `#${visit.job_number} — ${visit.title}${
                    visit.technicians?.length
                        ? ` — ${visit.technicians.join(", ")}`
                        : ""
                }`
            )
            .on("click", () => focusVisit(visit))
            .addTo(map)
    );
    if (located.length > 0) {
        map.fitBounds(
            L.latLngBounds(located.map((visit) => [visit.lat, visit.lng])).pad(
                0.25
            ),
            { paddingTopLeft: [380, 24], paddingBottomRight: [24, 24] }
        );
    }
};

// Re-plot the selected day when a calendar filter changes.
watch([calendarCategory, calendarTech], () => {
    if (selectedDay.value) {
        selectDay(selectedDay.value);
    }
});

watch([search, propertyFilter], refreshVisibility);
watch(viewMode, (mode) => {
    if (mode !== "property") {
        clearSelection();
    }
    if (mode === "calendar") {
        loadCalendarMonth();
    } else {
        selectDay(null);
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
                // Aggregate of ungeocoded properties — per-building status
                // unknown, so the THMP/Unscheduled filters hide these circles.
                thmp: false,
                unscheduled: false,
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
                thmp: property.thmp,
                unscheduled: property.unscheduled,
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
    visitMarkers = [];
    nearbyMarkers = [];
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
            class="scheduler-drop absolute left-4 top-4 z-[1001] max-w-[calc(100%-2rem)] space-y-2.5 rounded-lg border bg-background/90 p-3 shadow-lg backdrop-blur"
            :class="viewMode === 'calendar' ? 'w-[23rem]' : 'w-[21rem]'"
        >
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="flex items-baseline gap-1.5 font-semibold leading-none">
                    Scheduler
                    <Badge variant="outline" class="text-[9px] font-normal">
                        Still developing
                    </Badge>
                </h2>
                <span class="text-xs text-muted-foreground">
                    {{ mappedCount }} of {{ totalProperties }} mapped
                </span>
            </div>
            <div class="flex rounded-md border p-0.5">
                <button
                    v-for="mode in VIEW_MODES"
                    :key="mode.key"
                    type="button"
                    class="flex-1 rounded px-2 py-1 text-xs transition-colors"
                    :class="
                        viewMode === mode.key
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-accent'
                    "
                    @click="viewMode = mode.key"
                >
                    {{ mode.label }}
                </button>
            </div>
            <div v-if="viewMode === 'property'" class="flex rounded-md border p-0.5">
                <button
                    v-for="filter in PROPERTY_FILTERS"
                    :key="filter.key"
                    type="button"
                    :title="filter.hint"
                    class="flex-1 rounded px-2 py-1 text-xs transition-colors"
                    :class="
                        propertyFilter === filter.key
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-accent'
                    "
                    @click="propertyFilter = filter.key"
                >
                    {{ filter.label }} · {{ filterCount(filter.key) }}
                </button>
            </div>
            <Input
                v-if="viewMode === 'property'"
                v-model="search"
                placeholder="Search property, address or city..."
                class="h-8"
            />

            <!-- Full-month Jobber visit calendar -->
            <template v-if="viewMode === 'calendar'">
                <div class="flex items-center justify-between">
                    <button
                        type="button"
                        class="rounded px-2 py-0.5 text-sm text-muted-foreground hover:bg-accent"
                        @click="shiftMonth(-1)"
                    >
                        ‹
                    </button>
                    <span class="text-sm font-medium">{{ monthLabel }}</span>
                    <button
                        type="button"
                        class="rounded px-2 py-0.5 text-sm text-muted-foreground hover:bg-accent"
                        @click="shiftMonth(1)"
                    >
                        ›
                    </button>
                </div>

                <p
                    v-if="calendarError && Object.keys(monthDays).length === 0"
                    class="text-xs text-destructive"
                >
                    Could not load visits for this month.
                </p>

                <div
                    v-if="categoriesInMonth.length"
                    class="flex flex-wrap gap-1"
                >
                    <button
                        type="button"
                        class="rounded-full border px-2 py-0.5 text-[10px] transition-colors"
                        :class="
                            calendarCategory === 'all'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent'
                        "
                        @click="calendarCategory = 'all'"
                    >
                        All types
                    </button>
                    <button
                        v-for="category in categoriesInMonth"
                        :key="category.key"
                        type="button"
                        class="flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] transition-colors"
                        :class="
                            calendarCategory === category.key
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent'
                        "
                        @click="calendarCategory = category.key"
                    >
                        <span
                            class="inline-block h-2 w-2 rounded-full"
                            :style="{ backgroundColor: category.color }"
                        />
                        {{ category.label }}
                    </button>
                </div>

                <div
                    v-if="techniciansInMonth.length"
                    class="flex flex-wrap gap-1"
                >
                    <button
                        type="button"
                        class="rounded-full border px-2 py-0.5 text-[10px] transition-colors"
                        :class="
                            calendarTech === 'all'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent'
                        "
                        @click="calendarTech = 'all'"
                    >
                        All techs
                    </button>
                    <button
                        v-for="technician in techniciansInMonth"
                        :key="technician"
                        type="button"
                        class="flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] transition-colors"
                        :class="
                            calendarTech === technician
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent'
                        "
                        @click="calendarTech = technician"
                    >
                        <span
                            class="inline-block h-2 w-2 rounded-full"
                            :style="{ backgroundColor: techColor(technician) }"
                        />
                        {{ technician }}
                    </button>
                </div>
                <p
                    v-else-if="Object.keys(monthDays).length"
                    class="text-[10px] text-muted-foreground"
                >
                    Technician info appears after the next Jobber sync.
                </p>

                <div class="grid grid-cols-7 gap-1 text-center">
                    <span
                        v-for="weekday in WEEKDAYS"
                        :key="weekday"
                        class="text-[10px] font-medium uppercase text-muted-foreground"
                    >
                        {{ weekday }}
                    </span>
                    <template v-for="(cell, index) in calendarCells" :key="index">
                        <span v-if="cell === null" />
                        <button
                            v-else
                            type="button"
                            class="relative rounded-md py-1.5 text-xs transition-colors"
                            :class="[
                                selectedDay === cell
                                    ? 'bg-primary text-primary-foreground'
                                    : dayVisits(cell).length
                                      ? 'font-medium hover:bg-accent'
                                      : 'text-muted-foreground hover:bg-accent',
                                cell === today && selectedDay !== cell
                                    ? 'ring-1 ring-foreground/40'
                                    : '',
                            ]"
                            @click="selectDay(selectedDay === cell ? null : cell)"
                        >
                            {{ Number(cell.slice(8)) }}
                            <span
                                v-if="dayVisits(cell).length"
                                class="absolute inset-x-0 -bottom-0.5 text-[9px] leading-none"
                                :class="
                                    selectedDay === cell
                                        ? 'text-primary-foreground/80'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ dayVisits(cell).length }}
                            </span>
                        </button>
                    </template>
                </div>

                <div
                    v-if="calendarLoading"
                    class="flex justify-center py-2"
                >
                    <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
                </div>

                <div
                    v-if="selectedDay"
                    class="max-h-56 space-y-1 overflow-y-auto border-t pt-2"
                >
                    <p class="px-1 text-xs text-muted-foreground">
                        {{ dayVisits(selectedDay).length }}
                        {{ dayVisits(selectedDay).length === 1 ? "visit" : "visits" }}
                        on {{ selectedDay }} — dots on the map
                    </p>
                    <p
                        v-if="dayVisits(selectedDay).length === 0"
                        class="px-1 text-xs text-muted-foreground"
                    >
                        No visits booked this day.
                    </p>
                    <button
                        v-for="visit in dayVisits(selectedDay)"
                        :key="visitKey(visit)"
                        type="button"
                        class="block w-full rounded-md px-2 py-1.5 text-left text-xs transition-colors"
                        :class="
                            focusedVisitKey === visitKey(visit)
                                ? 'bg-accent ring-1 ring-[#eab308]'
                                : visit.lat
                                  ? 'hover:bg-accent'
                                  : 'cursor-default opacity-70'
                        "
                        @click="focusVisit(visit)"
                    >
                        <span
                            class="mr-1 inline-block h-2 w-2 rounded-full"
                            :style="{ backgroundColor: visitColor(visit) }"
                        />
                        <span class="font-medium">#{{ visit.job_number }}</span>
                        <span
                            v-if="!visit.lat"
                            class="ml-1 text-[10px] text-muted-foreground"
                            title="No geocoded building matches this Jobber property address"
                        >
                            (not on map)
                        </span>
                        <p class="text-muted-foreground line-clamp-1">
                            {{ visit.title }}
                        </p>
                        <p
                            v-if="visit.technicians?.length"
                            class="text-[10px] text-muted-foreground"
                        >
                            {{ visit.technicians.join(", ") }}
                        </p>
                        <p
                            v-if="focusedVisitKey === visitKey(visit)"
                            class="mt-0.5 text-[10px] font-medium text-[#38bdf8]"
                        >
                            {{ nearbyTbpCount }} unscheduled {{ tbpQuarter }}
                            {{ nearbyTbpCount === 1 ? "TBP" : "TBPs" }}
                            <template v-if="nearbyTbpSpots < nearbyTbpCount">
                                at {{ nearbyTbpSpots }}
                                {{
                                    nearbyTbpSpots === 1
                                        ? "property"
                                        : "properties"
                                }}
                            </template>
                            within 5 miles
                        </p>
                    </button>
                </div>
            </template>
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

/* Count badge on a TBP location holding several jobs. */
.scheduler-tbp-count {
    position: absolute;
    left: 5px;
    top: -16px;
    min-width: 15px;
    height: 15px;
    padding: 0 3px;
    border-radius: 9999px;
    background: #38bdf8;
    color: #0c1220;
    font-size: 10px;
    font-weight: 700;
    line-height: 15px;
    text-align: center;
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
