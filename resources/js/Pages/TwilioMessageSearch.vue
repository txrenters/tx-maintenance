<script setup>
import { computed, reactive, ref, watch } from "vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import {
    AlertCircle,
    AlertTriangle,
    Briefcase,
    CheckCircle2,
    Copy,
    ExternalLink,
    Eye,
    Loader2,
    MessageSquare,
    RefreshCcw,
    Search,
    Wrench,
} from "lucide-vue-next";
import { describeTwilioError } from "@/utils/twilioErrorCatalog.js";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    filters: Object,
    messages: {
        type: Array,
        default: () => [],
    },
    error: {
        type: String,
        default: null,
    },
    hasFilters: Boolean,
    resultLimit: {
        type: Number,
        default: 100,
    },
    ourTwilioNumbers: {
        type: Array,
        default: () => [],
    },
});

const { toast } = useToast();
const page = usePage();

const searchUrl = route("twilio_messages.search");

const filterForm = reactive({
    phone: props.filters?.phone || "",
    date_from: props.filters?.date_from || "",
    date_to: props.filters?.date_to || "",
});

const isSearching = ref(false);
const syncingSid = ref(null);
const isViewModalOpen = ref(false);
const selectedMessage = ref(null);

const messagesData = computed(() => props.messages || []);
const activeFiltersCount = computed(() =>
    [
        filterForm.phone.trim() !== "",
        filterForm.date_from !== "",
        filterForm.date_to !== "",
    ].filter(Boolean).length
);

watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;
        if (flash.success) {
            toast({ title: "Success", description: flash.success });
        }
        if (flash.warning) {
            toast({
                variant: "destructive",
                title: "Heads up",
                description: flash.warning,
            });
        }
        if (flash.error) {
            toast({
                variant: "destructive",
                title: "Error",
                description: flash.error,
            });
        }
    },
    { deep: true, immediate: true }
);

const submitSearch = () => {
    const query = {};
    if (filterForm.phone.trim() !== "") query.phone = filterForm.phone.trim();
    if (filterForm.date_from !== "") query.date_from = filterForm.date_from;
    if (filterForm.date_to !== "") query.date_to = filterForm.date_to;

    isSearching.value = true;
    router.get(searchUrl, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            isSearching.value = false;
        },
    });
};

