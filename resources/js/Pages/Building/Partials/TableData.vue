<script setup>
import { Link } from "@inertiajs/vue3";

defineProps({
    data: Object,
});
</script>
<template>
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead>Name</TableHead>
                <TableHead class="hidden md:table-cell">Address</TableHead>
                <TableHead class="hidden md:table-cell">Status</TableHead>
                <TableHead>Work Orders</TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="building in data" :key="building.id">
                <TableCell class="font-medium">
                    <Link
                        :href="route('buildings.show', building.id)"
                        class="text-primary hover:text-primary/80"
                    >
                        {{ building.name ?? "—" }}
                    </Link>
                    <p class="text-xs font-normal mt-1 md:hidden text-muted-foreground">
                        {{ building.address }}
                    </p>
                </TableCell>
                <TableCell class="hidden md:table-cell text-sm text-muted-foreground">
                    {{ building.address ?? "—" }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    <Badge :variant="building.active ? 'default' : 'secondary'">
                        {{ building.active ? "Active" : "Inactive" }}
                    </Badge>
                </TableCell>
                <TableCell>
                    {{ building.work_orders_count }}
                </TableCell>
            </TableRow>
            <TableRow v-if="data.length === 0">
                <TableCell colspan="4">No buildings found.</TableCell>
            </TableRow>
        </TableBody>
    </Table>
</template>
