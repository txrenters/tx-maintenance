<script setup>
import { ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { Loader2, File } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import FilesInvoice from "./FilesInvoice.vue";

const { toast } = useToast();

const props = defineProps({
    workOrderInvoices: Object,
    isLoading: Boolean,
    workOrder: Object,
});

const emit = defineEmits(["fetch-invoices"]);

const openAttachmentModal = ref(false);

const attachmentForm = useForm({
    title: "",
    amount: "",
    filename: "",
    vendor_id: "",
    is_publish_to_owner_portal: "No",
    is_publish_to_tenant_portal: "No",
    work_order_id: props.workOrder.id,
});

const updateInvoiceForm = useForm({
    id: "",
    status: "",
});

const openExpandModal = ref(false);
const expandedImage = ref("");
const expandedImageName = ref("");

const handleExpandImage = (imageSelected) => {
    expandedImage.value = imageSelected.invoice_url;
    expandedImageName.value = imageSelected.title;
    openExpandModal.value = true;
};

const handleUpdateInvoice = (invoice, status) => {
    updateInvoiceForm.id = invoice.id;
    updateInvoiceForm.status = status;

    console.log(updateInvoiceForm);
    updateInvoiceForm.post(route("api.invoices.update", updateInvoiceForm.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Invoice has been updated successfully!",
            });
            updateInvoiceForm.reset();
            handleFetchInvoices();
        },
        onError: (errors) => {
            console.error('Invoice update error:', errors);
            const errorMessage = errors.error || Object.values(errors)[0] || "There was a problem updating the invoice. Please try again!";
            toast({
                variant: "destructive",
                title: "Error updating invoice",
                description: errorMessage,
            });
        },
    });
};

const handleFormSubmit = () => {
    if (
        !attachmentForm.title ||
        !attachmentForm.amount ||
        !attachmentForm.filename
    ) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please try again!",
        });
        return;
    }
    attachmentForm.post(route("api.invoices.store"), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Invoice has been sent successfully!",
            });
            openAttachmentModal.value = false;
            attachmentForm.reset();
            handleFetchInvoices();
        },
        onError: (errors) => {
            console.error('Invoice upload error:', errors);
            const errorMessage = errors.error || Object.values(errors)[0] || "There was a problem with your request. Please try again!";
            toast({
                variant: "destructive",
                title: "Error uploading invoice",
                description: errorMessage,
            });
        },
    });
};

const handleFetchInvoices = () => {
    emit("fetch-invoices");
};

const handleDeleteInvoice = (invoice) => {
    if (!confirm(`Are you sure you want to delete invoice "${invoice.title}"?`)) {
        return;
    }

    router.delete(route("api.invoices.destroy", invoice.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Invoice has been deleted successfully!",
            });
            handleFetchInvoices();
        },
        onError: (errors) => {
            console.error('Invoice delete error:', errors);
            const errorMessage = errors.error || Object.values(errors)[0] || "There was a problem deleting the invoice. Please try again!";
            toast({
                variant: "destructive",
                title: "Error deleting invoice",
                description: errorMessage,
            });
        },
    });
};
</script>

