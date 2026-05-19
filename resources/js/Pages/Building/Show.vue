<script setup>
import { Link } from "@inertiajs/vue3";
import { ChevronDown, ExternalLink } from "lucide-vue-next";
import AppLayout from "@/Layouts/AppLayout.vue";

defineOptions({ layout: AppLayout });

defineProps({
    title: String,
    building: Object,
    workOrders: Object,
});
</script>
<template>
    <Head :title="title" />
    <div class="flex flex-col gap-4">
        <!-- Building Details -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span>{{ building.name ?? "Building Details" }}</span>
                        <a
                            v-if="building.propertyware_id"
                            :href="`https://app.propertyware.com/pw/properties/building_detail.do?entityID=${building.propertyware_id}`"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 rounded-md border border-input bg-background px-2.5 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted"
                        >
                            <ExternalLink class="h-3.5 w-3.5" />
                            PropertyWare
                        </a>
                    </div>
                    <Badge :variant="building.active ? 'default' : 'secondary'">
                        {{ building.active ? "Active" : "Inactive" }}
                    </Badge>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <dl
                    class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3 text-sm"
                >
                    <div>
                        <dt class="text-muted-foreground">Address</dt>
                        <dd class="font-medium">
                            {{ building.address ?? "—" }}
                        </dd>
                    </div>
                    <div v-if="building.address_cont">
                        <dt class="text-muted-foreground">Address (cont.)</dt>
                        <dd class="font-medium">{{ building.address_cont }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">City</dt>
                        <dd class="font-medium">{{ building.city ?? "—" }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">State / Region</dt>
                        <dd class="font-medium">
                            {{ building.state_region ?? "—" }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Postal Code</dt>
                        <dd class="font-medium">
                            {{ building.postal_code ?? "—" }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Country</dt>
                        <dd class="font-medium">
                            {{ building.country ?? "—" }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">PropertyWare ID</dt>
                        <dd class="font-medium">
                            {{ building.propertyware_id }}
                        </dd>
                    </div>
                    <div v-if="building.category">
                        <dt class="text-muted-foreground">Category</dt>
                        <dd class="font-medium">{{ building.category }}</dd>
                    </div>
                    <div v-if="building.property_type">
                        <dt class="text-muted-foreground">Property Type</dt>
                        <dd class="font-medium">{{ building.property_type }}</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <!-- Maintenance Settings -->
        <Collapsible v-slot="{ open }">
            <Card>
                <CardHeader class="p-0">
                    <CollapsibleTrigger
                        class="flex w-full items-center justify-between gap-2 p-6 text-left transition-colors hover:bg-muted/40"
                    >
                        <span class="flex items-center gap-2 text-lg font-semibold leading-none tracking-tight">
                            <ChevronDown
                                class="h-4 w-4 transition-transform"
                                :class="{ '-rotate-90': !open }"
                            />
                            Maintenance
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
                    <CardContent class="space-y-4 text-sm">
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

                        <dl class="grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
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
                            <dl class="grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-2">
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

        <!-- Related Work Orders -->
        <Card>
            <CardHeader>
                <CardTitle>Work Orders</CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>WO #</TableHead>
                            <TableHead class="hidden md:table-cell"
                                >Description</TableHead
                            >
                            <TableHead class="hidden md:table-cell"
                                >Service Status</TableHead
                            >
                            <TableHead class="hidden lg:table-cell"
                                >Priority</TableHead
                            >
                            <TableHead class="hidden lg:table-cell"
                                >Category</TableHead
                            >
                            <TableHead class="hidden md:table-cell"
                                >Created</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="wo in workOrders.data" :key="wo.id">
                            <TableCell class="font-medium">
                                <Link
                                    :href="route('work_orders.details', wo.id)"
                                    class="text-primary hover:text-primary/80"
                                >
                                    {{ wo.work_order_no ?? wo.id }}
                                </Link>
                            </TableCell>
                            <TableCell
                                class="hidden md:table-cell text-sm text-muted-foreground max-w-xs truncate"
                            >
                                {{ wo.description ?? "—" }}
                            </TableCell>
                            <TableCell class="hidden md:table-cell">
                                {{ wo.service_status ?? "—" }}
                            </TableCell>
                            <TableCell class="hidden lg:table-cell">
                                {{ wo.priority ?? "—" }}
                            </TableCell>
                            <TableCell class="hidden lg:table-cell">
                                {{ wo.category ?? "—" }}
                            </TableCell>
                            <TableCell
                                class="hidden md:table-cell text-sm text-muted-foreground"
                            >
                                {{ wo.created_date ?? "—" }}
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="workOrders.data.length === 0">
                            <TableCell colspan="7"
                                >No work orders found for this
                                building.</TableCell
                            >
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
            <CardFooter
                class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
            >
                <PaginationResultRange :data="workOrders" />
                <Pagination :pagination="workOrders.links" />
            </CardFooter>
        </Card>
    </div>
</template>
