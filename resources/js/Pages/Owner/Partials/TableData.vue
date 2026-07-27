<script setup>
defineProps({
    data: Object,
});
</script>
<template>
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead>Name</TableHead>
                <TableHead class="hidden md:table-cell"> Mobile </TableHead>
                <TableHead class="hidden md:table-cell"> Phone </TableHead>
                <TableHead class="hidden md:table-cell"> Status </TableHead>
                <TableHead class="hidden md:table-cell"> Address </TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="owner in data" :key="owner.id">
                <TableCell class="font-medium">
                    <Link
                        :href="route('owners.show', owner.id)"
                        class="text-primary hover:underline"
                    >
                        {{ owner.name }}
                    </Link>
                    <p class="text-xs font-normal">{{ owner.email }}</p>
                    <p class="text-xs font-normal">{{ owner.mobile }}</p>
                    <p class="text-xs font-normal text-muted-foreground">
                        PW #{{ owner.propertyware_id }} ·
                        {{ owner.property_work_orders_count }} owned ·
                        {{ owner.management_work_orders_count }} managed
                    </p>
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ owner.mobile }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ owner.phone }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ owner.status }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ owner.address }}
                </TableCell>
            </TableRow>
            <TableRow v-if="data.length === 0">
                <TableCell colspan="6">No owners found!</TableCell>
            </TableRow>
        </TableBody>
    </Table>

</template>
