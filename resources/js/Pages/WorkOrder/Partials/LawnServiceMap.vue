<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import { Map as MapIcon } from "lucide-vue-next";
import { Button } from "@/Components/ui/button";
import { Dialog, DialogContent, DialogTitle, DialogDescription } from "@/Components/ui/dialog";
import { statusList } from "@/utils/serviceStatusList";

/**
 * The Lawn Service board's map, the way a game shows one: a small corner
 * mini map that is only a picture of where the work is, and a full map on
 * click with a pin per property that opens the work order (John Carlo,
 * 2026-10-27).
 *
 * Pins come from the board's own payload: every open lawn work order whose
 * building has coordinates from geocode:buildings. A building the Census
 * geocoder has not placed yet is counted, not hidden, so the mini map says
 * how much of the board it is showing.
 */
const props = defineProps({
    serviceStatus: { type: [Array, Object], default: () => [] },
});

const emit = defineEmits(["open"]);

/** One colour per column, in board order, so the pins read like the columns. */
const COLUMN_COLORS = ["#f87171", "#fbbf24", "#60a5fa", "#34d399", "#a78bfa", "#f472b6", "#22d3ee", "#fb923c"];

const columns = computed(() =>
    statusList(props.serviceStatus).map((status, index) => ({
        id: status.id,
        name: status.name,
        color: COLUMN_COLORS[index % COLUMN_COLORS.length],
        workOrders: Object.values(status.work_orders ?? {}),
    }))
);

const pins = computed(() =>
    columns.value.flatMap((column) =>
        column.workOrders
            .filter((workOrder) => workOrder.building?.latitude != null && workOrder.building?.longitude != null)
            .map((workOrder) => ({
                id: workOrder.id,
                workOrderNo: workOrder.work_order_no,
                label: workOrder.building?.name || workOrder.location || `#${workOrder.work_order_no}`,
                lat: Number(workOrder.building.latitude),
                lng: Number(workOrder.building.longitude),
                status: column.name,
                color: column.color,
            }))
    )
);

const unplaced = computed(() =>
    columns.value.flatMap((column) =>
        column.workOrders
            .filter((workOrder) => !(workOrder.building?.latitude != null && workOrder.building?.longitude != null))
            .map((workOrder) => ({
                id: workOrder.id,
                workOrderNo: workOrder.work_order_no,
                label: workOrder.building?.name || workOrder.location || `#${workOrder.work_order_no}`,
                status: column.name,
            }))
    )
);

// Houston metro, so an empty board still shows the area rather than the world.
const HOME_BOUNDS = L.latLngBounds([29.3, -95.9], [30.2, -94.9]);

const isDark = () => document.documentElement.classList.contains("dark");

// Esri's canvas tiles: streets and place names without the highway shields
// OSM's default tiles print over every pin. Base and labels are two layers so
// the labels stay above the pins.
const tileUrl = (layer) =>
    `https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_${isDark() ? "Dark" : "Light"}_Gray_${layer}/MapServer/tile/{z}/{y}/{x}`;

const ATTRIBUTION = 'Esri &mdash; &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

const pinIcon = (color, size) =>
    L.divIcon({
        className: "",
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
        html: `<span style="display:block;width:${size}px;height:${size}px;border-radius:9999px;background:${color};border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.35)"></span>`,
    });

const bounds = () => (pins.value.length ? L.latLngBounds(pins.value.map((pin) => [pin.lat, pin.lng])).pad(0.2) : HOME_BOUNDS);

// --- Mini map -------------------------------------------------------------

const miniElement = ref(null);
let miniMap = null;
let miniLayer = null;

const drawMini = () => {
    if (!miniMap) return;
    miniLayer?.remove();
    miniLayer = L.layerGroup(pins.value.map((pin) => L.marker([pin.lat, pin.lng], { icon: pinIcon(pin.color, 8), interactive: false })));
    miniLayer.addTo(miniMap);
    miniMap.fitBounds(bounds(), { animate: false });
};

onMounted(() => {
    if (!miniElement.value) return;

    miniMap = L.map(miniElement.value, {
        zoomControl: false,
        attributionControl: false,
        dragging: false,
        scrollWheelZoom: false,
        doubleClickZoom: false,
        touchZoom: false,
        boxZoom: false,
        keyboard: false,
        tap: false,
    });
    L.tileLayer(tileUrl("Base"), { maxZoom: 16 }).addTo(miniMap);
    drawMini();
});

watch(pins, drawMini);

// --- Full map -------------------------------------------------------------

const open = ref(false);
const fullElement = ref(null);
let fullMap = null;
let fullLayer = null;

