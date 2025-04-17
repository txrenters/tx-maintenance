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
                <TableHead class="hidden md:table-cell"> Address </TableHead>
                <!-- <TableHead>
          <span class="sr-only">Actions</span>
        </TableHead> -->
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="tenant in data" :key="tenant.id">
                <TableCell class="font-medium">
                    {{ tenant.name }}
                    <p class="text-xs font-normal">{{ tenant.email }}</p>
                    <p class="text-xs font-normal md:hidden">
                        {{ tenant.mobile_phone }}
                    </p>
                    <p class="text-xs font-normal md:hidden">
                        {{ tenant.home_phone }}
                    </p>
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ tenant.mobile_phone }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ tenant.home_phone }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ tenant.address }}
                </TableCell>
                <TableCell>
                    <Link
                        method="delete"
                        :href="route('tenants.destroy', tenant.id)"
                        class="hover:text-red-500 p-0"
                        preserve-state
                        preserve-scroll
                        @click="
                            () => {
                                toast({
                                    title: 'Success.',
                                    description: 'Tenant deleted.',
                                });
                            }
                        "
                        ><Trash2 class="w-4 h-4"
                    /></Link>
                </TableCell>
            </TableRow>
            <TableRow v-if="data.length === 0">
                <TableCell colspan="4">No tenants found!</TableCell>
            </TableRow>
        </TableBody>
    </Table>
</template>