const resetSearch = () => {
    filterForm.phone = "";
    filterForm.date_from = "";
    filterForm.date_to = "";
    router.get(searchUrl, {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const openViewModal = (message) => {
    selectedMessage.value = message;
    isViewModalOpen.value = true;
};

const closeViewModal = () => {
    isViewModalOpen.value = false;
    selectedMessage.value = null;
};

const syncStatus = (sid) => {
    if (!sid) return;
    syncingSid.value = sid;
    router.post(
        route("twilio_messages.sync_status"),
        { sid },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                syncingSid.value = null;
            },
        }
    );
};

const copyToClipboard = async (value) => {
    if (!value) return;
    try {
        await navigator.clipboard.writeText(value);
        toast({ title: "Copied", description: value });
    } catch (e) {
        toast({
            variant: "destructive",
            title: "Copy failed",
            description: "Clipboard access was denied.",
        });
    }
};

const statusLabel = (status) => {
    if (!status) return "Unknown";
    return String(status)
        .replace(/_/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
};

const statusVariant = (status) => {
    switch (String(status || "").toLowerCase()) {
        case "delivered":
        case "received":
            return "secondary";
        case "sent":
        case "queued":
        case "accepted":
        case "sending":
        case "receiving":
            return "default";
        case "failed":
        case "undelivered":
        case "canceled":
            return "destructive";
        default:
            return "outline";
    }
};

const directionLabel = (direction) => {
    if (!direction) return "—";
    return direction.startsWith("inbound") ? "Inbound" : "Outbound";
};

const formatDate = (value) => {
    if (!value) return "—";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString("en-US", {
        timeZone: "America/Chicago",
        year: "numeric",
        month: "short",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    });
};

const truncate = (text, length = 80) => {
    const value = String(text || "");
    if (value.length <= length) return value;
    return `${value.slice(0, length)}…`;
};

const errorExplanation = describeTwilioError;

const errorDocsUrl = (code) => {
    if (!code) return null;
    return `https://www.twilio.com/docs/api/errors/${String(code).trim()}`;
};

const localMatchLabel = (message) => {
    const match = message?.local_match;
    if (!match) return null;
    if (match.source === "work_order") {
        return match.work_order_id
            ? `WO #${match.work_order_id}`
            : "Work Order";
    }
    if (match.source === "job") {
        return match.jobber_id ? `Job #${match.jobber_id}` : "Job Text";
    }
    return null;
};
</script>

<template>
    <Head :title="title || 'Search Twilio'" />

    <div class="space-y-6">
        <Card>
            <CardHeader class="pb-3">
                <CardTitle class="flex items-center gap-2 text-lg">
                    <Search class="h-5 w-5" />
                    Search Twilio Messages
                </CardTitle>
                <p class="text-sm text-muted-foreground">
                    Query Twilio directly to verify whether a message was sent.
                    Results come from Twilio's API — not the local database.
                </p>
            </CardHeader>
            <CardContent>
                <form
                    class="grid gap-4 md:grid-cols-4"
                    @submit.prevent="submitSearch"
                >
                    <div class="space-y-2 md:col-span-2">
                        <label class="text-sm font-medium">Phone Number</label>
                        <Input
                            v-model="filterForm.phone"
                            placeholder="+15125551234 or 5125551234"
                            autocomplete="off"
                        />
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">From Date</label>
                        <Input v-model="filterForm.date_from" type="date" />
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">To Date</label>
                        <Input v-model="filterForm.date_to" type="date" />
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 md:col-span-4"
                    >
                        <Badge
                            v-if="activeFiltersCount"
                            variant="secondary"
                            class="mr-auto"
                        >
                            {{ activeFiltersCount }} active filter(s)
                        </Badge>
                        <Button
                            type="button"
                            variant="outline"
                            class="gap-2"
                            :disabled="isSearching"
                            @click="resetSearch"
                        >
                            <RefreshCcw class="h-4 w-4" />
                            Reset
                        </Button>
                        <Button
                            type="submit"
                            class="gap-2"
                            :disabled="isSearching || activeFiltersCount === 0"
                        >
                            <Loader2
                                v-if="isSearching"
                                class="h-4 w-4 animate-spin"
                            />
                            <Search v-else class="h-4 w-4" />
                            Search
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <div
            v-if="error"
            class="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive"
        >
            <AlertCircle class="mt-0.5 h-4 w-4" />
            <div>
                <p class="font-medium">Search failed</p>
                <p>{{ error }}</p>
            </div>
        </div>

        <Card v-if="hasFilters">
            <CardHeader class="pb-2">
                <div class="flex items-center justify-between gap-2">
                    <CardTitle class="text-lg">Results</CardTitle>
                    <span class="text-xs text-muted-foreground">
                        {{ messagesData.length }} message(s)
                        <span v-if="messagesData.length >= resultLimit">
                            • showing latest {{ resultLimit }} — narrow filters
                            for older results
                        </span>
                    </span>
                </div>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-[180px]">Date Sent</TableHead>
                            <TableHead>Direction</TableHead>
                            <TableHead>From</TableHead>
                            <TableHead>To</TableHead>
                            <TableHead>Body</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="w-[200px] text-right">
                                Actions
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="message in messagesData"
                            :key="message.sid"
                            class="cursor-pointer"
                            @click="openViewModal(message)"
                        >
                            <TableCell class="text-sm text-muted-foreground">
                                {{ formatDate(message.date_sent || message.date_created) }}
                            </TableCell>
                            <TableCell>
                                <Badge variant="outline">
                                    {{ directionLabel(message.direction) }}
                                </Badge>
                            </TableCell>
                            <TableCell class="font-mono text-xs">
                                {{ message.from || "—" }}
                            </TableCell>
                            <TableCell class="font-mono text-xs">
                                {{ message.to || "—" }}
                            </TableCell>
                            <TableCell>
                                <p
                                    class="max-w-[280px] whitespace-pre-wrap break-words text-sm"
                                >
                                    {{ truncate(message.body) || "(no body)" }}
                                </p>
                            </TableCell>
                            <TableCell>
                                <div class="flex flex-col items-start gap-1">
                                    <Badge :variant="statusVariant(message.status)">
                                        {{ statusLabel(message.status) }}
                                    </Badge>
                                    <p
                                        v-if="message.error_code"
                                        class="text-xs text-muted-foreground"
                                    >
                                        Code: {{ message.error_code }}
                                    </p>
                                    <p
                                        v-if="message.error_message"
                                        class="max-w-[220px] text-xs text-red-600 line-clamp-2"
                                    >
                                        {{ message.error_message }}
                                    </p>
                                    <p
                                        v-if="errorExplanation(message.error_code)"
                                        class="max-w-[220px] text-xs text-amber-700 line-clamp-2"
                                    >
                                        {{ errorExplanation(message.error_code) }}
                                    </p>
                                </div>
                            </TableCell>
                            <TableCell class="text-right" @click.stop>
                                <div class="flex items-center justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="gap-1"
                                        @click="openViewModal(message)"
                                    >
                                        <Eye class="h-4 w-4" />
                                        View
                                    </Button>
                                    <Button
                                        v-if="message.local_match || message.importable"
                                        variant="outline"
                                        size="sm"
                                        class="gap-1"
                                        :disabled="syncingSid === message.sid"
                                        :title="
                                            message.local_match
                                                ? 'Update Twilio status on the local record'
                                                : 'Import this missed inbound message into the local database'
                                        "
                                        @click="syncStatus(message.sid)"
                                    >
                                        <Loader2
                                            v-if="syncingSid === message.sid"
                                            class="h-4 w-4 animate-spin"
                                        />
                                        <RefreshCcw v-else class="h-4 w-4" />
                                        {{ message.local_match ? "Sync" : "Import" }}
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <div
                    v-if="messagesData.length === 0 && !error"
                    class="flex flex-col items-center justify-center py-12 text-center"
                >
                    <MessageSquare
                        class="mb-3 h-10 w-10 text-muted-foreground"
                    />
                    <p class="text-sm font-medium">No Twilio messages found</p>
                    <p class="text-xs text-muted-foreground">
                        Try a different SID, phone number, or date range.
                    </p>
                </div>
            </CardContent>
        </Card>

        <Card v-else>
            <CardContent class="py-10">
                <div class="flex flex-col items-center text-center">
                    <Search class="mb-3 h-10 w-10 text-muted-foreground" />
                    <p class="text-sm font-medium">
                        Enter a SID, phone number, or date range to search
                        Twilio.
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        This page queries Twilio's API directly. No local
                        records are read or written until you click Sync.
                    </p>
                </div>
            </CardContent>
        </Card>

        <Dialog v-model:open="isViewModalOpen">
            <DialogContent
                class="flex max-h-[90vh] max-w-3xl flex-col overflow-hidden"
            >
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Eye class="h-4 w-4" />
                        Twilio Message Details
                    </DialogTitle>
                    <DialogDescription>
                        Full message data returned by Twilio's API.
                    </DialogDescription>
                </DialogHeader>

                <div
                    v-if="selectedMessage"
                    class="-mx-6 flex-1 space-y-5 overflow-y-auto px-6 py-1"
                >
                    <div class="grid gap-4 rounded-md border p-4 md:grid-cols-2">
                        <div class="space-y-1 md:col-span-2">
                            <p class="text-xs uppercase text-muted-foreground">
                                Message SID
                            </p>
                            <div class="flex items-center gap-2">
                                <p class="font-mono text-sm">
                                    {{ selectedMessage.sid }}
                                </p>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-6 px-2"
                                    @click="copyToClipboard(selectedMessage.sid)"
                                >
                                    <Copy class="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase text-muted-foreground">
                                Status
                            </p>
                            <Badge
                                :variant="statusVariant(selectedMessage.status)"
                            >
                                {{ statusLabel(selectedMessage.status) }}
                            </Badge>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase text-muted-foreground">
                                Direction
                            </p>
                            <p class="text-sm">
                                {{ directionLabel(selectedMessage.direction) }}
                            </p>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase text-muted-foreground">
                                From
                            </p>
                            <p class="font-mono text-sm">
                                {{ selectedMessage.from || "—" }}
                            </p>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase text-muted-foreground">
                                To
                            </p>
                            <p class="font-mono text-sm">
                                {{ selectedMessage.to || "—" }}
                            </p>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase text-muted-foreground">
                                Date Sent
                            </p>
                            <p class="text-sm">
                                {{ formatDate(selectedMessage.date_sent) }}
                            </p>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase text-muted-foreground">
                                Date Created
                            </p>
                            <p class="text-sm">
                                {{ formatDate(selectedMessage.date_created) }}
                            </p>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase text-muted-foreground">
                                Date Updated
                            </p>
                            <p class="text-sm">
                                {{ formatDate(selectedMessage.date_updated) }}
                            </p>
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs uppercase text-muted-foreground">
                                Media
                            </p>
                            <p class="text-sm">
                                {{ selectedMessage.num_media || "0" }}
                            </p>
                        </div>
                    </div>

                    <div class="rounded-md border p-4">
                        <p class="mb-2 text-xs uppercase text-muted-foreground">
                            Message Body
                        </p>
                        <p
                            class="whitespace-pre-wrap break-words text-sm"
                        >
                            {{ selectedMessage.body || "(empty)" }}
                        </p>
                    </div>

                    <div
                        v-if="selectedMessage.error_code || selectedMessage.error_message"
                        class="space-y-2 rounded-md border border-destructive/40 bg-destructive/5 p-4"
                    >
                        <p
                            class="flex items-center gap-1 text-xs uppercase text-destructive"
                        >
                            <AlertCircle class="h-3.5 w-3.5" />
                            Twilio Error
                        </p>
                        <p
                            v-if="selectedMessage.error_code"
                            class="text-xs text-muted-foreground"
                        >
                            Code: {{ selectedMessage.error_code }}
                        </p>
                        <p class="text-sm text-destructive">
                            {{ selectedMessage.error_message || "—" }}
                        </p>
                        <div
                            v-if="errorExplanation(selectedMessage.error_code)"
                            class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
                        >
                            <p class="mb-1 text-xs font-semibold uppercase">
                                What this means
                            </p>
                            <p>
                                {{ errorExplanation(selectedMessage.error_code) }}
                            </p>
                        </div>
                        <a
                            v-if="errorDocsUrl(selectedMessage.error_code)"
                            :href="errorDocsUrl(selectedMessage.error_code)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-xs text-blue-600 hover:underline"
                        >
                            <ExternalLink class="h-3 w-3" />
                            Twilio docs for code
                            {{ selectedMessage.error_code }}
                        </a>
                    </div>

                    <div
                        v-if="!selectedMessage.local_match && selectedMessage.importable"
                        class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
                    >
                        <p class="mb-1 text-xs font-semibold uppercase">
                            Not in local database
                        </p>
                        <p>
                            This inbound message was sent to one of our Twilio
                            numbers but never stored locally. Click
                            <strong>Import to Local</strong> to attach it to the
                            most recent work order conversation for
                            {{ selectedMessage.from || "this sender" }}.
                        </p>
                    </div>

                    <div
                        v-if="selectedMessage.local_match"
                        class="rounded-md border p-4"
                    >
                        <p class="mb-2 text-xs uppercase text-muted-foreground">
                            Local Record Match
                        </p>
                        <div class="space-y-1 text-sm">
                            <p>
                                <span class="font-medium">Source:</span>
                                {{
                                    selectedMessage.local_match.source ===
                                    "work_order"
                                        ? "Work Order Conversation"
                                        : "Job Text Message"
                                }}
                            </p>
                            <p>
                                <span class="font-medium">Local status:</span>
                                {{
                                    statusLabel(
                                        selectedMessage.local_match.local_status
                                    )
                                }}
                            </p>
                            <p
                                v-if="selectedMessage.local_status_diff"
                                class="flex items-center gap-1 text-amber-600"
                            >
                                <AlertTriangle class="h-3.5 w-3.5" />
                                Local status differs from Twilio's status. Click
                                Sync to update.
                            </p>
                        </div>
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="closeViewModal">
                        Close
                    </Button>
                    <Button
                        v-if="selectedMessage?.local_match || selectedMessage?.importable"
                        class="gap-2"
                        :disabled="syncingSid === selectedMessage?.sid"
                        @click="syncStatus(selectedMessage.sid)"
                    >
                        <Loader2
                            v-if="syncingSid === selectedMessage?.sid"
                            class="h-4 w-4 animate-spin"
                        />
                        <RefreshCcw v-else class="h-4 w-4" />
                        {{
                            selectedMessage?.local_match
                                ? "Sync Status to Local"
                                : "Import to Local"
                        }}
                    </Button>
                    <Button
                        v-if="
                            selectedMessage?.local_match?.source === 'work_order' &&
                            selectedMessage?.local_match?.work_order_id
                        "
                        as-child
                    >
                        <Link
                            :href="
                                route(
                                    'work_orders.details',
                                    selectedMessage.local_match.work_order_id
                                )
                            "
                            class="inline-flex items-center gap-1"
                        >
                            <Wrench class="h-4 w-4" />
                            Open Work Order
                        </Link>
                    </Button>
                    <Button
                        v-if="
                            selectedMessage?.local_match?.source === 'job' &&
                            selectedMessage?.local_match?.jobber_id
                        "
                        as-child
                    >
                        <Link
                            :href="
                                route(
                                    'jobber.jobDetails',
                                    selectedMessage.local_match.jobber_id
                                )
                            "
                            class="inline-flex items-center gap-1"
                        >
                            <Briefcase class="h-4 w-4" />
                            Open Job
                        </Link>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
