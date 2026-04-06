<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import { PlusCircle, Pencil, Trash2, PlusIcon, XIcon, Loader2 } from "lucide-vue-next";
import {
    TagsInput,
    TagsInputInput,
    TagsInputItem,
    TagsInputItemDelete,
    TagsInputItemText,
} from "@/Components/ui/tags-input";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import { Switch } from "@/Components/ui/switch";
import { Badge } from "@/Components/ui/badge";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardDescription,
} from "@/Components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from "@/Components/ui/dialog";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/Components/ui/alert-dialog";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    fallbackVendors: Array,
    vendors: Array,
});

const { toast } = useToast();

const isCreateDialogOpen = ref(false);
const isEditDialogOpen = ref(false);
const isDeleteDialogOpen = ref(false);
const selectedVendorId = ref(null);

const blankForm = () => ({
    vendor_id: null,
    notes: "",
    priority: 50,
    is_active: true,
    contacts: [{ name: "", phone: "", email: "" }],
    issue_types: [],
    keywords: [],
});

const form = useForm(blankForm());
const editForm = useForm({ id: null, ...blankForm() });
const deleteForm = useForm({ id: null });

const addContact = (targetForm) => {
    targetForm.contacts.push({ name: "", phone: "", email: "" });
};

const removeContact = (targetForm, index) => {
    if (targetForm.contacts.length > 1) {
        targetForm.contacts.splice(index, 1);
    }
};

const openEditDialog = (vendor) => {
    editForm.id = vendor.id;
    editForm.vendor_id = vendor.vendor_id ? String(vendor.vendor_id) : "";
    editForm.notes = vendor.notes ?? "";
    editForm.priority = vendor.priority;
    editForm.is_active = vendor.is_active;
    editForm.contacts = vendor.contacts.length
        ? vendor.contacts.map((c) => ({ name: c.name ?? "", phone: c.phone ?? "", email: c.email ?? "" }))
        : [{ name: "", phone: "", email: "" }];
    editForm.issue_types = [...vendor.issue_types];
    editForm.keywords = [...vendor.keywords];
    isEditDialogOpen.value = true;
};

const openDeleteDialog = (vendor) => {
    deleteForm.id = vendor.id;
    isDeleteDialogOpen.value = true;
};

const handleCreate = () => {
    form.post(route("fallback_vendors.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            Object.assign(form, blankForm());
            isCreateDialogOpen.value = false;
            toast({ title: "Success!", description: "Fallback vendor created successfully." });
        },
        onError: () => {
            toast({ variant: "destructive", title: "Error", description: "There was a problem with your request." });
        },
        only: ["fallbackVendors"],
    });
};

const handleUpdate = () => {
    editForm.put(route("fallback_vendors.update", editForm.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditDialogOpen.value = false;
            toast({ title: "Success!", description: "Fallback vendor updated successfully." });
        },
        onError: () => {
            toast({ variant: "destructive", title: "Error", description: "There was a problem with your request." });
        },
        only: ["fallbackVendors"],
    });
};

const handleDelete = () => {
    deleteForm.delete(route("fallback_vendors.destroy", deleteForm.id), {
        preserveScroll: true,
        onSuccess: () => {
            isDeleteDialogOpen.value = false;
            toast({ title: "Success!", description: "Fallback vendor deleted successfully." });
        },
        onError: () => {
            toast({ variant: "destructive", title: "Error", description: "There was a problem with your request." });
        },
        only: ["fallbackVendors"],
    });
};
</script>

