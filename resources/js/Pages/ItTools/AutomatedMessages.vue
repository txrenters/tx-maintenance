<script setup>
import { computed, reactive, ref } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import Pagination from "@/Components/Pagination.vue";
import PaginationResultRange from "@/Components/PaginationResultRange.vue";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { Input } from "@/Components/ui/input";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from "@/Components/ui/tabs";
import MessageTemplateEditor from "./Partials/MessageTemplateEditor.vue";
import {
    Bot,
    Briefcase,
    Eye,
    Mail,
    MessageSquare,
    PenLine,
    RefreshCcw,
    ScrollText,
    Wrench,
} from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    logs: Object,
    stats: Object,
    filters: Object,
    automations: Object,
});

const isViewModalOpen = ref(false);
const selectedLog = ref(null);

// The active tab survives the full page reloads the log filters trigger via
// the URL hash, so applying a filter never bounces staff out of the editor.
const activeTab = ref(
    typeof window !== "undefined" && window.location.hash === "#templates"
        ? "templates"
        : "log"
);

const onTabChange = (tab) => {
    activeTab.value = tab;
    if (typeof window !== "undefined") {
        window.history.replaceState(
            null,
            "",
            tab === "templates" ? "#templates" : window.location.pathname + window.location.search
        );
    }
};

const filterForm = reactive({
    audience: props.filters?.audience || "all",
    channel: props.filters?.channel || "all",
    automation: props.filters?.automation || "all",
    work_order: props.filters?.work_order || "",
    date_from: props.filters?.date_from || "",
    date_to: props.filters?.date_to || "",
});

const logsData = computed(() => props.logs?.data || []);

const automationOptions = computed(() =>
    Object.entries(props.automations || {}).map(([key, label]) => ({
        key,
        label,
    }))
);

const audienceVariant = (audience) => {
    switch (audience) {
        case "owner":
            return "default";
        case "tenant":
            return "secondary";
        case "vendor":
            return "outline";
        default:
            return "outline";
    }
};

const audienceLabel = (audience) => {
    if (!audience) return "Unknown";
    return audience.charAt(0).toUpperCase() + audience.slice(1);
};

