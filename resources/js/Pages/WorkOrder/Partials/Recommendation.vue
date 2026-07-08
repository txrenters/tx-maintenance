<script setup>
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { Button } from "@/Components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import { Badge } from "@/Components/ui/badge";
import { useToast } from "@/Components/ui/toast/use-toast";
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
} from "lucide-vue-next";

const props = defineProps({
    isLoading: Boolean,
    isGenerating: Boolean,
    recommendation: Object,
});

defineEmits(["generate", "assign"]);

const { toast } = useToast();

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

const VENDOR_SOURCE_LABELS = {
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

// Local override so the card updates immediately after Confirm/Override,
// without waiting for a recommendation re-fetch. Reset when a new
// recommendation payload arrives.
const localEmergency = ref(undefined);
const isSettingEmergency = ref(false);

watch(
    () => props.recommendation,
    () => {
        localEmergency.value = undefined;
    },
);

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

// The work order's current flag: null = unclassified (needs review).
const workOrderEmergency = computed(() => {
    if (localEmergency.value !== undefined) return localEmergency.value;
    const value = props.recommendation?.work_order?.is_emergency;
    if (value === null || value === undefined) return null;
    return Boolean(Number(value));
});

const needsEmergencyReview = computed(
    () => hasEmergencyAssessment.value && workOrderEmergency.value === null,
);

const emergencyClassifiedBy = computed(() => {
    if (workOrderEmergency.value === null) return null;
    if (localEmergency.value !== undefined) return "staff";
    return props.recommendation?.emergency_auto_applied &&
        workOrderEmergency.value === aiSaysEmergency.value
        ? "ai"
        : "staff";
});

const setEmergency = (isEmergency) => {
    const workOrderId =
        props.recommendation?.work_order?.id ?? props.recommendation?.work_order_id;

    if (!workOrderId || isSettingEmergency.value) return;

    isSettingEmergency.value = true;

    router.put(
        route("work_orders.emergency.change", workOrderId),
        { is_emergency: isEmergency ? "Emergency" : "Non-emergency" },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                localEmergency.value = isEmergency;
                toast({
                    title: "Success",
                    description: `Work order marked as ${isEmergency ? "Emergency" : "Non-emergency"}. Tasks were regenerated.`,
                });
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description: "Failed to update the emergency status. Please try again!",
                });
            },
            onFinish: () => {
                isSettingEmergency.value = false;
            },
        },
    );
};

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
                    {{ recommendation ? "Refresh" : "Generate" }}
                </Button>
            </div>

            <!-- Empty state -->
            <div
                v-if="!recommendation && !isLoading"
                class="rounded-lg border border-dashed px-6 py-12 text-center"
            >
                <Sparkles class="mx-auto mb-3 h-8 w-8 text-muted-foreground" />
                <p class="font-medium">No recommendation has been generated yet.</p>
                <p class="text-sm text-muted-foreground">
                    Generate one to classify the issue, inspect similar past work, and rank vendors.
                </p>
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

                        <!-- Needs review: low confidence, waiting for a human decision -->
                        <div
                            v-if="needsEmergencyReview"
                            class="rounded-md border border-amber-500/40 bg-amber-500/10 p-3"
                        >
                            <p class="text-sm font-medium text-amber-700 dark:text-amber-400">
                                Needs review — confidence was too low to apply this
                                automatically. Please confirm:
                            </p>
                            <div class="mt-2.5 flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    :disabled="isSettingEmergency"
                                    :class="
                                        aiSaysEmergency
                                            ? 'bg-red-600 text-white hover:bg-red-700'
                                            : ''
                                    "
                                    :variant="aiSaysEmergency ? 'default' : 'outline'"
                                    @click="setEmergency(true)"
                                >
                                    <AlertTriangle class="mr-1.5 h-3.5 w-3.5" />
                                    Emergency
                                </Button>
                                <Button
                                    size="sm"
                                    :disabled="isSettingEmergency"
                                    :class="
                                        !aiSaysEmergency
                                            ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                            : ''
                                    "
                                    :variant="!aiSaysEmergency ? 'default' : 'outline'"
                                    @click="setEmergency(false)"
                                >
                                    <ShieldCheck class="mr-1.5 h-3.5 w-3.5" />
                                    Non-emergency
                                </Button>
                            </div>
                        </div>

                        <!-- Already classified: show current status + who set it -->
                        <p
                            v-else-if="workOrderEmergency !== null"
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