<template>
    <Head :title="title" />

    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-muted-foreground">
            Fallback vendors are used when no matching vendor is found for a work order recommendation.
        </p>
        <Button size="sm" class="h-7 gap-1" @click="isCreateDialogOpen = true">
            <PlusCircle class="h-3.5 w-3.5" />
            <span class="sr-only sm:not-sr-only sm:whitespace-nowrap">Add Fallback Vendor</span>
        </Button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Card v-for="vendor in fallbackVendors" :key="vendor.id">
            <CardHeader class="pb-2">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2 flex-wrap">
                        <CardTitle class="text-base">{{ vendor.vendor?.name ?? 'Unknown Vendor' }}</CardTitle>
                        <Badge :variant="vendor.is_active ? 'default' : 'secondary'">
                            {{ vendor.is_active ? "Active" : "Inactive" }}
                        </Badge>
                    </div>
                    <div class="flex gap-1 shrink-0">
                        <Button variant="ghost" size="icon" class="h-7 w-7" @click="openEditDialog(vendor)">
                            <Pencil class="h-3.5 w-3.5" />
                        </Button>
                        <Button variant="ghost" size="icon" class="h-7 w-7 text-destructive" @click="openDeleteDialog(vendor)">
                            <Trash2 class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>
                <CardDescription class="text-xs">Priority: {{ vendor.priority }}</CardDescription>
            </CardHeader>
            <CardContent class="space-y-2 text-sm">
                <div v-if="vendor.notes" class="text-muted-foreground text-xs">{{ vendor.notes }}</div>

                <div>
                    <p class="font-medium text-xs mb-1">Contacts</p>
                    <div v-for="(contact, i) in vendor.contacts" :key="i" class="text-xs text-muted-foreground">
                        <span v-if="contact.name">{{ contact.name }} — </span>
                        <span v-if="contact.phone">{{ contact.phone }}</span>
                        <span v-if="contact.email"> · {{ contact.email }}</span>
                    </div>
                </div>

                <div>
                    <p class="font-medium text-xs mb-1">Issue Types</p>
                    <div class="flex flex-wrap gap-1">
                        <Badge v-for="type in vendor.issue_types" :key="type" variant="outline" class="text-xs">{{ type }}</Badge>
                    </div>
                </div>

                <div>
                    <p class="font-medium text-xs mb-1">Keywords</p>
                    <div class="flex flex-wrap gap-1">
                        <Badge v-for="kw in vendor.keywords" :key="kw" variant="secondary" class="text-xs">{{ kw }}</Badge>
                    </div>
                </div>
            </CardContent>
        </Card>

        <div v-if="!fallbackVendors.length" class="col-span-full text-center text-muted-foreground py-12">
            No fallback vendors found. Add one to get started.
        </div>
    </div>

    <!-- Create Dialog -->
    <Dialog v-model:open="isCreateDialogOpen">
        <DialogContent class="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Add Fallback Vendor</DialogTitle>
                <DialogDescription>
                    This vendor will be recommended when no matching active vendor is found.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <Label class="mb-1">Vendor</Label>
                        <Select v-model="form.vendor_id">
                            <SelectTrigger>
                                <SelectValue placeholder="Select an active vendor" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Vendors</SelectLabel>
                                    <SelectItem v-for="v in vendors" :key="v.id" :value="String(v.id)">
                                        {{ v.name }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <p v-if="form.errors.vendor_id" class="text-destructive text-xs mt-1">{{ form.errors.vendor_id }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Priority</Label>
                        <Input v-model.number="form.priority" type="number" min="1" max="255" placeholder="50" />
                        <p class="text-xs text-muted-foreground mt-1">Lower number = higher priority</p>
                        <p v-if="form.errors.priority" class="text-destructive text-xs mt-1">{{ form.errors.priority }}</p>
                    </div>
                    <div class="flex items-center gap-2 mt-5">
                        <Switch v-model="form.is_active" />
                        <Label>Active</Label>
                    </div>
                    <div class="col-span-2">
                        <Label class="mb-1">Notes</Label>
                        <Textarea v-model="form.notes" placeholder="When to use this vendor..." rows="2" />
                        <p v-if="form.errors.notes" class="text-destructive text-xs mt-1">{{ form.errors.notes }}</p>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <Label>Contacts</Label>
                        <Button type="button" variant="outline" size="sm" @click="addContact(form)">
                            <PlusIcon class="h-3.5 w-3.5 mr-1" /> Add Contact
                        </Button>
                    </div>
                    <div v-for="(contact, i) in form.contacts" :key="i" class="flex gap-2 mb-2 items-start">
                        <Input v-model="contact.name" placeholder="Name (optional)" class="flex-1" />
                        <Input v-model="contact.phone" placeholder="Phone" class="flex-1" />
                        <Input v-model="contact.email" placeholder="Email (optional)" class="flex-1" />
                        <Button type="button" variant="ghost" size="icon" class="shrink-0 text-destructive" @click="removeContact(form, i)" :disabled="form.contacts.length === 1">
                            <XIcon class="h-4 w-4" />
                        </Button>
                    </div>
                    <p v-if="form.errors.contacts" class="text-destructive text-xs mt-1">{{ form.errors.contacts }}</p>
                </div>

                <div>
                    <Label class="mb-1">Issue Types</Label>
                    <TagsInput v-model="form.issue_types">
                        <TagsInputItem v-for="type in form.issue_types" :key="type" :value="type">
                            <TagsInputItemText />
                            <TagsInputItemDelete />
                        </TagsInputItem>
                        <TagsInputInput placeholder="Type and press Enter..." />
                    </TagsInput>
                    <p v-if="form.errors.issue_types" class="text-destructive text-xs mt-1">{{ form.errors.issue_types }}</p>
                </div>

                <div>
                    <Label class="mb-1">Keywords</Label>
                    <TagsInput v-model="form.keywords">
                        <TagsInputItem v-for="kw in form.keywords" :key="kw" :value="kw">
                            <TagsInputItemText />
                            <TagsInputItemDelete />
                        </TagsInputItem>
                        <TagsInputInput placeholder="Type and press Enter..." />
                    </TagsInput>
                    <p v-if="form.errors.keywords" class="text-destructive text-xs mt-1">{{ form.errors.keywords }}</p>
                </div>
            </div>

            <DialogFooter class="flex gap-2 mt-4">
                <Button type="button" variant="outline" @click="isCreateDialogOpen = false">Cancel</Button>
                <Button :disabled="form.processing" @click.prevent="handleCreate">
                    <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin mr-1" />
                    Create
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Edit Dialog -->
    <Dialog v-model:open="isEditDialogOpen">
        <DialogContent class="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Edit Fallback Vendor</DialogTitle>
                <DialogDescription>Update the fallback vendor details.</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <Label class="mb-1">Vendor</Label>
                        <Select v-model="editForm.vendor_id">
                            <SelectTrigger>
                                <SelectValue placeholder="Select an active vendor" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Vendors</SelectLabel>
                                    <SelectItem v-for="v in vendors" :key="v.id" :value="String(v.id)">
                                        {{ v.name }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <p v-if="editForm.errors.vendor_id" class="text-destructive text-xs mt-1">{{ editForm.errors.vendor_id }}</p>
                    </div>
                    <div>
                        <Label class="mb-1">Priority</Label>
                        <Input v-model.number="editForm.priority" type="number" min="1" max="255" placeholder="50" />
                        <p class="text-xs text-muted-foreground mt-1">Lower number = higher priority</p>
                        <p v-if="editForm.errors.priority" class="text-destructive text-xs mt-1">{{ editForm.errors.priority }}</p>
                    </div>
                    <div class="flex items-center gap-2 mt-5">
                        <Switch v-model="editForm.is_active" />
                        <Label>Active</Label>
                    </div>
                    <div class="col-span-2">
                        <Label class="mb-1">Notes</Label>
                        <Textarea v-model="editForm.notes" placeholder="When to use this vendor..." rows="2" />
                        <p v-if="editForm.errors.notes" class="text-destructive text-xs mt-1">{{ editForm.errors.notes }}</p>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <Label>Contacts</Label>
                        <Button type="button" variant="outline" size="sm" @click="addContact(editForm)">
                            <PlusIcon class="h-3.5 w-3.5 mr-1" /> Add Contact
                        </Button>
                    </div>
                    <div v-for="(contact, i) in editForm.contacts" :key="i" class="flex gap-2 mb-2 items-start">
                        <Input v-model="contact.name" placeholder="Name (optional)" class="flex-1" />
                        <Input v-model="contact.phone" placeholder="Phone" class="flex-1" />
                        <Input v-model="contact.email" placeholder="Email (optional)" class="flex-1" />
                        <Button type="button" variant="ghost" size="icon" class="shrink-0 text-destructive" @click="removeContact(editForm, i)" :disabled="editForm.contacts.length === 1">
                            <XIcon class="h-4 w-4" />
                        </Button>
                    </div>
                    <p v-if="editForm.errors.contacts" class="text-destructive text-xs mt-1">{{ editForm.errors.contacts }}</p>
                </div>

                <div>
                    <Label class="mb-1">Issue Types</Label>
                    <TagsInput v-model="editForm.issue_types">
                        <TagsInputItem v-for="type in editForm.issue_types" :key="type" :value="type">
                            <TagsInputItemText />
                            <TagsInputItemDelete />
                        </TagsInputItem>
                        <TagsInputInput placeholder="Type and press Enter..." />
                    </TagsInput>
                    <p v-if="editForm.errors.issue_types" class="text-destructive text-xs mt-1">{{ editForm.errors.issue_types }}</p>
                </div>

                <div>
                    <Label class="mb-1">Keywords</Label>
                    <TagsInput v-model="editForm.keywords">
                        <TagsInputItem v-for="kw in editForm.keywords" :key="kw" :value="kw">
                            <TagsInputItemText />
                            <TagsInputItemDelete />
                        </TagsInputItem>
                        <TagsInputInput placeholder="Type and press Enter..." />
                    </TagsInput>
                    <p v-if="editForm.errors.keywords" class="text-destructive text-xs mt-1">{{ editForm.errors.keywords }}</p>
                </div>
            </div>

            <DialogFooter class="flex gap-2 mt-4">
                <Button type="button" variant="outline" @click="isEditDialogOpen = false">Cancel</Button>
                <Button :disabled="editForm.processing" @click.prevent="handleUpdate">
                    <Loader2 v-if="editForm.processing" class="w-4 h-4 animate-spin mr-1" />
                    Save Changes
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Delete Dialog -->
    <AlertDialog v-model:open="isDeleteDialogOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Are you absolutely sure?</AlertDialogTitle>
                <AlertDialogDescription>
                    This will permanently delete this fallback vendor. This action cannot be undone.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    class="destructive"
                    :disabled="deleteForm.processing"
                    @click.prevent="handleDelete"
                >
                    <Loader2 v-if="deleteForm.processing" class="w-4 h-4 animate-spin mr-1" />
                    Delete
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
