<script setup>
const emit = defineEmits(["openEditDialog", "openDeleteDialog"]);
import { useToast } from "@/Components/ui/toast/use-toast";

const { toast } = useToast();
defineProps({
    data: Object,
});

const openEditDialog = (user) => {
    emit("openEditDialog", true, user);
};

const openDeleteDialog = (user) => {
    emit("openDeleteDialog", true, user);
};
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

                <!-- <TableHead>
          <span class="sr-only">Actions</span>
        </TableHead> -->
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="owner in data" :key="owner.id">
                <TableCell class="font-medium">
                    {{ owner.name }}
                    <p class="text-xs font-normal">{{ owner.email }}</p>
                    <p class="text-xs font-normal">{{ owner.mobile }}</p>
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
                <TableCell>
                    <Link
                        method="delete"
                        :href="route('owners.destroy', owner.id)"
                        class="hover:text-red-500 p-0"
                        preserve-state
                        preserve-scroll
                        @click="
                            () => {
                                toast({
                                    title: 'Success.',
                                    description: 'Owner deleted.',
                                });
                            }
                        "
                        ><Trash2 class="w-4 h-4"
                    /></Link>
                </TableCell>
            </TableRow>
            <TableRow v-if="data.length === 0">
                <TableCell colspan="5">No owners found!</TableCell>
            </TableRow>
        </TableBody>
    </Table>
</template>
