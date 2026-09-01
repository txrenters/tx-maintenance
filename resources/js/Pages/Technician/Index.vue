<script setup>
import { computed, ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { router, useForm } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import { PlusCircle, Loader2, Phone, Mail } from "lucide-vue-next";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import { Switch } from "@/Components/ui/switch";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
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
    technicians: Array,
    roles: Object,
});

const { toast } = useToast();

const dialogOpen = ref(false);
const deleteOpen = ref(false);
const editingId = ref(null);

const blankForm = () => ({
    name: "",
    role: "repair",
    is_active: true,
    phone: "",
    email: "",
    specialty: "",
    notes: "",
});

const form = useForm(blankForm());
const deleteForm = useForm({});

// Send empty optional fields as null so the profile stays clean.
form.transform((data) => ({
    ...data,
    phone: data.phone || null,
    email: data.email || null,
    specialty: data.specialty || null,
    notes: data.notes || null,
}));

const editingTechnician = computed(() =>
    props.technicians.find((technician) => technician.id === editingId.value),
);

const openCreate = () => {
    editingId.value = null;
    form.clearErrors();
    Object.assign(form, blankForm());
    dialogOpen.value = true;
};

const openProfile = (technician) => {
    editingId.value = technician.id;
    form.clearErrors();
    Object.assign(form, {
        name: technician.name,
        role: technician.role,
        is_active: technician.is_active,
        phone: technician.phone ?? "",
        email: technician.email ?? "",
        specialty: technician.specialty ?? "",
        notes: technician.notes ?? "",
    });
    dialogOpen.value = true;
};

const submit = () => {
    const options = {
        preserveScroll: true,
        only: ["technicians"],
        onSuccess: () => {
            dialogOpen.value = false;
            toast({
                title: editingId.value ? "Profile saved" : "Technician added",
            });
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Check the form",
                description: "Some fields need attention.",
            });
        },
    };

    if (editingId.value) {
        form.put(route("technicians.update", editingId.value), options);
    } else {
        form.post(route("technicians.store"), options);
    }
};

const destroy = () => {
    deleteForm.delete(route("technicians.destroy", editingId.value), {
        preserveScroll: true,
        only: ["technicians"],
        onSuccess: () => {
            deleteOpen.value = false;
            dialogOpen.value = false;
            toast({ title: "Technician removed" });
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Could not remove",
                description: "Try again in a moment.",
            });
        },
    });
};

// ---- Photo (sent to the tenant with the appointment text) ----
// Uploaded straight from the profile modal, separate from the fields form.
const photoInput = ref(null);
const photoBusy = ref(false);

const uploadPhoto = (event) => {
    const file = event.target.files?.[0];
    if (!file || !editingId.value) return;

    photoBusy.value = true;
    router.post(
        route("technicians.photo.update", editingId.value),
        { photo: file },
        {
            forceFormData: true,
            preserveScroll: true,
            only: ["technicians"],
            onSuccess: () => toast({ title: "Photo saved" }),
            onError: (errors) =>
                toast({
                    variant: "destructive",
                    title: "Photo not saved",
                    description:
                        errors.photo ?? "Use a JPG or PNG image up to 2 MB.",
                }),
            onFinish: () => {
                photoBusy.value = false;
                if (photoInput.value) photoInput.value.value = "";
            },
        },
    );
};

const removePhoto = () => {
    if (!editingId.value) return;

    photoBusy.value = true;
    router.delete(route("technicians.photo.destroy", editingId.value), {
        preserveScroll: true,
        only: ["technicians"],
        onSuccess: () => toast({ title: "Photo removed" }),
        onFinish: () => (photoBusy.value = false),
    });
};
</script>

