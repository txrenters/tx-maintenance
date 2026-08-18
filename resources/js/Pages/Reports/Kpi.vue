<script setup>
import { ref, computed, watch } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import { Sparkles } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    description: String,
    reportKey: String,
    hasMonthFilter: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
    years: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    breached: { type: Number, default: 0 },
    percentage: { type: Number, default: 0 },
    countLabel: { type: String, default: "No. of WOs" },
    columns: { type: Array, default: () => [] },
    lists: { type: Array, default: () => [] },
    // Reports that support the per-row "why is this stuck" AI digest pass
    // true and expose a matching reports.{reportKey}.analyze endpoint.
    aiDigest: { type: Boolean, default: false },
});

// Per-row AI digests, keyed by work order id, shown in a modal. On demand
// only — one click, one row, one AI call (cached server-side for a day).
const digests = ref({});
const digestOpen = ref(false);
const digestRow = ref(null);

const digest = computed(() =>
    digestRow.value ? digests.value[digestRow.value.id] : null,
);

const analyzeRow = async (row, refresh = false) => {
    digests.value[row.id] = { loading: true };
    try {
        const { data } = await axios.post(
            route(`reports.${props.reportKey}.analyze`),
            { work_order_id: row.id, refresh },
        );
        digests.value[row.id] = { loading: false, ...data };
    } catch {
        digests.value[row.id] = { loading: false, available: false };
    }
};

const openDigest = (row) => {
    digestRow.value = row;
    digestOpen.value = true;

    if (!digests.value[row.id]) {
        analyzeRow(row);
    }
};

const months = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
];

const year = ref(props.filters.year ?? new Date().getFullYear());
const month = ref(props.filters.month ?? new Date().getMonth() + 1);

const activeList = ref(props.lists[0]?.key ?? "breached");
const pageNum = ref(1);
const perPage = 20;

const currentList = computed(
    () => props.lists.find((l) => l.key === activeList.value) ?? null
);
const currentRows = computed(() => currentList.value?.rows ?? []);

// Stats reflect the selected tab, not always the breached set.
const activeCount = computed(() => currentRows.value.length);
const activePercentage = computed(() =>
    props.total > 0
        ? Math.round((activeCount.value / props.total) * 1000) / 10
        : 0
);
const totalPages = computed(() =>
    Math.max(1, Math.ceil(currentRows.value.length / perPage))
);
const paginatedRows = computed(() =>
    currentRows.value.slice((pageNum.value - 1) * perPage, pageNum.value * perPage)
);

watch(currentRows, () => (pageNum.value = 1));

const selectList = (key) => {
    activeList.value = key;
    pageNum.value = 1;
};

const applyFilter = () => {
    router.get(
        route(`reports.${props.reportKey}`),
        { year: year.value, month: month.value },
        { preserveState: true, preserveScroll: true, replace: true }
    );
};

const fmtDate = (v) => {
    if (!v) return "—";
    const d = new Date(v);
    if (isNaN(d.getTime())) return v;
    return d.toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
};

const cellValue = (row, col) => {
    const v = row[col.key];
    if (col.type === "date") return fmtDate(v);
    return v ?? "—";
};

const truncateWords = (v, limit = 50) => {
    if (!v) return "—";
    const words = String(v).trim().split(/\s+/);
    if (words.length <= limit) return v;
    return words.slice(0, limit).join(" ") + "…";
};

const subtitle = computed(() => {
    const noun = props.countLabel.toLowerCase().includes("task")
        ? "tasks"
        : "work orders";
    return `${activeCount.value} of ${props.total} ${noun} (${activePercentage.value}%)`;
});
</script>

