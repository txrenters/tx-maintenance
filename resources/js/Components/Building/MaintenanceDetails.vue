<script setup>
import { ChevronDown } from "lucide-vue-next";

defineProps({
    building: { type: Object, default: null },
    defaultOpen: { type: Boolean, default: false },
    title: { type: String, default: "Maintenance" },
    /**
     * Sizes the card to sit beside the other fields in the work order modal:
     * a field-height header, single-column lists, and the expanded content
     * scrolling inside itself instead of stretching the modal.
     */
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <Collapsible v-if="building" :default-open="defaultOpen" v-slot="{ open }">
        <Card>
            <CardHeader class="p-0">
                <CollapsibleTrigger
                    class="flex w-full items-center justify-between gap-2 text-left transition-colors hover:bg-muted/40"
                    :class="compact ? 'rounded-lg px-3 py-2' : 'p-6'"
                >
                    <span
                        class="flex items-center gap-2 font-semibold leading-none tracking-tight"
                        :class="compact ? 'text-sm' : 'text-lg'"
                    >
                        <ChevronDown
                            class="h-4 w-4 transition-transform"
                            :class="{ '-rotate-90': !open }"
                        />
                        {{ title }}
                    </span>
                    <span
                        v-if="building.details_synced_at"
                        class="text-xs font-normal text-muted-foreground"
                    >
                        Synced {{ new Date(building.details_synced_at).toLocaleString() }}
                    </span>
                </CollapsibleTrigger>
            </CardHeader>
            <CollapsibleContent>
                <CardContent
                    class="space-y-4 text-sm"
                    :class="compact ? 'max-h-72 overflow-y-auto px-3 pb-3' : ''"
                >
                    <div v-if="building.maintenance_notice">
                        <dt class="mb-1 text-muted-foreground">Maintenance Notice</dt>
                        <dd class="whitespace-pre-line rounded-md border bg-muted/30 p-3 font-medium">
                            {{ building.maintenance_notice }}
                        </dd>
                    </div>
                    <div
                        v-else
                        class="rounded-md border border-dashed p-3 text-muted-foreground"
                    >
                        No maintenance notice on file.
                    </div>

                    <dl
                        class="grid grid-cols-1 gap-x-8 gap-y-3"
                        :class="compact ? '' : 'sm:grid-cols-2'"
                    >
                        <div>
                            <dt class="text-muted-foreground">Spending Limit</dt>
                            <dd class="font-medium">
                                {{
                                    building.maintenance_spending_limit_amount
                                        ? `$${building.maintenance_spending_limit_amount}`
                                        : "—"
                                }}
                                <span
                                    v-if="building.maintenance_spending_limit_time"
                                    class="text-muted-foreground"
                                >
                                    / {{ building.maintenance_spending_limit_time }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Labor Surcharge</dt>
                            <dd class="font-medium">
                                {{
                                    building.maintenance_labor_surcharge_amount
                                        ? `$${building.maintenance_labor_surcharge_amount}`
                                        : "—"
                                }}
                                <span
                                    v-if="building.maintenance_labor_surcharge_type"
                                    class="text-muted-foreground"
                                >
                                    ({{ building.maintenance_labor_surcharge_type }})
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="building.custom_fields && building.custom_fields.length"
                        class="space-y-2"
                    >
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Custom Fields
                        </p>
                        <dl
                            class="grid grid-cols-1 gap-x-8 gap-y-2"
                            :class="compact ? '' : 'sm:grid-cols-2'"
                        >
                            <div
                                v-for="field in building.custom_fields"
                                :key="field.definitionID || field.fieldName"
                            >
                                <dt class="text-muted-foreground">{{ field.fieldName }}</dt>
                                <dd class="font-medium">{{ field.value || "—" }}</dd>
                            </div>
                        </dl>
                    </div>
                </CardContent>
            </CollapsibleContent>
        </Card>
    </Collapsible>
</template>
