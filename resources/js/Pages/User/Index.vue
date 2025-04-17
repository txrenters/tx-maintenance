<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    users: Object,
    roles: Object,
    filter: Object,
});

const url = ref(route("users.index"));
const search = ref(props.filter.search);

const isCreateDialogOpen = ref(false);
const isEditDialogOpen = ref(false);
const isDeleteDialogOpen = ref(false);

const form = useForm({
    name: "",
    email: "",
    phone: "",
    company: "",
    website: "",
    address: "",
    role_id: "",
});

const editForm = useForm({
    _method: "PUT",
    id: "",
    name: "",
    email: "",
    phone: "",
    company: "",
    website: "",
    address: "",
    password: "",
    role_id: "",
});

const deleteForm = useForm({
    id: "",
});

const setEditForm = (user) => {
    editForm.id = user.id;
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.phone = user.phone;
    editForm.company = user.company;
    editForm.website = user.website;
    editForm.address = user.address;
    editForm.role_id = String(user.role_id);
};

const setDeleteForm = (schoolYear) => {
    deleteForm.id = schoolYear.id;
};

const handleCreateSubmit = () => {
    form.post(route("users.store_"), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            toast({
                title: "Success",
                description: "User has been created successfully!",
            });
            isCreateDialogOpen.value = false;
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
        only: ["users"],
    });
};

const handleUpdateSubmit = () => {
    editForm.put(route("users.update", editForm.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            editForm.reset();
            isEditDialogOpen.value = false;
            toast({
                title: "Success",
                description: "User has been updated successfully!",
            });
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
        only: ["users"],
    });
};

const handleDeleteSubmit = () => {
    deleteForm.delete(route("users.destroy", deleteForm.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            deleteForm.reset();
            toast({
                title: "Success",
                description: "User has been deleted successfully!",
            });
            isDeleteDialogOpen.value = false;
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
            isDeleteDialogOpen.value = false;
        },
        only: ["users"],
    });
};

const handleEditDialog = (open, user) => {
    isEditDialogOpen.value = open;
    setEditForm(user);
};

