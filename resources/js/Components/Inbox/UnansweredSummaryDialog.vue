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
import { waitedLabel } from "@/utils/conversation";
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Loader2,
    MessagesSquare,
    RefreshCw,
} from "lucide-vue-next";

/**
 * The unanswered-message report: who has written in and not been answered,
 * which of them matter, and what to do about each.
 *
 * The figures come counted from the server; the AI only writes over them. When
 * the provider is down the same report renders from a deterministic write-up,
 * so this is never blank.
 */
const emit = defineEmits(["open-thread"]);

const open = ref(false);
const isLoading = ref(false);
const errorMessage = ref("");
const stats = ref(null);
const summary = ref(null);
const source = ref(null);
const aiProvider = ref(null);

const fetchSummary = async (refresh = false) => {
    isLoading.value = true;
    errorMessage.value = "";

    try {
        const { data } = await axios.get(route("inbox.summary"), {
            params: { refresh: refresh ? 1 : 0 },
        });

        stats.value = data.stats;
        summary.value = data.summary;
        source.value = data.source;
        aiProvider.value = data.ai_provider;
    } catch (error) {
        errorMessage.value =
            error.response?.status === 403
                ? "You do not have access to the message summary."
                : "Could not build the summary. Please try again.";
        console.error(error);
    } finally {
        isLoading.value = false;
    }
};

// Build on first open only; Refresh is the way to rebuild. A failed fetch is
// not retried automatically so a broken provider cannot loop.
watch(open, (isOpen) => {
    if (isOpen && !stats.value && !isLoading.value) fetchSummary();
});

const priorities = computed(() => summary.value?.priorities ?? []);
const byParty = computed(() => stats.value?.by_party ?? []);

const tiles = computed(() => {
    if (!stats.value) return [];

    const age = stats.value.by_age ?? {};

    return [
        { label: "Waiting on us", value: stats.value.threads, icon: MessagesSquare },
        { label: "Work orders", value: stats.value.work_orders, icon: MessagesSquare },
        { label: "Over a day", value: (age.one_to_three_days ?? 0) + (age.over_three_days ?? 0), icon: Clock },
        {
            label: "Longest wait",
            value:
                stats.value.oldest_waiting_hours === null
                    ? "—"
                    : waitedLabel(stats.value.oldest_waiting_hours),
            icon: Clock,
        },
    ];
});

const URGENCY_DOT = {
    high: "bg-red-500",
    medium: "bg-amber-500",
    low: "bg-emerald-500",
};

const openThread = (item) => {
    emit("open-thread", item);
    open.value = false;
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
                variant="outline"
                size="sm"
                class="h-7 gap-1.5 text-xs"
                aria-label="Summary of messages that have not been answered"
            >
                <SummaryReportIcon class="h-3.5 w-3.5" />
                Summary
            </Button>
        </DialogTrigger>

        <DialogScrollContent class="max-h-[90vh] w-full !max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <SummaryReportIcon class="text-primary h-5 w-5" />
                    Messages that have not been answered
                </DialogTitle>
                <DialogDescription>
                    Every conversation where a tenant, owner or vendor sent the
                    last message and nobody has replied.
                </DialogDescription>
            </DialogHeader>

            <ScrollArea class="bg-secondary max-h-[60vh] rounded-md p-3">
                <div v-if="isLoading" class="flex flex-col items-center gap-3 py-10">
                    <Loader2 class="text-primary h-10 w-10 animate-spin" />
                    <p class="text-muted-foreground text-sm">
                        Reading the unanswered threads…
                    </p>
                </div>

                <div
                    v-else-if="errorMessage"
                    class="text-destructive flex items-center gap-2 py-10 text-sm"
                >
                    <AlertTriangle class="h-4 w-4 shrink-0" />
                    {{ errorMessage }}
                </div>

                <!-- Nothing waiting is a result worth showing plainly. -->
                <div
                    v-else-if="stats && stats.threads === 0"
                    class="flex flex-col items-center gap-2 py-10 text-center"
                >
                    <CheckCircle2 class="h-10 w-10 text-emerald-500" />
                    <p class="text-sm font-medium">Everyone has been answered.</p>
                    <p class="text-muted-foreground text-xs">
                        No conversation is waiting on a reply right now.
                    </p>
                </div>

                <div v-else-if="stats" class="flex flex-col gap-3">
                    <!-- The hard numbers -->
                    <div
                        class="bg-card text-card-foreground max-w-[90%] rounded-2xl rounded-bl-none border px-4 py-3 shadow-md"
                    >
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div v-for="tile in tiles" :key="tile.label">
                                <p
                                    class="text-muted-foreground flex items-center gap-1 text-xs"
                                >
                                    <component :is="tile.icon" class="h-3 w-3" />
                                    {{ tile.label }}
                                </p>
                                <p class="text-xl font-semibold">{{ tile.value }}</p>
                            </div>
                        </div>

                        <div
                            v-if="byParty.length"
                            class="mt-3 flex flex-wrap gap-1 border-t pt-3"
                        >
                            <Badge
                                v-for="party in byParty"
                                :key="party.party"
                                variant="outline"
                            >
                                {{ party.party }} · {{ party.total }}
                            </Badge>
                        </div>
                    </div>

                    <!-- The written briefing -->
                    <div
                        v-if="summary"
                        class="bg-primary text-primary-foreground max-w-[90%] rounded-2xl rounded-bl-none px-4 py-3 shadow-md"
                    >
                        <p class="text-sm font-medium">{{ summary.headline }}</p>

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

                    <!-- Answer these first -->
                    <div
                        v-if="priorities.length"
                        class="bg-card text-card-foreground rounded-2xl rounded-bl-none border px-4 py-3 shadow-md"
                    >
                        <p class="mb-2 text-xs font-bold uppercase">
                            Answer these first
                        </p>
                        <div class="flex flex-col gap-3">
                            <button
                                v-for="item in priorities"
                                :key="item.ref"
                                type="button"
                                class="hover:bg-accent -mx-2 flex gap-2 rounded-md px-2 py-1.5 text-left transition-colors"
                                @click="openThread(item)"
                            >
                                <span
                                    class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                                    :class="URGENCY_DOT[item.urgency] ?? URGENCY_DOT.medium"
                                />
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="flex flex-wrap items-center gap-x-2 gap-y-1"
                                    >
                                        <span class="text-sm font-medium">
                                            {{ item.counterparty }}
                                        </span>
                                        <Badge variant="outline" class="text-[10px]">
                                            {{ item.party }}
                                        </Badge>
                                        <span class="text-muted-foreground text-xs">
                                            WO#{{ item.work_order_no }}
                                        </span>
                                        <span
                                            class="text-xs"
                                            :class="
                                                item.waiting_hours >= 24
                                                    ? 'text-destructive font-semibold'
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{ waitedLabel(item.waiting_hours) }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="item.message"
                                        class="text-muted-foreground mt-0.5 block truncate text-xs italic"
                                    >
                                        “{{ item.message }}”
                                    </span>
                                    <span v-if="item.reason" class="mt-1 block text-sm">
                                        {{ item.reason }}
                                    </span>
                                    <span
                                        v-if="item.next_step"
                                        class="text-primary mt-0.5 block text-xs font-medium"
                                    >
                                        → {{ item.next_step }}
                                    </span>
                                </span>
                            </button>
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
                        Written by AI<span v-if="aiProvider"> ({{ aiProvider }})</span>
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
