<script setup>
import { useToast } from "@/Components/ui/toast/use-toast";
import { useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const { toast } = useToast();

const props = defineProps({
    data: Object,
});

const openDeleteDialog = ref(false);

const deleteSelectedForm = useForm({ ownersId: [] });
const ownersId = ref([]);
const selectAllCheckbox = ref(false);

const selectAll = () => {
    if (selectAllCheckbox.value) {
        ownersId.value = props.data.map((owner) => String(owner.id));
    } else {
        ownersId.value = [];
    }
};

const submitBulkDeleteForm = () => {
    deleteSelectedForm.ownersId = ownersId.value;
    deleteSelectedForm.post(route("owners.bulkdelete"), {
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
            ownersId.value = [];
            deleteSelectedForm.reset();
            selectAllCheckbox.value = false;
            openDeleteDialog.value = false;
            toast({
                title: "Success.",
                description: "Selected owners has been deleted.",
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
        v-if="ownersId.length > 0"
        ><Trash2 class="w-4 h-4" />Delete ({{ ownersId.length }})</Button
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
                <TableHead class="hidden md:table-cell"> Status </TableHead>
                <TableHead class="hidden md:table-cell"> Address </TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="owner in data" :key="owner.id">
                <TableCell>
                    <input
                        :value="owner.id"
                        v-model="ownersId"
                        type="checkbox"
                        class="checkbox checkbox-sm"
                    />
                </TableCell>
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

    <Dialog v-model:open="openDeleteDialog">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Are you sure?</DialogTitle>
                <DialogDescription>
                    You're about to delete
                    {{ ownersId.length }} owners?
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
