<script setup>
import { useToast } from "@/Components/ui/toast/use-toast";
import { useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const { toast } = useToast();

const props = defineProps({
    data: Object,
});

const openDeleteDialog = ref(false);

const deleteSelectedForm = useForm({ tenantsId: [] });
const tenantsId = ref([]);
const selectAllCheckbox = ref(false);

const selectAll = () => {
    if (selectAllCheckbox.value) {
        tenantsId.value = props.data.map((tenant) => String(tenant.id));
    } else {
        tenantsId.value = [];
    }
};

const submitBulkDeleteForm = () => {
    deleteSelectedForm.tenantsId = tenantsId.value;
    deleteSelectedForm.post(route("tenants.bulkdelete"), {
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
            tenantsId.value = [];
            deleteSelectedForm.reset();
            selectAllCheckbox.value = false;
            openDeleteDialog.value = false;
            toast({
                title: "Success.",
                description: "Selected tenants has been deleted.",
            });
        },
    });
};
</script>
<template>
    <Button
        size="small"
        variant="destructive"
        class="p-2"
        @click="openDeleteDialog = true"
        v-if="tenantsId.length > 0"
        ><Trash2 class="w-4 h-4" />Delete ({{ tenantsId.length }})</Button
    >
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead>
                    <input
                        @change="selectAll"
                        v-model="selectAllCheckbox"
                        type="checkbox"
                        class="checkbox checkbox-sm"
                    />
                </TableHead>
                <TableHead>Name</TableHead>
                <TableHead class="hidden md:table-cell"> Mobile </TableHead>
                <TableHead class="hidden md:table-cell"> Phone </TableHead>
                <TableHead class="hidden md:table-cell"> Address </TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="tenant in data" :key="tenant.id">
                <TableCell>
                    <input
                        :value="tenant.id"
                        v-model="tenantsId"
                        type="checkbox"
                        class="checkbox checkbox-sm"
                    />
                </TableCell>
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

    <Dialog v-model:open="openDeleteDialog">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Are you sure?</DialogTitle>
                <DialogDescription>
                    You're about to delete
                    {{ tenantsId.length }} tenants?
                </DialogDescription>
            </DialogHeader>

            <div class="my-2">
                <p class="text-xs">Note: This action cannot be undone.</p>
            </div>

            <DialogFooter>
                <Button
                    variant="destructive"
                    type="submit"
                    :disabled="deleteSelectedForm.processing"
                    @click.prevent="submitBulkDeleteForm"
                >
                    <Loader2
                        v-if="deleteSelectedForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    <div>
                        <span v-if="deleteSelectedForm.processing">
                            Deleting...
                        </span>
                        <span v-else>Delete Now</span>
                    </div>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
