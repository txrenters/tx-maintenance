<script setup>
import { ref, watch } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import TableData from "./Partials/TableData.vue";
import { useForm, router } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import { ScanSearch } from "lucide-vue-next";
import debounce from "lodash/debounce";
import {
    TagsInput,
    TagsInputInput,
    TagsInputItem,
    TagsInputItemDelete,
    TagsInputItemText,
} from "@/Components/ui/tags-input";
import Input from "@/Components/ui/input/Input.vue";
import axios from "axios";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    vendors: Object,
    twilio_numbers: Object,
    vendorTypes: Object,
    filter: Object,
});

const url = ref(route("vendors.index"));
const search = ref(props.filter.search);

const form = useForm({
    name: "",
    name_on_check: "",
    twilio_number: "",
    email: "",
    phone: "",
    address: "",
});

const isDialogOpen = ref(false);
const isCreateDialogOpen = ref(false);

const editForm = useForm({
    id: "",
    twilio_number: "",
    name: "",
    name_on_check: "",
    vendor_type: "",
    email: "",
    phone: "",
    address: "",
    zones: [],
});

const setEditForm = (vendor) => {
    editForm.id = String(vendor.id);
    editForm.name = vendor.name;
    editForm.name_on_check = vendor.name_on_check;
    editForm.twilio_number = vendor.twilio_number;
    editForm.email = vendor.email;
    editForm.vendor_type = vendor.vendor_type;
    editForm.phone = vendor.phone;
    editForm.address = vendor.address;
    editForm.zones = Array.isArray(vendor.zones) ? [...vendor.zones] : [];
};
const handleOpenDialog = (open, vendor) => {
    isDialogOpen.value = open;
    setEditForm(vendor);
};

const handleUpdate = () => {
    editForm.put(route("vendors.update", editForm.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            editForm.reset();
            toast({
                title: "Success",
                description: "Vendor has been updated successfully!",
            });
            isDialogOpen.value = false;
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
        only: ["vendors"],
    });
};

const handleStatusChange = (checked, vendor) => {
    router.put(
        route("vendors.change_status", vendor),
        { status: checked },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: `Vendor status updated to ${
                        checked ? "Active" : "Inactive"
                    }!`,
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
            only: ["vendors"],
        }
    );
};
const loader = ref(false);

const handleSubmit = () => {
    form.post(route("vendors.store"), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Vendors created successfully!",
            });
            form.reset();
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
        only: ["vendors"],
    });
};

const filter_vendor = ref("");
watch(
    filter_vendor,
    debounce(function (value) {
        const newQuery = { status: value }; //maintain url params
        router.visit(url.value, {
            method: "get",
            data: newQuery,
            preserveState: true,
            replace: true,
            preserveScroll: true,
        });
    }, 500)
);