<template>
    <Head :title="title" />

    <Card>
        <CardHeader>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <CardTitle>{{ title }}</CardTitle>
                    <p class="text-sm text-muted-foreground mt-1">
                        {{ description }}
                    </p>
                </div>

                <div v-if="hasMonthFilter" class="flex gap-2">
                    <Select
                        :modelValue="String(month)"
                        @update:modelValue="(v) => { month = Number(v); applyFilter(); }"
                    >
                        <SelectTrigger class="w-[140px]">
                            <SelectValue placeholder="Month" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem
                                    v-for="(m, i) in months"
                                    :key="i"
                                    :value="String(i + 1)"
                                    >{{ m }}</SelectItem
                                >
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <Select
                        :modelValue="String(year)"
                        @update:modelValue="(v) => { year = Number(v); applyFilter(); }"
                    >
                        <SelectTrigger class="w-[110px]">
                            <SelectValue placeholder="Year" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem
                                    v-for="y in years"
                                    :key="y"
                                    :value="String(y)"
                                    >{{ y }}</SelectItem
                                >
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </div>
            </div>
        </CardHeader>

        <CardContent class="space-y-5">
            <!-- Metric tiles (reflect the selected tab) -->
            <div class="grid grid-cols-2 gap-3 max-w-md">
                <div class="rounded-lg border border-border p-4">
                    <p class="text-xs font-medium text-muted-foreground uppercase">
                        Percentage
                    </p>
                    <p class="text-3xl font-bold text-foreground mt-1">
                        {{ activePercentage }}%
                    </p>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <p class="text-xs font-medium text-muted-foreground uppercase">
                        {{ countLabel }}
                    </p>
                    <p class="text-3xl font-bold text-foreground mt-1">
                        {{ activeCount }}
                    </p>
                </div>
            </div>

            <p class="text-sm text-muted-foreground">{{ subtitle }}</p>

            <!-- List tabs -->
            <div class="flex flex-wrap gap-2 border-b pb-3">
                <button
                    v-for="l in lists"
                    :key="l.key"
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="
                        activeList === l.key
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-border text-muted-foreground hover:bg-accent'
                    "
                    @click="selectList(l.key)"
                >
                    {{ l.label }} ({{ l.rows.length }})
                </button>
            </div>

            <!-- Drill-down list -->
            <Table v-if="paginatedRows.length">
                <TableHeader>
                    <TableRow>
                        <TableHead
                            v-for="col in columns"
                            :key="col.key"
                            :class="col.type === 'right' ? 'text-right' : ''"
                        >
                            {{ col.label }}
                        </TableHead>
                        <TableHead v-if="aiDigest">Why stuck (AI)</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="(row, i) in paginatedRows" :key="i">
                        <TableCell
                            v-for="col in columns"
                            :key="col.key"
                            :class="[
                                col.type === 'right' ? 'text-right' : '',
                                col.type === 'truncate' ? 'max-w-xs' : '',
                            ]"
                        >
                            <Link
                                v-if="col.type === 'wo_link'"
                                :href="route('work_orders.details', row.id)"
                                class="font-medium text-primary hover:underline"
                            >
                                #{{ row[col.key] }}
                            </Link>
                            <span
                                v-else-if="col.type === 'truncate'"
                                class="block max-w-[280px] break-words"
                                :title="row[col.key]"
                                >{{ truncateWords(row[col.key]) }}</span
                            >
                            <span v-else>{{ cellValue(row, col) }}</span>
                        </TableCell>
                        <TableCell v-if="aiDigest">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="digests[row.id]?.loading"
                                @click="openDigest(row)"
                            >
                                <Loader2
                                    v-if="digests[row.id]?.loading"
                                    class="mr-1 h-3.5 w-3.5 animate-spin"
                                />
                                <Sparkles v-else class="mr-1 h-3.5 w-3.5" />
                                {{
                                    digests[row.id]?.available
                                        ? "View"
                                        : "Analyze"
                                }}
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <div
                v-else
                class="rounded-lg border bg-muted/30 p-8 text-center text-sm text-muted-foreground"
            >
                Nothing in this list for the selected period.
            </div>

            <!-- Pagination -->
            <div
                v-if="currentRows.length > perPage"
                class="flex items-center justify-between text-sm text-muted-foreground"
            >
                <span>
                    Showing {{ (pageNum - 1) * perPage + 1 }}–{{
                        Math.min(pageNum * perPage, currentRows.length)
                    }}
                    of {{ currentRows.length }}
                </span>
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        class="rounded-md border px-3 py-1 disabled:opacity-50 hover:bg-accent"
                        :disabled="pageNum <= 1"
                        @click="pageNum--"
                    >
                        Prev
                    </button>
                    <span class="px-2">Page {{ pageNum }} / {{ totalPages }}</span>
                    <button
                        type="button"
                        class="rounded-md border px-3 py-1 disabled:opacity-50 hover:bg-accent"
                        :disabled="pageNum >= totalPages"
                        @click="pageNum++"
                    >
                        Next
                    </button>
                </div>
            </div>

            <!-- AI digest modal (teleported; kept inside the single root) -->
            <Dialog v-model:open="digestOpen">
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle class="flex items-center gap-2">
                            <Sparkles class="h-4 w-4" />
                            WO #{{ digestRow?.work_order_no }} — AI analysis
                        </DialogTitle>
                        <DialogDescription class="line-clamp-2">
                            {{ digestRow?.description }}
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        v-if="digest?.loading"
                        class="flex items-center gap-2 py-6 text-sm text-muted-foreground"
                    >
                        <Loader2 class="h-4 w-4 animate-spin" />
                        Reading the work order's messages, notes and tasks…
                    </div>

                    <div v-else-if="digest?.available" class="space-y-4 py-2">
                        <div>
                            <p
                                class="text-xs font-semibold uppercase text-muted-foreground"
                            >
                                Why it looks stuck
                            </p>
                            <p class="mt-1 text-sm">
                                {{ digest.stuck_reason }}
                            </p>
                        </div>
                        <div>
                            <p
                                class="text-xs font-semibold uppercase text-muted-foreground"
                            >
                                Next step
                            </p>
                            <p class="mt-1 text-sm font-medium">
                                {{ digest.next_action }}
                            </p>
                        </div>
                        <p class="text-[11px] text-muted-foreground">
                            AI-written from this work order's own messages,
                            notes, tasks and schedules — double-check before
                            acting on it.
                        </p>
                    </div>

                    <div
                        v-else
                        class="py-6 text-sm text-muted-foreground"
                    >
                        The AI analysis is unavailable right now — try again in
                        a moment.
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            :disabled="digest?.loading"
                            @click="analyzeRow(digestRow, true)"
                        >
                            <Loader2
                                v-if="digest?.loading"
                                class="mr-1 h-3.5 w-3.5 animate-spin"
                            />
                            Re-analyze
                        </Button>
                        <Button @click="digestOpen = false">Close</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </CardContent>
    </Card>
</template>
