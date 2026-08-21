<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { Head } from "@inertiajs/vue3";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    cities: Array,
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

const zoneLegend = computed(() =>
    ZONES.map((z) => ({
        ...z,
        properties: (props.cities ?? [])
            .filter((c) => c.zone === z.zone)
            .reduce((sum, c) => sum + c.properties, 0),
    }))
);

const totalProperties = computed(() =>
    (props.cities ?? []).reduce((sum, c) => sum + c.properties, 0)
);

const mapElement = ref(null);
let map = null;

onMounted(() => {
    const plotted = (props.cities ?? []).filter((c) => c.lat && c.lng);
    if (!mapElement.value || plotted.length === 0) {
        return;
    }

    map = L.map(mapElement.value, { minZoom: 7, maxZoom: 15 });

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution:
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);

    const bounds = L.latLngBounds(plotted.map((c) => [c.lat, c.lng]));
    map.fitBounds(bounds.pad(0.08));
    // Keep the view on the coverage area only.
    map.setMaxBounds(bounds.pad(0.6));

    plotted.forEach((city) => {
        const color = zoneColor(city.zone);

        L.circleMarker([city.lat, city.lng], {
            radius: 5 + Math.sqrt(city.properties) * 1.7,
            color,
            weight: 1.5,
            fillColor: color,
            fillOpacity: 0.35,
        })
            .bindTooltip(
                `${city.name} — ${city.properties} ${
                    city.properties === 1 ? "property" : "properties"
                }${city.zone ? ` — Zone ${city.zone}` : ""}`
            )
            .addTo(map);
    });
});

onBeforeUnmount(() => {
    map?.remove();
    map = null;
});
</script>

<template>
    <Head :title="title" />

    <div class="bg-background border rounded-lg p-4">
        <h2 class="text-lg font-semibold">Scheduler</h2>
        <p class="text-sm text-muted-foreground">
            Coverage area: Greater Houston, TX + Nacogdoches, TX —
            {{ totalProperties }} active properties, one circle per city.
        </p>
    </div>

    <div class="bg-background border rounded-lg overflow-hidden">
        <div ref="mapElement" class="h-[32rem] w-full z-0" />
    </div>

    <div class="bg-background border rounded-lg p-4">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
            <div
                v-for="zone in zoneLegend"
                :key="zone.zone"
                class="flex items-center gap-2 text-sm"
            >
                <span
                    class="inline-block h-3 w-3 rounded-full"
                    :style="{ backgroundColor: zone.color }"
                />
                <span>{{ zone.label }}</span>
                <span class="text-muted-foreground">
                    {{ zone.properties }}
                </span>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <span
                    class="inline-block h-3 w-3 rounded-full"
                    :style="{ backgroundColor: NO_ZONE_COLOR }"
                />
                <span>No zone yet</span>
            </div>
        </div>
        <p class="mt-2 text-xs text-muted-foreground">
            Zones come from the PropertyWare "Zone" field on work orders (the
            same "Zone N" that appears in Jobber job titles). Each city is
            colored by its most common zone.
        </p>
    </div>
</template>