const handleAlertDialog = (open, user) => {
    isDeleteDialogOpen.value = open;
    setDeleteForm(user);
};
</script>
<template>
    <Head :title="title" />

    <div class="flex items-center">
        <div class="ml-auto flex items-center gap-2">
            <Button
                size="sm"
                class="h-7 gap-1"
                @click="isCreateDialogOpen = true"
            >
                <PlusCircle class="h-3.5 w-3.5" />
                <span class="sr-only sm:not-sr-only sm:whitespace-nowrap">
                    Add {{ title }}
                </span>
            </Button>
        </div>
    </div>
    <Card>
        <CardHeader>
            <SearchBar :url="url" v-model="search" />
        </CardHeader>
        <CardContent>
            <TableData
                :userData="users.data"
                @openEditDialog="handleEditDialog"
                @openDeleteDialog="handleAlertDialog"
            />
        </CardContent>
        <CardFooter
            class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
        >
            <PaginationResultRange :data="users" />
            <Pagination :pagination="users.links" />
        </CardFooter>
    </Card>

    <Dialog v-model:open="isCreateDialogOpen">
        <DialogContent class="sm:max-w-[525px]">
            <DialogHeader>
                <DialogTitle>Create {{ title }}</DialogTitle>
                <DialogDescription>
                    Add new user here. Click create when you're done.
                </DialogDescription>
            </DialogHeader>
            <form id="dialogForm" @submit="handleSubmit($event, onSubmit)">
                <div class="mb-3">
                    <Label for="name">Name </Label>
                    <Input type="text" class="mt-2" v-model="form.name" />
                    <Label class="mt-1 text-destructive text-xs">{{
                        form.errors.name
                    }}</Label>
                    <p class="text-xs mt-1">
                        Note: The password will be set to match the email
                        address.
                    </p>
                </div>
                <div class="flex gap-3">
                    <div class="mb-3 w-full">
                        <Label for="code">Email</Label>
                        <Input type="email" class="mt-2" v-model="form.email" />
                        <Label class="mt-1 text-destructive text-xs">{{
                            form.errors.email
                        }}</Label>
                    </div>
                    <div class="mb-3 w-full">
                        <Label for="code">Phone Number</Label>
                        <Input type="text" class="mt-2" v-model="form.phone" />
                        <Label class="mt-1 text-destructive text-xs">{{
                            form.errors.phone
                        }}</Label>
                    </div>
                </div>

                <div class="flex gap-3">
                    <div class="mb-3 w-full">
                        <Label for="code">Company</Label>
                        <Input
                            type="text"
                            class="mt-2"
                            v-model="form.company"
                        />
                        <Label class="mt-1 text-destructive text-xs">{{
                            form.errors.company
                        }}</Label>
                    </div>
                    <div class="mb-3 w-full">
                        <Label for="code">Website</Label>
                        <Input type="url" class="mt-2" v-model="form.website" />
                        <Label class="mt-1 text-destructive text-xs">{{
                            form.errors.website
                        }}</Label>
                    </div>
                </div>

                <div class="mb-3">
                    <Label for="roles" class="mb-2">Roles</Label>
                    <Select class="mt-2" v-model="form.role_id">
                        <SelectTrigger>
                            <SelectValue placeholder="Select a role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectLabel>Roles</SelectLabel>
                                <SelectItem
                                    v-for="role in roles"
                                    :value="String(role.id)"
                                    :key="role.id"
                                >
                                    {{ role.name }}
                                </SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <Label class="mt-1 text-destructive text-xs">{{
                        form.errors.role_id
                    }}</Label>
                </div>
                <div class="mb-3">
                    <Label for="code">Address</Label>
                    <Textarea v-model="form.address" />
                    <Label class="mt-1 text-destructive text-xs">{{
                        form.errors.address
                    }}</Label>
                </div>
            </form>
            <DialogFooter class="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    @click="isCreateDialogOpen = false"
                >
                    Cancel</Button
                >
                <Button
                    type="submit"
                    :disabled="form.processing"
                    @click.prevent="handleCreateSubmit"
                >
                    <Loader2
                        v-if="form.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    Create</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="isEditDialogOpen">
        <DialogContent class="sm:max-w-[525px]">
            <DialogHeader>
                <DialogTitle>Edit {{ title }}</DialogTitle>
                <DialogDescription>
                    Make changes to the user here. Click save when you're done.
                </DialogDescription>
            </DialogHeader>
            <form id="dialogForm" @submit="handleSubmit($event, onSubmit)">
                <div class="mb-3">
                    <Label for="name">Name </Label>
                    <Input type="text" class="mt-2" v-model="editForm.name" />
                    <Label class="mt-1 text-destructive text-xs">{{
                        editForm.errors.name
                    }}</Label>
                </div>
                <div class="flex gap-3">
                    <div class="mb-3 w-full">
                        <Label for="code">Email</Label>
                        <Input
                            type="email"
                            class="mt-2"
                            v-model="editForm.email"
                        />
                        <Label class="mt-1 text-destructive text-xs">{{
                            editForm.errors.email
                        }}</Label>
                    </div>
                    <div class="mb-3 w-full">
                        <Label for="code">Phone Number</Label>
                        <Input
                            type="text"
                            class="mt-2"
                            v-model="editForm.phone"
                        />
                        <Label class="mt-1 text-destructive text-xs">{{
                            editForm.errors.phone
                        }}</Label>
                    </div>
                </div>

                <div class="flex gap-3">
                    <div class="mb-3 w-full">
                        <Label for="code">Company</Label>
                        <Input
                            type="text"
                            class="mt-2"
                            v-model="editForm.company"
                        />
                        <Label class="mt-1 text-destructive text-xs">{{
                            editForm.errors.company
                        }}</Label>
                    </div>
                    <div class="mb-3 w-full">
                        <Label for="code">Website</Label>
                        <Input
                            type="url"
                            class="mt-2"
                            v-model="editForm.website"
                        />
                        <Label class="mt-1 text-destructive text-xs">{{
                            editForm.errors.website
                        }}</Label>
                    </div>
                </div>
                <div class="mb-3 w-full">
                    <Label for="code">Type New Password</Label>
                    <Input
                        type="text"
                        class="mt-2"
                        v-model="editForm.password"
                    />
                    <Label class="mt-1 text-destructive text-xs">{{
                        editForm.errors.password
                    }}</Label>
                    <p class="text-xs mt-1">Leave blank if no changes.</p>
                </div>
                <div class="mb-3">
                    <Label for="roles" class="mb-2">Roles</Label>
                    <Select class="mt-2" v-model="editForm.role_id">
                        <SelectTrigger>
                            <SelectValue placeholder="Select a role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectLabel>Roles</SelectLabel>
                                <SelectItem
                                    v-for="role in roles"
                                    :value="String(role.id)"
                                    :key="role.id"
                                >
                                    {{ role.name }}
                                </SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <Label class="mt-1 text-destructive text-xs">{{
                        editForm.errors.role_id
                    }}</Label>
                </div>
                <div class="mb-3">
                    <Label for="code">Address</Label>
                    <Textarea v-model="editForm.address" />
                    <Label class="mt-1 text-destructive text-xs">{{
                        editForm.errors.address
                    }}</Label>
                </div>
            </form>
            <DialogFooter class="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    @click="isEditDialogOpen = false"
                >
                    Cancel</Button
                >
                <Button
                    type="submit"
                    :disabled="editForm.processing"
                    @click.prevent="handleUpdateSubmit"
                >
                    <Loader2
                        v-if="editForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    Save changes</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <AlertDialog v-model:open="isDeleteDialogOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Are you absolutely sure?</AlertDialogTitle>
                <AlertDialogDescription>
                    This action cannot be undone. This will permanently delete
                    your account and remove your data from our servers.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    class="destructive"
                    :disabled="deleteForm.processing"
                    @click.prevent="handleDeleteSubmit"
                >
                    <Loader2
                        v-if="deleteForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    Continue
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