const drawFull = () => {
    if (!fullMap) return;
    fullLayer?.remove();
    fullLayer = L.layerGroup(
        pins.value.map((pin) => {
            const marker = L.marker([pin.lat, pin.lng], { icon: pinIcon(pin.color, 16), title: `#${pin.workOrderNo} ${pin.label}` });
            const popup = document.createElement("div");
            popup.className = "space-y-1 text-sm";
            popup.innerHTML = `<div class="font-semibold">#${pin.workOrderNo}</div><div>${pin.label}</div><div class="text-xs opacity-70">${pin.status}</div>`;
            const button = document.createElement("button");
            button.type = "button";
            button.className = "mt-1 rounded bg-primary px-2 py-1 text-xs font-medium text-primary-foreground";
            button.textContent = "Open work order";
            button.addEventListener("click", () => {
                open.value = false;
                emit("open", pin.id);
            });
            popup.appendChild(button);
            marker.bindPopup(popup);
            return marker;
        })
    );
    fullLayer.addTo(fullMap);
    fullMap.fitBounds(bounds(), { animate: false });
};

// The dialog animates in, so the pane is 0x0 when Leaflet first sees it;
// size it again once the frame has settled or the tiles tear.
const mountFull = async () => {
    await nextTick();
    if (!fullElement.value) return;

    if (!fullMap) {
        fullMap = L.map(fullElement.value, { zoomControl: true, attributionControl: true });
        L.tileLayer(tileUrl("Base"), { maxZoom: 18, attribution: ATTRIBUTION }).addTo(fullMap);
        L.tileLayer(tileUrl("Reference"), { maxZoom: 18, pane: "shadowPane" }).addTo(fullMap);
    }

    drawFull();
    setTimeout(() => {
        fullMap?.invalidateSize({ animate: false });
        fullMap?.fitBounds(bounds(), { animate: false });
    }, 120);
};

watch(open, (isOpen) => {
    if (isOpen) {
        mountFull();
    } else {
        fullMap?.remove();
        fullMap = null;
        fullLayer = null;
    }
});

watch(pins, () => open.value && drawFull());

onBeforeUnmount(() => {
    miniMap?.remove();
    fullMap?.remove();
});

const legend = computed(() =>
    columns.value
        .map((column) => ({
            name: column.name,
            color: column.color,
            count: pins.value.filter((pin) => pin.status === column.name).length,
        }))
        .filter((entry) => entry.count > 0)
);
</script>

<template>
    <!-- The mini map: a corner picture of the board, click for the real map. -->
    <button
        type="button"
        class="fixed bottom-24 right-6 z-30 hidden overflow-hidden rounded-lg border border-border bg-card shadow-lg transition hover:ring-2 hover:ring-primary md:block"
        :title="`${pins.length} on the map${unplaced.length ? `, ${unplaced.length} not located yet` : ''} - click to open`"
        data-testid="lawn-mini-map"
        @click="open = true"
    >
        <div ref="miniElement" class="pointer-events-none h-36 w-56"></div>
        <div class="flex items-center justify-between gap-2 border-t border-border px-2 py-1 text-[11px] text-muted-foreground">
            <span class="flex items-center gap-1"><MapIcon class="h-3 w-3" /> Map</span>
            <span>{{ pins.length }} on map<template v-if="unplaced.length"> · {{ unplaced.length }} unplaced</template></span>
        </div>
    </button>

    <Dialog v-model:open="open">
        <DialogContent class="flex h-[90vh] w-[95vw] max-w-[95vw] flex-col gap-0 overflow-hidden p-0">
            <div class="flex items-center justify-between gap-3 border-b border-border py-2 pl-4 pr-12">
                <div>
                    <DialogTitle class="text-base">Lawn Service map</DialogTitle>
                    <DialogDescription class="text-xs">
                        {{ pins.length }} properties on the map<template v-if="unplaced.length">, {{ unplaced.length }} without a location yet</template>. Click a pin to open its work order.
                    </DialogDescription>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-xs">
                    <span v-for="entry in legend" :key="entry.name" class="flex items-center gap-1">
                        <span class="inline-block h-3 w-3 rounded-full border border-white" :style="{ background: entry.color }"></span>
                        {{ entry.name }} ({{ entry.count }})
                    </span>
                </div>
            </div>
            <div ref="fullElement" class="min-h-0 flex-1" data-testid="lawn-full-map"></div>
            <div v-if="unplaced.length" class="max-h-20 overflow-y-auto border-t border-border px-4 py-1 text-xs text-muted-foreground">
                Not on the map yet:
                <button
                    v-for="item in unplaced"
                    :key="item.id"
                    type="button"
                    class="ml-2 underline-offset-2 hover:underline"
                    @click="open = false; emit('open', item.id)"
                >
                    #{{ item.workOrderNo }} {{ item.label }}
                </button>
            </div>
        </DialogContent>
    </Dialog>
</template>