const openImportVendor = ref(false);
const importVendorForm = useForm({
    vendors_name: "",
});
const handleImportVendor = () => {
    importVendorForm.processing = true;

    axios
        .post(route("vendors.import"), {
            vendors_name: importVendorForm.vendors_name,
        })
        .then((response) => {
            toast({
                title: "Success",
                description: "Vendor imported successfully!",
            });
            importVendorForm.reset();
            openImportVendor.value = false;
            importVendorForm.processing = false;
        })
        .catch((error) => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description: "Please try again in a few minutes",
            });
            importVendorForm.processing = false;
        });
};
</script>
<template>
    <Head :title="title" />
    <div class="ml-auto flex items-center gap-2">
        <div class="flex gap-2 items-center w-full">
            <Select
                :modelValue="String(filter_vendor)"
                @update:modelValue="(value) => (filter_vendor = value)"
            >
                <SelectTrigger class="w-full sm:w-[250px]">
                    <SelectValue placeholder="Select a vendor" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <SelectItem value="All"> All </SelectItem>
                        <SelectItem value="Active"> Active </SelectItem>
                        <SelectItem value="Inactive"> Inactive </SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>
        </div>
        <Button
            class="bg-primary px-3 py-3 rounded text-white hover:bg-primary/80"
            size="icon"
            title="Import Vendor"
            @click="openImportVendor = true"
            ><ScanSearch class="w-4 h-4" />
        </Button>
    </div>
    <Card>
        <CardHeader>
            <SearchBar :url="url" v-model="search" />
            <!-- <CardTitle>{{ title }}</CardTitle>
          <CardDescription> Manage your users and view their roles. </CardDescription> -->
        </CardHeader>
        <CardContent>
            <TableData
                :data="vendors.data"
                @isDialogOpen="handleOpenDialog"
                @statusChanged="handleStatusChange"
            />
        </CardContent>
        <CardFooter
            class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
        >
            <PaginationResultRange :data="vendors" />
            <Pagination :pagination="vendors.links" />
        </CardFooter>
    </Card>

    <Dialog v-model:open="isCreateDialogOpen">
        <DialogContent class="sm:max-w-[525px]">
            <DialogHeader>
                <DialogTitle>Create {{ title }}</DialogTitle>
                <DialogDescription>
                    Add new vendor here. Click create when you're done.
                </DialogDescription>
            </DialogHeader>
            <form id="dialogForm" @submit.prevent="handleSubmit">
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
                        <Label for="code">Contact Name</Label>
                        <Input
                            type="text"
                            class=""
                            v-model="form.name_on_check"
                        />
                        <Label class="mt-1 text-destructive text-xs">{{
                            form.errors.name_on_check
                        }}</Label>
                    </div>
                    <div class="mb-3 w-full">
                        <Label for="roles " class="mb-4">Assign Number</Label>
                        <Select class="mt-2" v-model="form.twilio_number">
                            <SelectTrigger>
                                <SelectValue placeholder="Select a number" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Numbers</SelectLabel>
                                    <SelectItem
                                        v-for="twilio in twilio_numbers"
                                        :value="String(twilio.phone_number)"
                                        :key="String(twilio.id)"
                                    >
                                        {{ twilio.name }} -
                                        {{ twilio.phone_number }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <Label class="mt-1 text-destructive text-xs">{{
                            form.errors.twilio_number
                        }}</Label>
                    </div>
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
                    @click.prevent="handleSubmit"
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

    <Dialog v-model:open="isDialogOpen">
        <DialogContent class="sm:max-w-[525px]">
            <DialogHeader>
                <DialogTitle>Edit {{ title }}</DialogTitle>
                <DialogDescription>
                    Edit new vendor here. Click save when you're done.
                </DialogDescription>
            </DialogHeader>
            <form id="dialogForm" @submit.prevent="handleUpdate">
                <div class="mb-3 flex flex-col gap-1">
                    <Label for="name">Name </Label>
                    <Input type="text" class="mt-2" v-model="editForm.name" />
                    <Label class="mt-1 text-destructive text-xs">{{
                        editForm.errors.name
                    }}</Label>
                </div>
                <div class="flex gap-3 mb-3">
                    <div class="mb-3 w-full flex flex-col gap-2">
                        <Label for="code" class="mb-1">Contact Name</Label>
                        <Input
                            type="text"
                            class=""
                            v-model="editForm.name_on_check"
                        />
                        <Label class="mt-1 text-destructive text-xs">{{
                            editForm.errors.name_on_check
                        }}</Label>
                    </div>
                    <div class="mb-3 w-full flex flex-col gap-2">
                        <Label for="roles " class="mb-1">Assign Number</Label>
                        <Select class="mt-2" v-model="editForm.twilio_number">
                            <SelectTrigger>
                                <SelectValue placeholder="Select a number" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Numbers</SelectLabel>
                                    <SelectItem
                                        v-for="twilio in twilio_numbers"
                                        :value="String(twilio.phone_number)"
                                        :key="String(twilio.id)"
                                    >
                                        {{ twilio.name }} -
                                        {{ twilio.phone_number }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <Label class="mt-1 text-destructive text-xs">{{
                            editForm.errors.twilio_number
                        }}</Label>
                    </div>
                </div>

                <div class="mb-3 w-full flex flex-col">
                    <Label for="roles " class="mb-4">Vendor Type</Label>
                    <Select class="mt-2" v-model="editForm.vendor_type">
                        <SelectTrigger>
                            <SelectValue placeholder="Select a vendor types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectLabel>Types</SelectLabel>
                                <SelectItem
                                    v-for="vendor in vendorTypes"
                                    :value="String(vendor.name)"
                                    :key="String(vendor.id)"
                                >
                                    {{ vendor.name }}
                                </SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <Label class="mt-1 text-destructive text-xs">{{
                        editForm.errors.twilio_number
                    }}</Label>
                </div>

                <div class="mb-3 w-full flex flex-col gap-2">
                    <Label for="zones">Zones</Label>
                    <TagsInput v-model="editForm.zones">
                        <TagsInputItem
                            v-for="item in editForm.zones"
                            :key="item"
                            :value="item"
                        >
                            <TagsInputItemText />
                            <TagsInputItemDelete />
                        </TagsInputItem>
                        <TagsInputInput placeholder="Type a zone and press Enter (e.g. 1, 2, 3)" />
                    </TagsInput>
                    <p class="text-xs text-muted-foreground">
                        Leave empty if this vendor serves all zones. Add specific zone numbers to limit recommendations to those zones.
                    </p>
                    <Label class="mt-1 text-destructive text-xs">{{
                        editForm.errors.zones
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
                    @click="isDialogOpen = false"
                >
                    Cancel</Button
                >
                <Button
                    type="submit"
                    :disabled="editForm.processing"
                    @click.prevent="handleUpdate"
                >
                    <Loader2
                        v-if="editForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    Save</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="openImportVendor">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Import Vendors</DialogTitle>
                <DialogDescription>
                    Enter vendors name and click import to save the data.
                </DialogDescription>
            </DialogHeader>

            <div class="my-2">
                <Label for="name">Vendor Name</Label>
                <Input
                    type="text"
                    class="mt-2"
                    v-model="importVendorForm.vendors_name"
                    placeholder="Enter vendor name"
                />
                <span class="text-xs text-destructive">{{
                    importVendorForm.errors.vendors_name
                }}</span>

                <div class="text-xs text-muted-foreground mt-2">
                    <p>
                        Note: Please ensure the name closely matches the one in
                        PW. This process may take some time depending on the
                        number of vendors.
                        <span v-if="importVendorForm.processing">
                            Please don't close...
                        </span>
                    </p>
                </div>
            </div>

            <DialogFooter>
                <Button
                    type="submit"
                    :disabled="importVendorForm.processing"
                    @click.prevent="handleImportVendor"
                >
                    <Loader2
                        v-if="importVendorForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    <div>
                        <span v-if="importVendorForm.processing">
                            Importing...
                        </span>
                        <span v-else>Import</span>
                    </div>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