const applyFilters = () => {
    const query = {};

    if (filterForm.audience !== "all") query.audience = filterForm.audience;
    if (filterForm.channel !== "all") query.channel = filterForm.channel;
    if (filterForm.automation !== "all") query.automation = filterForm.automation;
    if (filterForm.work_order.trim() !== "")
        query.work_order = filterForm.work_order.trim();
    if (filterForm.date_from !== "") query.date_from = filterForm.date_from;
    if (filterForm.date_to !== "") query.date_to = filterForm.date_to;

    router.get(route("it-tools.automated-messages"), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const clearFilters = () => {
    filterForm.audience = "all";
    filterForm.channel = "all";
    filterForm.automation = "all";
    filterForm.work_order = "";
    filterForm.date_from = "";
    filterForm.date_to = "";

    router.get(route("it-tools.automated-messages"), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const openViewModal = (log) => {
    selectedLog.value = log;
    isViewModalOpen.value = true;
};

const formatDate = (dateString) => {
    if (!dateString) return "N/A";
    const date = new Date(dateString);

    return date.toLocaleString("en-US", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "short",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
    });
};

const truncateMessage = (message, length = 120) => {
    const value = String(message || "");
    if (value.length <= length) return value;
    return `${value.slice(0, length)}...`;
};

const statTiles = computed(() => [
    { label: "Total", value: props.stats?.total ?? 0 },
    { label: "Owners", value: props.stats?.owner ?? 0 },
    { label: "Tenants", value: props.stats?.tenant ?? 0 },
    { label: "Vendors", value: props.stats?.vendor ?? 0 },
    { label: "Texts", value: props.stats?.sms ?? 0 },
    { label: "Emails", value: props.stats?.email ?? 0 },
]);
</script>

<template>
    <Head :title="title || 'IT Tools — Automated Messages'" />

    <div class="p-4 md:p-6 space-y-6">
        <div class="flex flex-col gap-1">
            <h1 class="flex items-center gap-2 text-2xl font-semibold">
                <Bot class="h-6 w-6" />
                Automated Messages
            </h1>
            <p class="text-sm text-muted-foreground">
                Every automated text and email sent to owners, tenants, and
                vendors, with the work order and the exact date and time —
                plus the canned wording each automation sends, editable under
                Message Templates.
            </p>
        </div>

        <Tabs :model-value="activeTab" @update:model-value="onTabChange">
            <TabsList>
                <TabsTrigger value="log" class="gap-1.5">
                    <ScrollText class="h-4 w-4" />
                    Message Log
                </TabsTrigger>
                <TabsTrigger value="templates" class="gap-1.5">
                    <PenLine class="h-4 w-4" />
                    Message Templates
                </TabsTrigger>
            </TabsList>

            <TabsContent value="log" class="mt-4 space-y-6">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <Card v-for="tile in statTiles" :key="tile.label">
                <CardContent class="p-4">
                    <p class="text-xs uppercase text-muted-foreground">
                        {{ tile.label }}
                    </p>
                    <p class="text-2xl font-semibold">{{ tile.value }}</p>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardContent class="p-4">
                <div
                    class="grid gap-3 md:grid-cols-3 lg:grid-cols-6 items-end"
                >
                    <div class="space-y-1">
                        <label class="text-xs font-medium">Audience</label>
                        <Select v-model="filterForm.audience">
                            <SelectTrigger>
                                <SelectValue placeholder="All audiences" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="owner">Owner</SelectItem>
                                <SelectItem value="tenant">Tenant</SelectItem>
                                <SelectItem value="vendor">Vendor</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium">Channel</label>
                        <Select v-model="filterForm.channel">
                            <SelectTrigger>
                                <SelectValue placeholder="All channels" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="sms">Text (SMS)</SelectItem>
                                <SelectItem value="email">Email</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium">Automation</label>
                        <Select v-model="filterForm.automation">
                            <SelectTrigger>
                                <SelectValue placeholder="All automations" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem
                                    v-for="option in automationOptions"
                                    :key="option.key"
                                    :value="option.key"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium">Work Order #</label>
                        <Input
                            v-model="filterForm.work_order"
                            type="search"
                            placeholder="e.g. 43704"
                            @keyup.enter="applyFilters"
                        />
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium">From</label>
                        <Input v-model="filterForm.date_from" type="date" />
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium">To</label>
                        <Input v-model="filterForm.date_to" type="date" />
                    </div>
                </div>

                <div class="mt-3 flex justify-end gap-2">
                    <Button variant="outline" class="gap-2" @click="clearFilters">
                        <RefreshCcw class="h-4 w-4" />
                        Reset
                    </Button>
                    <Button @click="applyFilters">Apply Filters</Button>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="pb-2">
                <div class="flex items-center justify-between gap-2">
                    <CardTitle class="text-lg">Log</CardTitle>
                    <span class="text-xs text-muted-foreground">
                        {{ logs?.total || 0 }} result(s)
                    </span>
                </div>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date &amp; Time</TableHead>
                            <TableHead>Audience</TableHead>
                            <TableHead>Channel</TableHead>
                            <TableHead>Automation</TableHead>
                            <TableHead>Recipient</TableHead>
                            <TableHead>Work Order</TableHead>
                            <TableHead>Message</TableHead>
                            <TableHead class="w-[80px]"></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="log in logsData" :key="log.id">
                            <TableCell
                                class="whitespace-nowrap text-sm text-muted-foreground"
                            >
                                {{ formatDate(log.created_at) }}
                            </TableCell>
                            <TableCell>
                                <Badge :variant="audienceVariant(log.audience)">
                                    {{ audienceLabel(log.audience) }}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <span
                                    class="inline-flex items-center gap-1 text-sm"
                                >
                                    <Mail
                                        v-if="log.channel === 'email'"
                                        class="h-3.5 w-3.5 text-muted-foreground"
                                    />
                                    <MessageSquare
                                        v-else
                                        class="h-3.5 w-3.5 text-muted-foreground"
                                    />
                                    {{ log.channel === "email" ? "Email" : "Text" }}
                                </span>
                            </TableCell>
                            <TableCell class="text-sm">
                                {{ log.automation_label }}
                            </TableCell>
                            <TableCell class="text-sm">
                                {{ log.recipient || "N/A" }}
                            </TableCell>
                            <TableCell>
                                <Link
                                    v-if="log.work_order_id"
                                    :href="
                                        route(
                                            'work_orders.details',
                                            log.work_order_id
                                        )
                                    "
                                    class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-800"
                                >
                                    <Wrench class="h-3.5 w-3.5" />
                                    {{
                                        log.work_order_no ||
                                        `WO #${log.work_order_id}`
                                    }}
                                </Link>
                                <span
                                    v-else-if="log.job_number || log.jobber_id"
                                    class="inline-flex items-center gap-1 text-sm text-emerald-700"
                                >
                                    <Briefcase class="h-3.5 w-3.5" />
                                    Job #{{ log.job_number || log.jobber_id }}
                                </span>
                                <span
                                    v-else
                                    class="text-sm text-muted-foreground"
                                    >—</span
                                >
                            </TableCell>
                            <TableCell>
                                <p
                                    class="max-w-[360px] whitespace-pre-wrap break-words text-sm"
                                >
                                    {{ truncateMessage(log.message) || "—" }}
                                </p>
                            </TableCell>
                            <TableCell>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="gap-1"
                                    @click="openViewModal(log)"
                                >
                                    <Eye class="h-4 w-4" />
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div
                    v-if="logsData.length === 0"
                    class="flex flex-col items-center justify-center py-12 text-center"
                >
                    <Bot class="mb-3 h-10 w-10 text-muted-foreground" />
                    <p class="text-sm font-medium">No automated messages yet</p>
                    <p class="text-xs text-muted-foreground">
                        Sends are recorded here from the moment this feature is
                        deployed. Try adjusting the filters.
                    </p>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <PaginationResultRange :data="logs" />
                    <Pagination :pagination="logs?.links || []" />
                </div>
            </CardContent>
        </Card>
            </TabsContent>

            <TabsContent value="templates" class="mt-4">
                <MessageTemplateEditor />
            </TabsContent>
        </Tabs>

        <Dialog v-model:open="isViewModalOpen">
            <DialogContent class="max-w-2xl">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Eye class="h-4 w-4" />
                        Automated Message
                    </DialogTitle>
                    <DialogDescription>
                        Full details of this automated send.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="selectedLog" class="space-y-5">
                    <div class="grid gap-4 rounded-md border p-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                Sent At
                            </p>
                            <p class="text-sm font-medium">
                                {{ formatDate(selectedLog.created_at) }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                Automation
                            </p>
                            <p class="text-sm font-medium">
                                {{ selectedLog.automation_label }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                Audience / Channel
                            </p>
                            <div class="flex items-center gap-2">
                                <Badge
                                    :variant="
                                        audienceVariant(selectedLog.audience)
                                    "
                                >
                                    {{ audienceLabel(selectedLog.audience) }}
                                </Badge>
                                <span class="text-sm">
                                    {{
                                        selectedLog.channel === "email"
                                            ? "Email"
                                            : "Text (SMS)"
                                    }}
                                </span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                Recipient
                            </p>
                            <p class="text-sm">
                                {{ selectedLog.recipient || "N/A" }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-md border p-4">
                        <p class="mb-2 text-xs uppercase text-muted-foreground">
                            Message
                        </p>
                        <p class="whitespace-pre-wrap break-words text-sm">
                            {{ selectedLog.message || "No text content" }}
                        </p>
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="isViewModalOpen = false"
                        >Close</Button
                    >
                    <Button
                        v-if="selectedLog?.work_order_id"
                        as-child
                    >
                        <Link
                            :href="
                                route(
                                    'work_orders.details',
                                    selectedLog.work_order_id
                                )
                            "
                        >
                            <Wrench class="mr-1 h-4 w-4" />
                            Open Work Order
                        </Link>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
