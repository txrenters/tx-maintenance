<script setup>
import { computed, ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import { Button } from "@/Components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import { Badge } from "@/Components/ui/badge";
import {
    Sparkles,
    Wrench,
    History,
    RefreshCw,
    CheckCircle2,
    Star,
    FileText,
    ArrowRight,
    AlertTriangle,
    ShieldCheck,
    Building2,
    ExternalLink,
} from "lucide-vue-next";

const props = defineProps({
    isLoading: Boolean,
    isGenerating: Boolean,
    recommendation: Object,
});

const emit = defineEmits(["generate", "assign"]);

// Auto-generate on first open: when the parent's fetch finishes and there is
// no stored recommendation yet, kick off generation without requiring a
// click. Armed once per fetch cycle so a failed generation does not loop —
// the button then acts as a manual retry.
const autoGenerateArmed = ref(true);

watch(
    () => props.isLoading,
    (loading) => {
        if (loading) autoGenerateArmed.value = true;
    },
);

watch(
    [
        () => props.isLoading,
        () => props.isGenerating,
        () => props.recommendation,
    ],
    ([loading, generating, recommendation]) => {
        if (!loading && !generating && !recommendation && autoGenerateArmed.value) {
            autoGenerateArmed.value = false;
            emit("generate");
        }
    },
    { immediate: true },
);

const matchedWorkOrders = computed(
    () => props.recommendation?.matched_work_orders ?? [],
);
const databaseAlternates = computed(
    () => props.recommendation?.alternate_vendors?.database ?? [],
);
const fallbackAlternates = computed(
    () => props.recommendation?.alternate_vendors?.fallback ?? [],
);
const recommendedVendor = computed(
    () => props.recommendation?.recommended_vendor ?? null,
);

// --- Previous work orders at this property ---

// Computed fresh by the server on every read: every other work order at the
// same property, newest first, capped with the total reported alongside.
const propertyHistory = computed(
    () => props.recommendation?.property_history ?? null,
);
const propertyHistoryItems = computed(() => propertyHistory.value?.items ?? []);
const propertyHistoryTotal = computed(() =>
    Number(propertyHistory.value?.total ?? 0),
);
const propertyHistoryHiddenCount = computed(() =>
    Math.max(0, propertyHistoryTotal.value - propertyHistoryItems.value.length),
);

// Mirrors WorkOrder::CLOSED_STATUSES.
const CLOSED_STATUSES = ["Closed", "Canceled By Tenant"];
const isClosed = (item) => CLOSED_STATUSES.includes(item.status);

const historyDetailLine = (item) =>
    [item.category, item.type, item.service_status].filter(Boolean).join(" · ");

// The property page behind "View all" is staff-only.
const page = usePage();
const isStaff = computed(() =>
    (page.props.auth?.user?.roles ?? []).some((role) =>
        ["admin", "woc", "accounting"].includes(role),
    ),
);

const VENDOR_SOURCE_LABELS = {
    repeat_issue: { label: "Repeat Issue", variant: "default" },
    owner_preferred: { label: "Owner Preferred", variant: "default" },
    building_history: { label: "Building History", variant: "secondary" },
    cross_site_history: { label: "Cross-Site History", variant: "secondary" },
    category_match: { label: "Category Match", variant: "outline" },
    fallback: { label: "Fallback", variant: "outline" },
};

const vendorSourceBadge = computed(() => {
    const source = props.recommendation?.vendor_source;
    return source ? VENDOR_SOURCE_LABELS[source] ?? null : null;
});

const ownerPreferredName = computed(
    () => props.recommendation?.classification?.owner_preferred_name ?? null,
);
const maintenanceNotice = computed(
    () => props.recommendation?.classification?.maintenance_notice ?? null,
);

const confidence = computed(() => Number(props.recommendation?.confidence ?? 0));

const confidenceTone = computed(() => {
    const c = confidence.value;
    if (c >= 75) return "bg-emerald-500";
    if (c >= 50) return "bg-amber-500";
    return "bg-red-500";
});

// --- Emergency assessment ---

const hasEmergencyAssessment = computed(
    () =>
        props.recommendation?.is_emergency !== null &&
        props.recommendation?.is_emergency !== undefined,
);

const aiSaysEmergency = computed(() => Boolean(props.recommendation?.is_emergency));
const emergencyCategory = computed(() => props.recommendation?.emergency_category ?? null);
const emergencyReason = computed(() => props.recommendation?.emergency_reason ?? null);
const emergencyConfidence = computed(() =>
    Number(props.recommendation?.emergency_confidence ?? 0),
);

// The work order's current flag: null only for legacy work orders classified
// before automatic labeling existed.
const workOrderEmergency = computed(() => {
    const value = props.recommendation?.work_order?.is_emergency;
    if (value === null || value === undefined) return null;
    return Boolean(Number(value));
});

const emergencyClassifiedBy = computed(() => {
    if (workOrderEmergency.value === null) return null;
    return props.recommendation?.emergency_auto_applied &&
        workOrderEmergency.value === aiSaysEmergency.value
        ? "ai"
        : "staff";
});

// --- Tenant easy fix ---

// What the AI (or the keyword fallback) thinks, and what the intake
// automation actually decided (stored on the work order). Shown whenever
// either says something, so staff can spot the ones the keywords missed.
const aiEasyFixKey = computed(
    () => props.recommendation?.classification?.easy_fix_key ?? null,
);
const aiSaysEasyFix = computed(() =>
    Boolean(props.recommendation?.classification?.is_tenant_easy_fix),
);
const intakeEasyFixKey = computed(
    () => props.recommendation?.work_order?.easy_fix_key ?? null,
);
// The AI's reading of the tenant's reply after the how-to text: a confident
// "it is fixed" labels the work order Ready to close for a coordinator.
const easyFixReply = computed(
    () => props.recommendation?.easy_fix_reply ?? null,
);
const easyFixReplyConfidence = computed(() =>
    Number(easyFixReply.value?.confidence ?? 0),
);
const easyFixReplyVerdict = computed(() => {
    const reply = easyFixReply.value;
    if (!reply) return null;
    if (reply.ready) return "the tenant says it is fixed";
    if (reply.resolved) return "the tenant may be saying it is fixed, but not confidently enough to label";
    return "the tenant says it is not fixed";
});
const hasEasyFixAssessment = computed(
    () =>
        aiSaysEasyFix.value ||
        Boolean(intakeEasyFixKey.value) ||
        Boolean(easyFixReply.value),
);
const humanizeKey = (key) => (key ? String(key).replace(/_/g, " ") : "");
const intakeEasyFixLabel = computed(() => {
    const key = intakeEasyFixKey.value;
    if (!key) return null;
    return `Tenant easy fix: ${humanizeKey(key)}`;
});
const easyFixDisagrees = computed(
    () =>
        aiSaysEasyFix.value &&
        !intakeEasyFixKey.value &&
        Boolean(aiEasyFixKey.value),
);

// --- Repeat issue ---

// Whether the engine flagged this as a repeat of prior work at the same
// property, and how many prior matches it found within the lookback window.
const isRepeatIssue = computed(() => {
    const value = props.recommendation?.work_order?.is_repeat_issue;
    return value === null || value === undefined ? false : Boolean(Number(value));
});
const repeatCount = computed(() =>
    Number(props.recommendation?.work_order?.repeat_count ?? 0),
);

// The current work order is the (repeatCount + 1)th occurrence at this
// property. Render that as a correct English ordinal ("2nd time", "3rd time"…).
const occurrenceLabel = computed(() => {
    const n = repeatCount.value + 1;
    const suffixes = ["th", "st", "nd", "rd"];
    const v = n % 100;
    return `${n}${suffixes[(v - 20) % 10] || suffixes[v] || suffixes[0]} time`;
});

const formatDate = (date) => {
    if (!date) return "Unknown";
    return new Date(date).toLocaleDateString();
};
</script>

<template>
    <div class="px-6 pb-6">
        <div class="space-y-5">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-primary">Recommendation Center</p>
                    <p class="text-sm text-muted-foreground">
                        Review the issue summary, similar history, and best vendor match.
                    </p>
                </div>

                <Button
                    variant="outline"
                    size="sm"
                    :disabled="isGenerating"
                    @click="$emit('generate')"
                >
                    <RefreshCw
                        :class="{ 'animate-spin': isGenerating }"
                        class="mr-2 h-4 w-4"
                    />
                    {{ recommendation ? "Refresh" : "Retry" }}
                </Button>
            </div>

            <!-- Generating state: shown while the AI analyzes the work order -->
            <div
                v-if="!recommendation && (isGenerating || isLoading)"
                class="animate-pulse rounded-lg border border-dashed px-6 py-12 text-center"
            >
                <Sparkles class="mx-auto mb-3 h-8 w-8 text-primary" />
                <p class="font-medium">
                    {{ isGenerating ? "Analyzing this work order with AI…" : "Loading recommendation…" }}
                </p>
                <p class="text-sm text-muted-foreground">
                    Classifying the issue, assessing emergency status, and ranking vendors.
                </p>
            </div>

            <!-- Retry state: auto-generation did not produce a recommendation -->
            <div
                v-else-if="!recommendation"
                class="rounded-lg border border-dashed px-6 py-12 text-center"
            >
                <Sparkles class="mx-auto mb-3 h-8 w-8 text-muted-foreground" />
                <p class="font-medium">The recommendation could not be generated.</p>
                <p class="text-sm text-muted-foreground">
                    Something went wrong while analyzing this work order. Please retry.
                </p>
                <Button class="mt-4" variant="outline" @click="$emit('generate')">
                    <RefreshCw class="mr-2 h-4 w-4" />
                    Retry
                </Button>
            </div>

            <template v-else-if="recommendation">
                <!-- Emergency Assessment -->
                <Card
                    v-if="hasEmergencyAssessment"
                    class="overflow-hidden border-2"
                    :class="
                        aiSaysEmergency
                            ? 'border-red-500/50 bg-red-500/5'
                            : 'border-emerald-500/40 bg-emerald-500/5'
                    "
                >
                    <CardContent class="space-y-3 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div
                                class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider"
                                :class="aiSaysEmergency ? 'text-red-600' : 'text-emerald-600'"
                            >
                                <AlertTriangle v-if="aiSaysEmergency" class="h-3.5 w-3.5" />
                                <ShieldCheck v-else class="h-3.5 w-3.5" />
                                Emergency Assessment
                            </div>
                            <span class="text-xs tabular-nums text-muted-foreground">
                                {{ emergencyConfidence }}% confidence
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Badge
                                :class="
                                    aiSaysEmergency
                                        ? 'bg-red-600 text-white hover:bg-red-600'
                                        : 'bg-emerald-600 text-white hover:bg-emerald-600'
                                "
                            >
                                {{ aiSaysEmergency ? "Emergency" : "Non-emergency" }}
                            </Badge>
                            <Badge v-if="emergencyCategory" variant="outline">
                                {{ emergencyCategory }}
                            </Badge>
                        </div>

                        <p
                            v-if="emergencyReason"
                            class="border-l-2 pl-3 text-sm italic leading-6 text-muted-foreground"
                            :class="aiSaysEmergency ? 'border-red-500/40' : 'border-emerald-500/40'"
                        >
                            {{ emergencyReason }}
                        </p>

                        <!-- Current status + who set it -->
                        <p
                            v-if="workOrderEmergency !== null"
                            class="border-t pt-2 text-xs text-muted-foreground"
                        >
                            Work order is marked
                            <span
                                class="font-semibold"
                                :class="workOrderEmergency ? 'text-red-600' : 'text-emerald-600'"
                            >
                                {{ workOrderEmergency ? "Emergency" : "Non-emergency" }}
                            </span>
                            ·
                            {{
                                emergencyClassifiedBy === "ai"
                                    ? "applied automatically by AI"
                                    : "set by staff"
                            }}
                        </p>
                    </CardContent>
                </Card>

                <!-- Tenant easy fix -->
                <Card
                    v-if="hasEasyFixAssessment"
                    class="overflow-hidden border-2 border-sky-500/50 bg-sky-500/5"
                >
                    <CardContent class="space-y-2 p-5">
                        <div
                            class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-sky-600"
                        >
                            <Wrench class="h-3.5 w-3.5" />
                            Tenant Easy Fix
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Badge
                                v-if="intakeEasyFixLabel"
                                class="bg-sky-600 text-white hover:bg-sky-600"
                            >
                                {{ intakeEasyFixLabel }}
                            </Badge>
                            <Badge v-if="aiSaysEasyFix" variant="outline">
                                AI: easy fix{{ aiEasyFixKey ? ` (${humanizeKey(aiEasyFixKey)})` : "" }}
                            </Badge>
                            <Badge
                                v-if="easyFixReply?.ready"
                                class="gap-1 bg-emerald-600 text-white hover:bg-emerald-600"
                                data-easy-fix-ready
                            >
                                <CheckCircle2 class="h-3 w-3" />
                                Ready to close · {{ easyFixReplyConfidence }}%
                            </Badge>
                        </div>

                        <p
                            v-if="easyFixReply"
                            class="text-sm leading-6 text-muted-foreground"
                            data-easy-fix-reply
                        >
                            Tenant's reply read by AI: {{ easyFixReplyVerdict }}
                            <span class="tabular-nums">({{ easyFixReplyConfidence }}% confidence)</span>.
                            {{ easyFixReply.reason }}
                            <template v-if="easyFixReply.ready">
                                Nothing closes on its own: close it out when you agree.
                            </template>
                        </p>

                        <p
                            v-if="intakeEasyFixLabel"
                            class="text-sm leading-6 text-muted-foreground"
                        >
                            Decided at intake: the tenant was sent the handbook's
                            how-to instead of the usual "request received" text
                            (when the easy-fix texts are switched on), and the
                            owner was told. Whether the item is a non-realty
                            appliance under the lease is your call from here.
                        </p>
                        <p
                            v-else-if="easyFixDisagrees"
                            class="text-sm leading-6 text-muted-foreground"
                        >
                            AI suggests this is a tenant easy fix, but the intake
                            rules did not match it, so the tenant got the usual
                            text. Worth a look.
                        </p>
                    </CardContent>
                </Card>

                <!-- Repeat Issue -->
                <Card
                    v-if="isRepeatIssue"
                    class="overflow-hidden border-2 border-amber-500/50 bg-amber-500/5"
                >
                    <CardContent class="space-y-2 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div
                                class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-amber-600"
                            >
                                <History class="h-3.5 w-3.5" />
                                Repeat Issue
                            </div>
                            <Badge class="bg-amber-600 text-white hover:bg-amber-600">
                                {{ occurrenceLabel }}
                            </Badge>
                        </div>
                        <p class="text-sm leading-6 text-muted-foreground">
                            This looks like a repeat of prior maintenance at this
                            property —
                            <span class="font-semibold text-foreground">
                                {{ repeatCount }} similar
                                {{ repeatCount === 1 ? "job" : "jobs" }}
                            </span>
                            found in the last 12 months. See the matching history
                            below.
                        </p>
                    </CardContent>
                </Card>

                <!-- HERO: Recommended Vendor -->
                <Card
                    class="overflow-hidden border-2 border-primary/40 bg-gradient-to-br from-primary/5 via-background to-background shadow-sm"
                >
                    <CardContent class="space-y-4 p-6">
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-primary">
                            <Star class="h-3.5 w-3.5 fill-primary text-primary" />
                            Recommended Vendor
                        </div>

                        <div v-if="recommendedVendor" class="space-y-4">
                            <!-- Vendor name + source badge -->
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 class="text-2xl font-bold leading-tight">
                                        {{ recommendedVendor.name }}
                                    </h2>
                                    <p class="mt-0.5 text-sm text-muted-foreground">
                                        {{ recommendedVendor.vendor_type || "No vendor type listed" }}
                                    </p>
                                </div>
                                <Badge
                                    v-if="vendorSourceBadge"
                                    :variant="vendorSourceBadge.variant"
                                    class="shrink-0"
                                >
                                    {{ vendorSourceBadge.label }}
                                </Badge>
                            </div>

                            <!-- Issue type + confidence bar -->
                            <div class="space-y-1.5">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-medium">
                                        {{ recommendation.issue_type || "Unknown issue" }}
                                    </span>
                                    <span class="tabular-nums text-muted-foreground">
                                        {{ confidence }}% confidence
                                    </span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :class="confidenceTone"
                                        :style="{ width: `${confidence}%` }"
                                    />
                                </div>
                            </div>

                            <!-- Reasoning -->
                            <p
                                v-if="recommendation.reasoning"
                                class="border-l-2 border-primary/40 pl-3 text-sm italic leading-6 text-muted-foreground"
                            >
                                {{ recommendation.reasoning }}
                            </p>

                            <!-- Owner preference + maintenance notice -->
                            <div
                                v-if="ownerPreferredName || maintenanceNotice"
                                class="rounded-md border border-primary/30 bg-primary/5 p-3"
                            >
                                <div
                                    v-if="ownerPreferredName"
                                    class="flex items-center gap-2 text-sm font-semibold text-primary"
                                >
                                    <Star class="h-3.5 w-3.5 fill-primary" />
                                    Owner prefers: {{ ownerPreferredName }}
                                </div>
                                <div
                                    v-if="maintenanceNotice"
                                    :class="[ownerPreferredName ? 'mt-2' : '']"
                                >
                                    <p class="mb-1 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-foreground">
                                        <FileText class="h-3 w-3" />
                                        Maintenance Notice
                                    </p>
                                    <p class="whitespace-pre-line text-sm leading-6 text-muted-foreground">
                                        {{ maintenanceNotice }}
                                    </p>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex flex-wrap gap-2 pt-1">
                                <Button
                                    :disabled="!recommendedVendor || isGenerating"
                                    @click="$emit('assign', recommendedVendor)"
                                >
                                    <CheckCircle2 class="mr-2 h-4 w-4" />
                                    Assign Vendor
                                    <ArrowRight class="ml-2 h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    :disabled="isGenerating"
                                    @click="$emit('generate')"
                                >
                                    <RefreshCw
                                        :class="{ 'animate-spin': isGenerating }"
                                        class="mr-2 h-4 w-4"
                                    />
                                    Regenerate
                                </Button>
                            </div>
                        </div>

                        <div v-else class="py-4 text-sm text-muted-foreground">
                            No vendor could be automatically recommended. Human review is required.
                        </div>
                    </CardContent>
                </Card>

                <!-- Issue Summary + Similar Work Orders -->
                <div class="space-y-4">
                    <Card>
                        <CardHeader class="pb-3">
                            <CardTitle class="flex items-center gap-2 text-base">
                                <Sparkles class="h-4 w-4" />
                                Issue Summary
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-3 text-sm">
                            <p class="leading-6 text-muted-foreground">
                                {{ recommendation.summary || "No summary available." }}
                            </p>

                            <div
                                v-if="recommendation.vendor_category"
                                class="flex items-center justify-between text-xs"
                            >
                                <span class="text-muted-foreground">Vendor type</span>
                                <span class="font-medium">
                                    {{ recommendation.vendor_category }}
                                </span>
                            </div>

                            <div
                                v-if="recommendation.keywords?.length"
                                class="flex flex-wrap gap-1.5"
                            >
                                <Badge
                                    v-for="keyword in recommendation.keywords"
                                    :key="keyword"
                                    variant="secondary"
                                    class="text-[10px]"
                                >
                                    {{ keyword }}
                                </Badge>
                            </div>

                            <p class="border-t pt-2 text-[11px] text-muted-foreground">
                                Source: {{ recommendation.source }}
                                <span v-if="recommendation.generated_at">
                                    • Generated {{ formatDate(recommendation.generated_at) }}
                                </span>
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="pb-3">
                            <CardTitle class="flex items-center gap-2 text-base">
                                <History class="h-4 w-4" />
                                Similar Work Orders
                            </CardTitle>
                            <CardDescription>
                                Prior completed work used to support the recommendation.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="pt-0">
                            <div v-if="matchedWorkOrders.length" class="-mx-2 divide-y">
                                <div
                                    v-for="item in matchedWorkOrders"
                                    :key="item.id"
                                    class="px-2 py-3"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-sm font-semibold">
                                            #{{ item.work_order_no }}
                                        </p>
                                        <p class="text-[11px] text-muted-foreground">
                                            {{ formatDate(item.completed_date) }}
                                        </p>
                                    </div>
                                    <p class="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                        {{
                                            item.description ||
                                            item.closing_comments ||
                                            "No details available."
                                        }}
                                    </p>
                                    <p
                                        v-if="item.vendor?.name"
                                        class="mt-1.5 text-[11px] font-medium uppercase tracking-wide text-primary"
                                    >
                                        Prior vendor: {{ item.vendor.name }}
                                    </p>
                                </div>
                            </div>
                            <p v-else class="text-sm text-muted-foreground">
                                No close historical matches were found yet.
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <!-- Previous Work Orders at This Property -->
                <Card v-if="propertyHistory">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex flex-wrap items-center gap-2 text-base">
                            <Building2 class="h-4 w-4" />
                            Previous Work Orders at This Property
                            <Badge
                                v-if="propertyHistory.property_id"
                                variant="secondary"
                                class="ml-auto tabular-nums"
                            >
                                {{ propertyHistoryTotal }}
                                {{ propertyHistoryTotal === 1 ? "work order" : "work orders" }}
                            </Badge>
                        </CardTitle>
                        <CardDescription>
                            Every earlier work order on file for this property, newest first.
                            Open ones are still in progress.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="pt-0">
                        <div v-if="propertyHistoryItems.length" class="-mx-2 divide-y">
                            <div
                                v-for="item in propertyHistoryItems"
                                :key="item.id"
                                class="px-2 py-3"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a
                                            :href="route('work_orders.details', item.id)"
                                            target="_blank"
                                            rel="noopener"
                                            class="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline"
                                            :title="`Open work order #${item.work_order_no ?? item.id} in a new tab`"
                                        >
                                            #{{ item.work_order_no ?? item.id }}
                                            <ExternalLink class="h-3 w-3" />
                                        </a>
                                        <Badge
                                            v-if="isClosed(item)"
                                            variant="secondary"
                                            class="text-[10px]"
                                        >
                                            {{ item.status }}
                                        </Badge>
                                        <Badge
                                            v-else
                                            variant="outline"
                                            class="border-amber-500/60 text-[10px] text-amber-700"
                                        >
                                            {{ item.status || "Open" }}
                                        </Badge>
                                        <Badge
                                            v-if="item.is_emergency"
                                            class="bg-red-600 text-[10px] text-white hover:bg-red-600"
                                        >
                                            Emergency
                                        </Badge>
                                    </div>
                                    <p class="text-[11px] text-muted-foreground">
                                        {{ item.created_date_label || "Unknown date" }}
                                        <span v-if="item.completed_date_label">
                                            · Completed {{ item.completed_date_label }}
                                        </span>
                                    </p>
                                </div>
                                <p
                                    v-if="historyDetailLine(item)"
                                    class="mt-1 text-[11px] text-muted-foreground"
                                >
                                    {{ historyDetailLine(item) }}
                                </p>
                                <p class="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                    {{ item.description || "No details available." }}
                                </p>
                                <p
                                    v-if="item.vendor_names"
                                    class="mt-1.5 text-[11px] font-medium uppercase tracking-wide text-primary"
                                >
                                    Vendor: {{ item.vendor_names }}
                                </p>
                            </div>
                        </div>
                        <p
                            v-else-if="!propertyHistory.property_id"
                            class="text-sm text-muted-foreground"
                        >
                            This work order is not linked to a property, so there is no history to show.
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            No previous work orders on file for this property.
                        </p>

                        <div
                            v-if="propertyHistoryHiddenCount > 0 || (isStaff && propertyHistory.building && propertyHistoryTotal > 0)"
                            class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t pt-2 text-[11px] text-muted-foreground"
                        >
                            <span v-if="propertyHistoryHiddenCount > 0">
                                Showing the {{ propertyHistoryItems.length }} most recent of
                                {{ propertyHistoryTotal }}.
                            </span>
                            <a
                                v-if="isStaff && propertyHistory.building && propertyHistoryTotal > 0"
                                :href="route('buildings.show', propertyHistory.building.id)"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                            >
                                View all on the property page
                                <ExternalLink class="h-3 w-3" />
                            </a>
                        </div>
                    </CardContent>
                </Card>

                <!-- Alternate Database Vendors -->
                <Card v-if="databaseAlternates.length">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Wrench class="h-4 w-4" />
                            Alternate Vendors
                        </CardTitle>
                        <CardDescription>
                            Other active vendors that match this issue category.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="flex flex-wrap gap-2">
                            <Badge
                                v-for="vendor in databaseAlternates"
                                :key="vendor.id"
                                variant="outline"
                                class="py-1"
                            >
                                {{ vendor.name }}
                                <span
                                    v-if="vendor.vendor_type"
                                    class="ml-1 text-muted-foreground"
                                >
                                    · {{ vendor.vendor_type }}
                                </span>
                            </Badge>
                        </div>
                    </CardContent>
                </Card>

                <!-- Fallback Vendors -->
                <Card v-if="fallbackAlternates.length">
                    <CardHeader class="pb-3">
                        <CardTitle class="text-base">Fallback Vendor List</CardTitle>
                        <CardDescription>
                            Curated manual vendors for when no automatic match applies.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div
                                v-for="vendor in fallbackAlternates"
                                :key="vendor.name"
                                class="rounded-lg border bg-muted/20 px-4 py-3"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-medium">{{ vendor.name }}</p>
                                </div>
                                <p
                                    v-if="vendor.issue_types?.length"
                                    class="mt-1 text-[10px] font-medium uppercase tracking-wide text-muted-foreground"
                                >
                                    {{ vendor.issue_types.join(" · ") }}
                                </p>
                                <div
                                    v-if="vendor.contacts?.length"
                                    class="mt-2 space-y-1 text-sm"
                                >
                                    <div
                                        v-for="(contact, index) in vendor.contacts"
                                        :key="`${vendor.name}-${index}`"
                                        class="text-muted-foreground"
                                    >
                                        <span v-if="contact.name" class="font-medium text-foreground">
                                            {{ contact.name }}:
                                        </span>
                                        <span v-if="contact.phone">{{ contact.phone }}</span>
                                        <span v-if="contact.email">
                                            <span v-if="contact.phone"> • </span>{{ contact.email }}
                                        </span>
                                    </div>
                                </div>
                                <p v-if="vendor.notes" class="mt-2 text-xs leading-5 text-muted-foreground">
                                    {{ vendor.notes }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </template>
        </div>
    </div>
</template>
