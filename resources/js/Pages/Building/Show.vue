<script setup>
import { Link } from "@inertiajs/vue3";
import { ExternalLink } from "lucide-vue-next";
import AppLayout from "@/Layouts/AppLayout.vue";
import MaintenanceDetails from "@/Components/Building/MaintenanceDetails.vue";

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
        <MaintenanceDetails :building="building" />

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
