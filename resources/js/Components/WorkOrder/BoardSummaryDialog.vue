<script setup>
import { computed, ref, watch } from "vue";
import axios from "axios";
import {
    Dialog,
    DialogScrollContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogTrigger,
} from "@/Components/ui/dialog";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import { ScrollArea } from "@/Components/ui/scroll-area";
import SummaryReportIcon from "@/Components/Icons/SummaryReportIcon.vue";
import {
    Loader2,
    RefreshCw,
    AlertTriangle,
    MessageSquare,
    ClipboardList,
    Clock,
} from "lucide-vue-next";

const props = defineProps({
    // One of WorkOrder::BOARDS — decides which board the summary is built for.
    board: { type: String, required: true },
});

const open = ref(false);
const isLoading = ref(false);
const errorMessage = ref("");
const stats = ref(null);
const summary = ref(null);
const source = ref(null);
const aiProvider = ref(null);

// The board filters live in the URL, so the summary honours whatever the
// coordinator has narrowed the board down to. Only the keys the endpoint
// validates are forwarded.
const FILTER_KEYS = [
    "search",
    "vendor",
    "category",
    "start_date",
    "end_date",
    "emergency",
];

const currentFilters = () => {
    const params = new URLSearchParams(window.location.search);
    const filters = {};

    for (const key of FILTER_KEYS) {
        const value = params.get(key);
        if (value) filters[key] = value;
    }

    return filters;
};

const fetchSummary = async (refresh = false) => {
    isLoading.value = true;
    errorMessage.value = "";

    try {
        const { data } = await axios.get(route("work_orders.summary"), {
            params: { board: props.board, ...currentFilters(), refresh: refresh ? 1 : 0 },
        });

        stats.value = data.stats;
        summary.value = data.summary;
        source.value = data.source;
        aiProvider.value = data.ai_provider;
    } catch (error) {
        errorMessage.value =
            error.response?.status === 403
                ? "You do not have access to board summaries."
                : "Could not build the summary. Please try again.";
        console.error(error);
    } finally {
        isLoading.value = false;
    }
};

// Build on first open only; the Refresh button is the way to rebuild. A failed
// fetch is not retried automatically so a broken provider cannot loop.
watch(open, (isOpen) => {
    if (isOpen && !stats.value && !isLoading.value) fetchSummary();
});

const boardLabel = computed(() => stats.value?.board_label ?? "this board");
const workOrders = computed(() => stats.value?.work_orders ?? null);
const messages = computed(() => stats.value?.messages ?? null);
const awaiting = computed(() => stats.value?.awaiting_reply ?? null);
const attention = computed(() => summary.value?.attention ?? []);

const tiles = computed(() => {
    if (!workOrders.value || !messages.value || !awaiting.value) return [];

    return [
        { label: "On this board", value: workOrders.value.total, icon: ClipboardList },
        { label: "Opened today", value: workOrders.value.created_today, icon: ClipboardList },
        { label: "Not picked up", value: workOrders.value.awaiting_first_touch, icon: Clock },
        { label: "Emergencies", value: workOrders.value.emergencies, icon: AlertTriangle },
        { label: "Texts today", value: messages.value.total_today, icon: MessageSquare },
        { label: "Awaiting reply", value: awaiting.value.threads, icon: MessageSquare },
    ];
});

const SEVERITY_DOT = {
    high: "bg-red-500",
    medium: "bg-amber-500",
    low: "bg-emerald-500",
};

const waitedLabel = (hours) => {
    if (hours == null) return "";
    if (hours < 1) return "under an hour";
    if (hours < 24) return `${hours}h`;

    const days = Math.floor(hours / 24);
    const rest = hours % 24;

    return rest ? `${days}d ${rest}h` : `${days}d`;
};