<template>
    <Head :title="title" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-2xl text-sm text-muted-foreground">
            The in-house technician roster. Click a card to open the profile.
            When a technician is picked on a service schedule, the tenant's
            appointment text carries their name and photo.
        </p>
        <Button size="sm" class="h-7 gap-1" @click="openCreate">
            <PlusCircle class="h-3.5 w-3.5" />
            <span class="whitespace-nowrap">Add technician</span>
        </Button>
    </div>

    <div
        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
    >
        <button
            v-for="technician in technicians"
            :key="technician.id"
            type="button"
            class="flex flex-col items-center gap-2 rounded-xl border bg-card p-5 text-center shadow-sm transition hover:shadow-md hover:border-primary/40"
            :class="{ 'opacity-60': !technician.is_active }"
            @click="openProfile(technician)"
        >
            <img
                v-if="technician.photo_url"
                :src="technician.photo_url"
                :alt="`${technician.name} photo`"
                class="h-24 w-24 rounded-full border object-cover"
            />
            <div
                v-else
                class="flex h-24 w-24 items-center justify-center rounded-full border bg-muted text-2xl font-semibold text-muted-foreground"
            >
                {{ technician.initials }}
            </div>

            <div class="mt-1">
                <p class="font-semibold leading-tight">{{ technician.name }}</p>
                <p
                    v-if="technician.specialty"
                    class="mt-0.5 text-xs text-muted-foreground"
                >
                    {{ technician.specialty }}
                </p>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-1">
                <Badge variant="secondary">{{ technician.role_label }}</Badge>
                <Badge v-if="!technician.is_active" variant="outline">
                    Inactive
                </Badge>
                <Badge v-if="!technician.has_photo" variant="outline">
                    No photo
                </Badge>
            </div>

            <div
                class="flex flex-col items-center gap-0.5 text-xs text-muted-foreground"
            >
                <span
                    v-if="technician.phone"
                    class="flex items-center gap-1"
                >
                    <Phone class="h-3 w-3" /> {{ technician.phone }}
                </span>
                <span
                    v-if="technician.email"
                    class="flex items-center gap-1"
                >
                    <Mail class="h-3 w-3" /> {{ technician.email }}
                </span>
            </div>
        </button>

        <div
            v-if="!technicians.length"
            class="col-span-full rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            No technicians yet. Add the first one to start attaching their
            photo to tenant appointment texts.
        </div>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>
                    {{ editingId ? editingTechnician?.name ?? "Profile" : "Add technician" }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        editingId
                            ? "The profile and the photo tenants see."
                            : "Add them first, then upload their photo."
                    }}
                </DialogDescription>
            </DialogHeader>

            <!-- Photo: attached to the tenant's appointment text when this
                 technician is picked on a service schedule. Saved on its own,
                 not with the fields form, so it exists only when editing. -->
            <div v-if="editingId" class="rounded-md border p-3">
                <p class="text-sm font-medium">Photo sent to tenants</p>
                <p class="mb-2 text-xs text-muted-foreground">
                    Attached to the tenant's appointment text. JPG or PNG, up
                    to 2 MB. Saved immediately.
                </p>
                <div class="flex items-center gap-3">
                    <img
                        v-if="editingTechnician?.photo_url"
                        :src="editingTechnician.photo_url"
                        :alt="`${editingTechnician.name} photo`"
                        class="h-16 w-16 rounded-full border object-cover"
                    />
                    <div
                        v-else
                        class="flex h-16 w-16 items-center justify-center rounded-full border bg-muted text-sm text-muted-foreground"
                    >
                        {{ editingTechnician?.initials }}
                    </div>
                    <div class="flex flex-col gap-2">
                        <input
                            ref="photoInput"
                            type="file"
                            accept="image/jpeg,image/png"
                            class="text-xs"
                            :disabled="photoBusy"
                            @change="uploadPhoto"
                        />
                        <Button
                            v-if="editingTechnician?.has_photo"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="w-fit"
                            :disabled="photoBusy"
                            @click="removePhoto"
                        >
                            <Loader2
                                v-if="photoBusy"
                                class="mr-1 h-3.5 w-3.5 animate-spin"
                            />
                            Remove photo
                        </Button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="space-y-1 sm:col-span-2">
                    <Label>Name <span class="text-red-500">*</span></Label>
                    <Input v-model="form.name" placeholder="Full name" />
                    <p v-if="form.errors.name" class="text-xs text-red-500">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="space-y-1">
                    <Label>Role</Label>
                    <Select v-model="form.role">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem
                                    v-for="(label, value) in roles"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </div>

                <div class="space-y-1">
                    <Label>Job title / specialty</Label>
                    <Input
                        v-model="form.specialty"
                        placeholder="e.g. HVAC and electrical"
                    />
                </div>

                <div class="space-y-1">
                    <Label>Phone</Label>
                    <Input
                        v-model="form.phone"
                        placeholder="Internal use only"
                    />
                    <p v-if="form.errors.phone" class="text-xs text-red-500">
                        {{ form.errors.phone }}
                    </p>
                </div>

                <div class="space-y-1">
                    <Label>Email</Label>
                    <Input
                        v-model="form.email"
                        placeholder="Internal use only"
                    />
                    <p v-if="form.errors.email" class="text-xs text-red-500">
                        {{ form.errors.email }}
                    </p>
                </div>

                <div class="space-y-1 sm:col-span-2">
                    <Label>Bio</Label>
                    <Textarea
                        v-model="form.notes"
                        rows="3"
                        placeholder="A few lines about them - experience, certifications, anything the office should know."
                    />
                </div>

                <div class="flex items-center gap-2 sm:col-span-2">
                    <Switch v-model="form.is_active" />
                    <Label>Active</Label>
                    <span class="text-xs text-muted-foreground">
                        Only active technicians appear in the schedule picker.
                    </span>
                </div>
            </div>

            <DialogFooter class="mt-2 flex items-center gap-2">
                <Button
                    v-if="editingId"
                    type="button"
                    variant="destructive"
                    class="mr-auto"
                    @click="deleteOpen = true"
                >
                    Remove
                </Button>
                <Button type="button" variant="outline" @click="dialogOpen = false">
                    Cancel
                </Button>
                <Button :disabled="form.processing" @click.prevent="submit">
                    <Loader2
                        v-if="form.processing"
                        class="mr-1 h-4 w-4 animate-spin"
                    />
                    {{ editingId ? "Save profile" : "Add technician" }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <AlertDialog v-model:open="deleteOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>
                    Remove {{ editingTechnician?.name }}?
                </AlertDialogTitle>
                <AlertDialogDescription>
                    This deletes the profile. If they are only away for a
                    while, switch them to inactive instead so their profile
                    and photo stay intact.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Keep</AlertDialogCancel>
                <AlertDialogAction
                    class="destructive"
                    :disabled="deleteForm.processing"
                    @click.prevent="destroy"
                >
                    <Loader2
                        v-if="deleteForm.processing"
                        class="mr-1 h-4 w-4 animate-spin"
                    />
                    Remove
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
