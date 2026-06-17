<script setup>
import { Phone, SquarePen, RefreshCw, Loader2 } from "lucide-vue-next";
const emit = defineEmits(["isDialogOpen", "statusChanged", "syncVendor"]);

defineProps({
    data: Object,
    syncingId: [Number, String, null],
});

const openEditDialog = (user) => {
    emit("isDialogOpen", true, user);
};
const updateStatus = (checked, vendor) => {
    emit("statusChanged", checked, vendor); // Emit event to parent
};
const syncVendor = (vendor) => {
    emit("syncVendor", vendor);
};
</script>
<template>
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead>Name</TableHead>
                <TableHead class="hidden md:table-cell">Contact Name</TableHead>
                <TableHead class="hidden md:table-cell">Type</TableHead>
                <TableHead class="hidden md:table-cell"
                    >Phone Number
                </TableHead>
                <TableHead class="hidden md:table-cell"
                    >Twilio Number
                </TableHead>
                <TableHead class="hidden md:table-cell">Zones </TableHead>
                <TableHead>Status </TableHead>
                <TableHead class="hidden md:table-cell">Address </TableHead>
                <TableHead>
                    <span class="sr-only">Actions</span>
                </TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="vendor in data" :key="vendor.id">
                <TableCell class="font-medium">
                    <Link
                        :href="route('vendors.show', vendor.id)"
                        class="hover:text-primary hover:underline"
                    >
                        {{ vendor.name }}
                    </Link>
                    <p v-if="vendor.email" class="text-xs font-normal text-muted-foreground">
                        {{ vendor.email }}
                    </p>
                    <span class="flex flex-col md:hidden">
                        <p class="text-xs font-normal">
                            {{ vendor.phone }}
                        </p>
                    </span>
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ vendor.name_on_check }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    <Badge v-if="vendor.vendor_type">{{
                        vendor.vendor_type
                    }}</Badge>
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ vendor.phone }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ vendor.twilio_number }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    <div v-if="vendor.zones?.length" class="flex flex-wrap gap-1">
                        <Badge
                            v-for="zone in vendor.zones"
                            :key="zone"
                            variant="outline"
                        >
                            {{ zone }}
                        </Badge>
                    </div>
                    <span v-else class="text-xs text-muted-foreground">—</span>
                </TableCell>
                <TableCell>
                    <Switch
                        :checked="vendor.status"
                        @update:checked="updateStatus($event, vendor.id)"
                    />
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ vendor.address }}
                </TableCell>
                <TableCell>
                    <div class="flex gap-3">
                        <Button
                            variant="link"
                            class="hover:text-primary p-0"
                            @click="openEditDialog(vendor)"
                            ><SquarePen
                        /></Button>
                        <Button
                            variant="link"
                            class="hover:text-primary p-0"
                            title="Sync from PropertyWare"
                            :disabled="syncingId === vendor.id"
                            @click="syncVendor(vendor)"
                        >
                            <Loader2
                                v-if="syncingId === vendor.id"
                                class="animate-spin"
                            />
                            <RefreshCw v-else />
                        </Button>
                    </div>
                </TableCell>
            </TableRow>
            <TableRow v-if="data.length === 0">
                <TableCell colspan="6">No vendors found!</TableCell>
            </TableRow>
        </TableBody>
    </Table>
</template>
