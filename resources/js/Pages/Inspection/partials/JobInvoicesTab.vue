<script setup>
/**
 * Invoices tab for a Jobber job, mirroring the work order Invoice tab: an
 * upload button top-right opening a dialog (title / amount / vendor / file),
 * and a card per invoice with a three-dot approve / decline / archive menu.
 *
 * Self-contained so the board modal and the full job page render exactly the
 * same thing. Emits `saved` after a successful write so the modal — which keeps
 * job data in local state rather than Inertia props — can refetch.
 */
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { DateTime } from "luxon";
import { EllipsisVertical, Loader2, Receipt } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Separator } from "@/Components/ui/separator";
import { Card } from "@/Components/ui/card";
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
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";
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

const props = defineProps({
    jobId: [Number, String],
    invoices: { type: Array, default: () => [] },
    vendors: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(["saved"]);

const { toast } = useToast();

const openUploadModal = ref(false);
const isUploading = ref(false);
const form = ref({ title: "", amount: "", vendor_id: "", filename: null });
const fileInputKey = ref(0);

const formatDate = (date) => {
    if (!date) return "------";
    const parsed =
        typeof date === "string" && date.includes("T")
            ? DateTime.fromISO(date, { zone: "utc" })
            : DateTime.fromFormat(String(date), "yyyy-MM-dd HH:mm:ss", {
                  zone: "utc",
              });
    return parsed.isValid ? parsed.toFormat("MM/dd/yyyy") : "------";
};

const isImage = (invoice) =>
    /\.(jpe?g|png|gif|webp)$/i.test(String(invoice.url || ""));

const resetForm = () => {
    form.value = { title: "", amount: "", vendor_id: "", filename: null };
    fileInputKey.value++;
};

const submit = () => {
    if (!form.value.title.trim() || !form.value.filename) {
        toast({
            variant: "destructive",
            title: "Error",
            description: "Add a title and choose a file.",
        });
        return;
    }

    const formData = new FormData();
    formData.append("title", form.value.title);
    formData.append("amount", form.value.amount || 0);
    if (form.value.vendor_id) formData.append("vendor_id", form.value.vendor_id);
    formData.append("filename", form.value.filename);

    isUploading.value = true;
    router.post(route("jobber.invoices.store", props.jobId), formData, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({ title: "Success", description: "Invoice uploaded." });
            openUploadModal.value = false;
            resetForm();
            emit("saved");
        },
        onError: () =>
            toast({
                variant: "destructive",
                title: "Error",
                description: "Failed to upload the invoice.",
            }),
        onFinish: () => (isUploading.value = false),
    });
};

// Invoices arrive already approved, matching the work-order flow; this is the
// correction afterwards, not a gate in front of the upload.
const updateStatus = (id, status) => {
    router.patch(
        route("jobber.invoices.update", id),
        { status },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Updated",
                    description: `Invoice marked as ${
                        status === "decline" ? "declined" : "approved"
                    }.`,
                });
                emit("saved");
            },
        }
    );
};

// Archiving hides the invoice but keeps the row and the uploaded file.
const archiveOpen = ref(false);
const archiving = ref(null);
const isArchiving = ref(false);

const askArchiveInvoice = (invoice) => {
    archiving.value = invoice;
    archiveOpen.value = true;
};

const confirmArchiveInvoice = () => {
    if (!archiving.value) {
        return;
    }

    isArchiving.value = true;

    router.delete(route("jobber.invoices.destroy", archiving.value.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({ title: "Archived", description: "Invoice archived." });
            archiveOpen.value = false;
            archiving.value = null;
            emit("saved");
        },
        onFinish: () => {
            isArchiving.value = false;
        },
    });
};
</script>