<template>
    <div class="overflow-y-auto px-6 w-full min-h-[300px] mb-10">
        <div class="flex justify-between gap-2 items-center mb-3">
            <div>
                <p class="font-semibold uppercase text-xs mb-3">Invoices</p>
            </div>
            <div
                class="flex gap-2"
                v-if="
                    $page.props.auth.user.roles.includes('admin') ||
                    $page.props.auth.user.roles.includes('woc') ||
                    $page.props.auth.user.roles.includes('vendor')
                "
            >
                <Button
                    :disabled="isLoading"
                    size="icon"
                    @click="openAttachmentModal = true"
                >
                    <File v-if="!isLoading" class="" />
                    <Loader2 v-else class="w-4 h-4 animate-spin" />
                </Button>
            </div>
        </div>
        <div class="mb-3">
            <FilesInvoice
                :files="workOrderInvoices"
                :loading="isLoading"
                @expandImage="handleExpandImage"
                @updateInvoice="handleUpdateInvoice"
                @deleteInvoice="handleDeleteInvoice"
            />
        </div>
    </div>
    <Dialog v-model:open="openAttachmentModal">
        <DialogContent
            class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle> Upload Invoice </DialogTitle>
                <DialogDescription>
                    Select pdf and image files only and then click
                    submit.</DialogDescription
                >
            </DialogHeader>
            <Separator />
            <div
                class="flex flex-col flex-nowrap overflow-x-auto scrollbar-hide px-6"
            >
                <div class="mb-3">
                    <Label>Title</Label>
                    <Input
                        type="text"
                        placeholder="Enter file description"
                        v-model="attachmentForm.title"
                    />
                </div>
                <div class="mb-3">
                    <Label>Amount</Label>
                    <Input
                        type="number"
                        placeholder="Enter invoice amount"
                        v-model="attachmentForm.amount"
                    />
                </div>
                <div class="mb-3">
                    <Label>File</Label>
                    <Input
                        type="file"
                        accept=".jpg, .jpeg, .png, .gif, .pdf, .doc, .docx, .xls, .xlsx"
                        @input="
                            attachmentForm.filename = $event.target.files[0]
                        "
                    />
                </div>
                <div
                    class="mb-3"
                    v-if="
                        ($page.props.auth.user.roles.includes('admin') ||
                            $page.props.auth.user.roles.includes('woc')) &&
                        workOrder.vendors &&
                        workOrder.vendors.length > 0
                    "
                >
                    <Label>Vendor</Label>
                    <Select v-model="attachmentForm.vendor_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a vendor" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem
                                    v-for="vendor in workOrder.vendors"
                                    :key="vendor.id"
                                    :value="String(vendor.id)"
                                >
                                    {{ vendor.name }}
                                </SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </div>
                <Progress
                    v-if="attachmentForm.progress"
                    :value="attachmentForm.progress.percentage"
                    :model-value="attachmentForm.progress.percentage"
                >
                    {{ attachmentForm.progress.percentage }}%
                </Progress>
                <div class="mb-3">
                    <Label>Publish to Tenant Portal</Label>
                    <RadioGroup
                        default-value="comfortable"
                        class="flex gap-5 mt-2"
                        v-model="attachmentForm.is_publish_to_tenant_portal"
                    >
                        <div class="flex items-center space-x-2">
                            <RadioGroupItem id="r2" value="Yes" />
                            <Label for="r2">Yes</Label>
                        </div>
                        <div class="flex items-center space-x-2">
                            <RadioGroupItem id="r3" value="No" />
                            <Label for="r3">No</Label>
                        </div>
                    </RadioGroup>
                </div>
                <div class="mb-3">
                    <Label>Publish to Owner Portal</Label>
                    <RadioGroup
                        default-value="comfortable"
                        class="flex gap-5 mt-2"
                        v-model="attachmentForm.is_publish_to_owner_portal"
                    >
                        <div class="flex items-center space-x-2">
                            <RadioGroupItem id="r2" value="Yes" />
                            <Label for="r2">Yes</Label>
                        </div>
                        <div class="flex items-center space-x-2">
                            <RadioGroupItem id="r3" value="No" />
                            <Label for="r3">No</Label>
                        </div>
                    </RadioGroup>
                </div>
            </div>
            <DialogFooter class="p-6 pt-0">
                <Button
                    type="submit"
                    :disabled="attachmentForm.processing"
                    @click.prevent="handleFormSubmit"
                >
                    <Loader2
                        v-if="attachmentForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    Submit
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
    <Dialog v-model:open="openExpandModal">
        <DialogContent
            class="sm:max-w-[900px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle> Image Preview</DialogTitle>
                <DialogDescription> </DialogDescription>
            </DialogHeader>
            <Separator />
            <div
                class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide px-6"
            >
                <div class="mb-3 w-full">
                    <img :src="expandedImage" alt="" class="w-full mt-3" />
                    <Label>{{ expandedImageName }}</Label>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
