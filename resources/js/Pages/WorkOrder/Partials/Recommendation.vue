<script setup>
import { computed } from "vue";
import { Button } from "@/Components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import { Badge } from "@/Components/ui/badge";
import { Sparkles, Bot, Wrench, History, RefreshCw, CheckCircle2 } from "lucide-vue-next";

const props = defineProps({
    isLoading: Boolean,
    isGenerating: Boolean,
    recommendation: Object,
    aiReady: Boolean,
});

const emit = defineEmits(["generate", "assign"]);

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

const formatDate = (date) => {
    if (!date) {
        return "Unknown";
    }

    return new Date(date).toLocaleDateString();
};
</script>

<template>
    <div class="px-6 pb-6">
        <div class="grid gap-4">
            <Alert v-if="!aiReady" class="border-amber-200 bg-amber-50 text-amber-900">
                <Bot class="h-4 w-4" />
                <AlertTitle>AI SDK not active yet</AlertTitle>
                <AlertDescription>
                    Recommendations currently use the built-in heuristic fallback until
                    `laravel/ai` is installed and configured with OpenAI.
                </AlertDescription>
            </Alert>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-primary">Recommendation Center</p>
                    <p class="text-sm text-muted-foreground">
                        Review the issue summary, similar history, and best vendor match.
                    </p>
                </div>

                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        :disabled="isGenerating"
                        @click="$emit('generate')"
                    >
                        <RefreshCw :class="{ 'animate-spin': isGenerating }" class="mr-2 h-4 w-4" />
                        {{ recommendation ? "Refresh Recommendation" : "Generate Recommendation" }}
                    </Button>

                    <Button
                        :disabled="!recommendedVendor || isGenerating"
                        @click="$emit('assign', recommendedVendor)"
                    >
                        <CheckCircle2 class="mr-2 h-4 w-4" />
                        Assign Recommended Vendor
                    </Button>
                </div>
            </div>

            <div
                v-if="!recommendation && !isLoading"
                class="rounded-lg border border-dashed px-6 py-10 text-center"
            >
                <Sparkles class="mx-auto mb-3 h-8 w-8 text-muted-foreground" />
                <p class="font-medium">No recommendation has been generated yet.</p>
                <p class="text-sm text-muted-foreground">
                    Generate one to classify the issue, inspect similar past work, and rank vendors.
                </p>
            </div>

            <div v-else-if="recommendation" class="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Sparkles class="h-4 w-4" />
                            Work Order Summary
                        </CardTitle>
                        <CardDescription>
                            Issue normalization and confidence for this recommendation snapshot.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex flex-wrap gap-2">
                            <Badge variant="outline">
                                Issue: {{ recommendation.issue_type || "Unknown" }}
                            </Badge>
                            <Badge v-if="recommendation.vendor_category" variant="outline">
                                Vendor Type: {{ recommendation.vendor_category }}
                            </Badge>
                            <Badge
                                :variant="
                                    recommendation.needs_human_review ? 'destructive' : 'outline'
                                "
                            >
                                Confidence: {{ recommendation.confidence ?? 0 }}%
                            </Badge>
                        </div>

                        <p class="text-sm leading-6 text-muted-foreground">
                            {{ recommendation.summary || "No summary available." }}
                        </p>

                        <div v-if="recommendation.keywords?.length" class="flex flex-wrap gap-2">
                            <Badge
                                v-for="keyword in recommendation.keywords"
                                :key="keyword"
                                variant="secondary"
                            >
                                {{ keyword }}
                            </Badge>
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Source: {{ recommendation.source }}<span v-if="recommendation.generated_at">
                                • Generated {{ formatDate(recommendation.generated_at) }}
                            </span>
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Wrench class="h-4 w-4" />
                            Vendor Recommendation
                        </CardTitle>
                        <CardDescription>
                            Best current match based on history and active vendor types.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div v-if="recommendedVendor">
                            <p class="text-lg font-semibold">{{ recommendedVendor.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ recommendedVendor.vendor_type || "No vendor type listed" }}
                            </p>
                        </div>
                        <div v-else class="text-sm text-muted-foreground">
                            No vendor could be automatically recommended.
                        </div>

                        <p class="text-sm leading-6">
                            {{ recommendation.reasoning || "No reasoning available." }}
                        </p>

                        <div v-if="databaseAlternates.length" class="space-y-2">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                Alternate Database Vendors
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Badge
                                    v-for="vendor in databaseAlternates"
                                    :key="vendor.id"
                                    variant="outline"
                                >
                                    {{ vendor.name }}
                                </Badge>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <History class="h-4 w-4" />
                            Similar Work Orders
                        </CardTitle>
                        <CardDescription>
                            Prior work used to support the vendor recommendation.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="matchedWorkOrders.length" class="space-y-3">
                            <div
                                v-for="item in matchedWorkOrders"
                                :key="item.id"
                                class="rounded-lg border px-4 py-3"
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="font-medium">#{{ item.work_order_no }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ formatDate(item.completed_date) }}
                                    </p>
                                </div>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ item.description || item.closing_comments || "No details available." }}
                                </p>
                                <p
                                    v-if="item.vendor?.name"
                                    class="mt-2 text-xs font-medium uppercase tracking-wide text-primary"
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

                <Card class="lg:col-span-2" v-if="fallbackAlternates.length">
                    <CardHeader>
                        <CardTitle>Fallback Vendor List</CardTitle>
                        <CardDescription>
                            Manual vendor recommendations from your curated vendor list.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div
                                v-for="vendor in fallbackAlternates"
                                :key="vendor.name"
                                class="rounded-lg border px-4 py-3"
                            >
                                <p class="font-medium">{{ vendor.name }}</p>
                                <p
                                    v-if="vendor.issue_types?.length"
                                    class="mt-1 text-xs uppercase tracking-wide text-muted-foreground"
                                >
                                    {{ vendor.issue_types.join(", ") }}
                                </p>
                                <div
                                    v-if="vendor.contacts?.length"
                                    class="mt-2 space-y-1 text-sm text-muted-foreground"
                                >
                                    <div
                                        v-for="(contact, index) in vendor.contacts"
                                        :key="`${vendor.name}-${index}`"
                                    >
                                        <span v-if="contact.name">{{ contact.name }}: </span>
                                        <span v-if="contact.phone">{{ contact.phone }}</span>
                                        <span v-if="contact.email">
                                            <span v-if="contact.phone"> • </span>{{ contact.email }}
                                        </span>
                                    </div>
                                </div>
                                <p v-if="vendor.notes" class="mt-2 text-sm text-muted-foreground">
                                    {{ vendor.notes }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