<template>
    <div>
        <div v-if="canManage" class="flex justify-end gap-2 items-center mb-3">
            <Button
                size="icon"
                :disabled="isUploading"
                title="Upload invoice"
                @click.prevent="openUploadModal = true"
            >
                <Receipt v-if="!isUploading" />
                <Loader2 v-else class="w-4 h-4 animate-spin" />
            </Button>
        </div>

        <p class="font-semibold uppercase mb-4 p-2 bg-primary text-white">
            Invoices
        </p>

        <div v-if="invoices.length" class="flex flex-col gap-3">
            <Card
                v-for="invoice in invoices"
                :key="invoice.id"
                class="flex gap-3 p-3 items-start"
            >
                <a
                    :href="invoice.url"
                    target="_blank"
                    class="w-20 h-20 shrink-0 border flex items-center justify-center"
                    title="View invoice"
                >
                    <img
                        :src="isImage(invoice) ? invoice.url : '/icons/pdf.png'"
                        :alt="invoice.title"
                        class="w-full h-full object-contain"
                    />
                </a>

                <div class="flex flex-col gap-1 flex-grow">
                    <p class="mt-2 font-semibold">
                        Invoice: {{ invoice.title }}
                    </p>
                    <p class="text-sm">Amount: ${{ invoice.amount }}</p>
                    <p class="text-xs">
                        <Badge
                            :variant="
                                invoice.status === 'decline' ? 'destructive' : ''
                            "
                        >
                            {{
                                invoice.status === "decline"
                                    ? "declined"
                                    : invoice.status
                            }}
                        </Badge>
                    </p>
                    <p class="text-sm">
                        Vendor: {{ invoice.vendor_name ?? "N/A" }}
                    </p>
                    <p class="text-sm">
                        Date: {{ formatDate(invoice.created_at) }}
                    </p>
                </div>

                <div class="flex flex-col gap-1" v-if="canManage">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button size="icon" variant="ghost">
                                <EllipsisVertical class="w-3 h-3" />
                                <span class="sr-only">Toggle menu</span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                            <DropdownMenuItem
                                class="cursor-pointer hover:bg-secondary"
                                @click="updateStatus(invoice.id, 'approved')"
                            >
                                Mark as Approved
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                class="cursor-pointer hover:bg-secondary"
                                @click="updateStatus(invoice.id, 'decline')"
                            >
                                Mark as Declined
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="cursor-pointer hover:bg-destructive hover:text-white text-destructive"
                                @click="askArchiveInvoice(invoice)"
                            >
                                Archive Invoice
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </Card>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            No invoices uploaded yet.
        </p>

        <Dialog v-model:open="openUploadModal">
            <DialogContent
                class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
            >
                <DialogHeader class="p-6 pb-0 text-left">
                    <DialogTitle>Upload Invoice</DialogTitle>
                    <DialogDescription>
                        Fill in the details and then click submit.
                    </DialogDescription>
                </DialogHeader>
                <Separator />
                <div
                    class="flex flex-col flex-nowrap overflow-x-auto scrollbar-hide px-6"
                >
                    <div class="mb-3">
                        <Label>Title</Label>
                        <Input
                            type="text"
                            placeholder="Enter invoice title"
                            v-model="form.title"
                        />
                    </div>
                    <div class="mb-3">
                        <Label>Amount</Label>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="Enter invoice amount"
                            v-model="form.amount"
                        />
                    </div>
                    <div class="mb-3">
                        <Label>File</Label>
                        <Input
                            :key="fileInputKey"
                            type="file"
                            accept=".jpg, .jpeg, .png, .pdf"
                            @change="form.filename = $event.target.files[0]"
                        />
                    </div>
                    <div class="mb-3" v-if="vendors.length">
                        <Label>Vendor</Label>
                        <Select v-model="form.vendor_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Select a vendor" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectItem
                                        v-for="vendor in vendors"
                                        :key="vendor.id"
                                        :value="String(vendor.id)"
                                    >
                                        {{ vendor.name }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <DialogFooter class="p-6 pt-0">
                    <Button
                        variant="destructive"
                        @click="openUploadModal = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="isUploading"
                        @click.prevent="submit"
                    >
                        <Loader2
                            v-if="isUploading"
                            class="w-4 h-4 animate-spin"
                        />
                        Submit
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <AlertDialog v-model:open="archiveOpen">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Archive invoice "{{ archiving?.title }}"?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        This hides the invoice from the job. The file is kept,
                        so the office can restore it later from the Invoices
                        page.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Keep</AlertDialogCancel>
                    <AlertDialogAction
                        class="destructive"
                        :disabled="isArchiving"
                        @click.prevent="confirmArchiveInvoice"
                    >
                        <Loader2
                            v-if="isArchiving"
                            class="mr-1 h-4 w-4 animate-spin"
                        />
                        Archive
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </div>
</template>