const generatedAtLabel = computed(() => {
    if (!stats.value?.generated_at) return "";

    return new Date(stats.value.generated_at).toLocaleTimeString([], {
        hour: "numeric",
        minute: "2-digit",
    });
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                class="rounded-full shadow-lg"
                aria-label="Summary report for this board"
            >
                <SummaryReportIcon class="mr-2 h-4 w-4" />
                Summary
            </Button>
        </DialogTrigger>

        <DialogScrollContent class="max-h-[90vh] w-full !max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <SummaryReportIcon class="text-primary h-5 w-5" />
                    Summary — {{ boardLabel }}
                </DialogTitle>
                <DialogDescription>
                    Where this board stands today, with the last 7 days for
                    context.
                </DialogDescription>
            </DialogHeader>

            <ScrollArea class="bg-secondary max-h-[60vh] rounded-md p-3">
                <!-- Building -->
                <div v-if="isLoading" class="flex flex-col items-center gap-3 py-10">
                    <Loader2 class="text-primary h-10 w-10 animate-spin" />
                    <p class="text-muted-foreground text-sm">
                        Reading this board…
                    </p>
                </div>

                <!-- Failed outright -->
                <div
                    v-else-if="errorMessage"
                    class="text-destructive flex items-center gap-2 py-10 text-sm"
                >
                    <AlertTriangle class="h-4 w-4 shrink-0" />
                    {{ errorMessage }}
                </div>

                <div v-else-if="stats" class="flex flex-col gap-3">
                    <!-- The hard numbers -->
                    <div
                        class="bg-card text-card-foreground max-w-[90%] rounded-2xl rounded-bl-none border px-4 py-3 shadow-md"
                    >
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <div v-for="tile in tiles" :key="tile.label">
                                <p
                                    class="text-muted-foreground flex items-center gap-1 text-xs"
                                >
                                    <component :is="tile.icon" class="h-3 w-3" />
                                    {{ tile.label }}
                                </p>
                                <p class="text-xl font-semibold">
                                    {{ tile.value }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- The written briefing -->
                    <div
                        v-if="summary"
                        class="bg-primary text-primary-foreground max-w-[90%] rounded-2xl rounded-bl-none px-4 py-3 shadow-md"
                    >
                        <p class="text-sm font-medium">
                            {{ summary.headline }}
                        </p>

                        <div
                            v-for="section in summary.sections"
                            :key="section.title"
                            class="mt-3"
                        >
                            <p class="text-xs font-bold uppercase opacity-80">
                                {{ section.title }}
                            </p>
                            <ul class="mt-1 space-y-1">
                                <li
                                    v-for="(bullet, index) in section.bullets"
                                    :key="index"
                                    class="text-sm"
                                >
                                    • {{ bullet }}
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- What to act on -->
                    <div
                        v-if="attention.length > 0"
                        class="bg-card text-card-foreground max-w-[90%] rounded-2xl rounded-bl-none border px-4 py-3 shadow-md"
                    >
                        <p class="mb-2 text-xs font-bold uppercase">
                            Needs attention
                        </p>
                        <div class="flex flex-col gap-2">
                            <div
                                v-for="(item, index) in attention"
                                :key="index"
                                class="flex gap-2"
                            >
                                <span
                                    class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                                    :class="
                                        SEVERITY_DOT[item.severity] ??
                                        SEVERITY_DOT.low
                                    "
                                />
                                <div>
                                    <p class="text-sm font-medium">
                                        {{ item.label }}
                                    </p>
                                    <p class="text-muted-foreground text-sm">
                                        {{ item.detail }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- The specific threads behind "awaiting reply" -->
                    <div
                        v-if="awaiting && awaiting.samples.length > 0"
                        class="bg-card text-card-foreground max-w-[90%] rounded-2xl rounded-bl-none border px-4 py-3 shadow-md"
                    >
                        <p class="mb-2 text-xs font-bold uppercase">
                            Longest waiting
                        </p>
                        <div class="flex flex-col gap-1">
                            <div
                                v-for="sample in awaiting.samples"
                                :key="`${sample.work_order_no}-${sample.party}`"
                                class="flex items-center justify-between gap-2 text-sm"
                            >
                                <span
                                    >WO #{{ sample.work_order_no }}
                                    <Badge variant="outline" class="ml-1">{{
                                        sample.party
                                    }}</Badge>
                                </span>
                                <span class="text-muted-foreground shrink-0">
                                    {{ waitedLabel(sample.waiting_hours) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </ScrollArea>

            <div class="flex items-center justify-between gap-2 pt-2">
                <p class="text-muted-foreground text-xs">
                    <span v-if="source === 'fallback'">
                        AI unavailable — showing computed figures.
                    </span>
                    <span v-else-if="source === 'ai'">
                        Written by AI<span v-if="aiProvider">
                            ({{ aiProvider }})</span
                        >
                        from live counts.
                    </span>
                    <span v-if="generatedAtLabel"> Built {{ generatedAtLabel }}.</span>
                </p>

                <Button
                    variant="outline"
                    size="sm"
                    :disabled="isLoading"
                    @click="fetchSummary(true)"
                >
                    <RefreshCw
                        class="mr-1 h-4 w-4"
                        :class="{ 'animate-spin': isLoading }"
                    />
                    Refresh
                </Button>
            </div>
        </DialogScrollContent>
    </Dialog>
</template>
