<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import { ArrowLeft, MapPin, Phone, Mail, Hash } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    vendor: Object,
    workOrders: { type: Array, default: () => [] },
});

const priorityClass = (priority) => {
    const p = (priority || "").toLowerCase();
    if (p.includes("high") || p.includes("emergency"))
        return "bg-red-100 text-red-700";
    if (p.includes("medium")) return "bg-amber-100 text-amber-700";
    return "bg-slate-100 text-slate-600";
};

const fmtDate = (d) => (d ? new Date(d).toLocaleDateString() : "—");
const fmtMoney = (v) =>
    v !== null && v !== undefined && v !== ""
        ? `$${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}`
        : "—";
</script>

<template>
    <Head :title="title" />

    <div class="space-y-4">
        <Link
            :href="route('vendors.index')"
            class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="w-4 h-4" /> Back to vendors
        </Link>

        <!-- Vendor info -->
        <Card>
            <CardHeader>
                <div class="flex items-center justify-between gap-2 flex-wrap">
                    <CardTitle class="text-xl">{{ vendor.name }}</CardTitle>
                    <Badge :variant="vendor.is_active ? 'default' : 'secondary'">
                        {{ vendor.is_active ? "Active" : "Inactive" }}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                <div
                    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3 text-sm"
                >
                    <div class="flex items-center gap-2">
                        <Mail class="w-4 h-4 text-muted-foreground shrink-0" />
                        <span class="truncate">{{ vendor.email || "—" }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Phone class="w-4 h-4 text-muted-foreground shrink-0" />
                        <span>{{ vendor.phone || "—" }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Hash class="w-4 h-4 text-muted-foreground shrink-0" />
                        <span>PW ID: {{ vendor.propertyware_id || "—" }}</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground">Type: </span>
                        {{ vendor.vendor_type || "—" }}
                    </div>
                    <div>
                        <span class="text-muted-foreground">Name on check: </span>
                        {{ vendor.name_on_check || "—" }}
                    </div>
                    <div>
                        <span class="text-muted-foreground">Twilio: </span>
                        {{ vendor.twilio_number || "—" }}
                    </div>
                    <div class="flex items-start gap-2 sm:col-span-2 lg:col-span-3">
                        <MapPin class="w-4 h-4 text-muted-foreground shrink-0 mt-0.5" />
                        <span>{{ vendor.address || "—" }}</span>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Assigned work orders -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">
                    Assigned Work Orders ({{ workOrders.length }})
                </CardTitle>
            </CardHeader>
            <CardContent>
                <Table v-if="workOrders.length">
                    <TableHeader>
                        <TableRow>
                            <TableHead>WO #</TableHead>
                            <TableHead class="hidden md:table-cell">Description</TableHead>
                            <TableHead class="hidden lg:table-cell">Location</TableHead>
                            <TableHead>Priority</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="hidden md:table-cell">Created</TableHead>
                            <TableHead class="text-right">Your estimate</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="wo in workOrders" :key="wo.id">
                            <TableCell>
                                <Link
                                    :href="route('work_orders.details', wo.id)"
                                    class="font-medium text-primary hover:underline"
                                >
                                    #{{ wo.work_order_no }}
                                </Link>
                            </TableCell>
                            <TableCell class="hidden md:table-cell max-w-xs">
                                <span class="line-clamp-1">{{ wo.description || "—" }}</span>
                            </TableCell>
                            <TableCell class="hidden lg:table-cell">
                                {{ wo.location || "—" }}
                            </TableCell>
                            <TableCell>
                                <span
                                    class="text-xs font-medium rounded-full px-2.5 py-0.5"
                                    :class="priorityClass(wo.priority)"
                                >
                                    {{ wo.priority || "—" }}
                                </span>
                            </TableCell>
                            <TableCell>
                                <span class="text-sm">{{ wo.service_status || wo.status || "—" }}</span>
                            </TableCell>
                            <TableCell class="hidden md:table-cell text-sm text-muted-foreground">
                                {{ fmtDate(wo.created_date) }}
                            </TableCell>
                            <TableCell class="text-right text-sm">
                                {{ fmtMoney(wo.cost_estimate) }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <p v-else class="text-sm text-muted-foreground py-6 text-center">
                    No work orders assigned to this vendor.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
