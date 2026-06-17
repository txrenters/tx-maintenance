<script setup>
import { ref, computed } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { router } from "@inertiajs/vue3";

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
    rows: { type: Array, default: () => [] },
});

const months = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
];

const year = ref(props.filters.year ?? new Date().getFullYear());
const month = ref(props.filters.month ?? new Date().getMonth() + 1);
const activeMetric = ref("percentage");

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
    if (isNaN(d.getTime())) return v; // pass through "Open", "Never scheduled", etc.
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

const subtitle = computed(() => {
    const noun = props.countLabel.toLowerCase().includes("task")
        ? "tasks"
        : "work orders";
    return `${props.breached} of ${props.total} ${noun} (${props.percentage}%)`;
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
            <!-- Metric tabs -->
            <div class="grid grid-cols-2 gap-3 max-w-md">
                <button
                    type="button"
                    class="rounded-lg border p-4 text-left transition-colors"
                    :class="
                        activeMetric === 'percentage'
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:bg-accent'
                    "
                    @click="activeMetric = 'percentage'"
                >
                    <p class="text-xs font-medium text-muted-foreground uppercase">
                        Percentage
                    </p>
                    <p class="text-3xl font-bold text-foreground mt-1">
                        {{ percentage }}%
                    </p>
                </button>
                <button
                    type="button"
                    class="rounded-lg border p-4 text-left transition-colors"
                    :class="
                        activeMetric === 'count'
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:bg-accent'
                    "
                    @click="activeMetric = 'count'"
                >
                    <p class="text-xs font-medium text-muted-foreground uppercase">
                        {{ countLabel }}
                    </p>
                    <p class="text-3xl font-bold text-foreground mt-1">
                        {{ breached }}
                    </p>
                </button>
            </div>

            <p class="text-sm text-muted-foreground">{{ subtitle }}</p>

            <!-- Drill-down list -->
            <Table v-if="rows.length">
                <TableHeader>
                    <TableRow>
                        <TableHead
                            v-for="col in columns"
                            :key="col.key"
                            :class="col.type === 'right' ? 'text-right' : ''"
                        >
                            {{ col.label }}
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="(row, i) in rows" :key="i">
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
                                class="line-clamp-1 block"
                                >{{ row[col.key] || "—" }}</span
                            >
                            <span v-else>{{ cellValue(row, col) }}</span>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <div
                v-else
                class="rounded-lg border bg-muted/30 p-8 text-center text-sm text-muted-foreground"
            >
                🎉 None for this period — nothing breached.
            </div>
        </CardContent>
    </Card>
</template>
