<script setup>
import { ref, watch, onMounted, computed } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import {
  Loader2,
  Camera,
  File,
  FileText,
  FileSpreadsheet,
  Download,
} from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import FilesInvoice from "./FilesInvoice.vue";
import CameraModal from "./CameraModal.vue";
import ImageCropper from "./ImageCropper.vue";

const { toast } = useToast();

const props = defineProps({
  workOrderInvoices: Object,
  isLoading: Boolean,
  workOrder: Object,
});

const emit = defineEmits(["fetch-invoices"]);

const openCameraModal = ref(false);
const openAttachmentModal = ref(false);

const attachmentForm = useForm({
  title: "",
  amount: "",
  filename: "",
  work_order_id: props.workOrder.id,
});

const updateInvoiceForm = useForm({
  id: "",
  status: "",
});

const openCropper = ref(false);
const selectedImage = ref("");

const handleCapturedImage = (capturedImage) => {
  selectedImage.value = "";
  if (!capturedImage?.blob) return; // Ensure blob exists

  const reader = new FileReader();
  reader.onload = (e) => {
    selectedImage.value = e.target.result;
    openCropper.value = true; // Show the crop modal
  };

  reader.readAsDataURL(capturedImage.blob); // Directly read blob
};

const openExpandModal = ref(false);
const expandedImage = ref("");
const expandedImageName = ref("");

const handleExpandImage = (imageSelected) => {
  expandedImage.value = imageSelected.invoice_url;
  expandedImageName.value = imageSelected.title;
  openExpandModal.value = true;
};

const openDeleteModal = ref(false);

const handleUpdateInvoice = (invoice, status) => {
  updateInvoiceForm.id = invoice.id;
  updateInvoiceForm.status = status;
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
    onError: () => {
      toast({
        variant: "destructive",
        title: "Uh oh! Something went wrong.",
        description: "There was a problem with your request. Please try again!",
      });
    },
  });
};

const handleFetchInvoices = () => {
  emit("fetch-invoices");
};
</script>

<template>
  <div class="overflow-y-auto px-6 w-full min-h-[300px] mb-10">
    <div class="flex justify-between gap-2 items-center mb-3">
      <div>
        <p class="font-semibold uppercase text-xs mb-3">Invoices</p>
      </div>
      <div class="flex gap-2">
        <Button
          :disabled="isLoading"
          size="icon"
          @click.prevent="openAttachmentModal = true"
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
      />
    </div>
  </div>
  <CameraModal
    :show="openCameraModal"
    @update:show="openCameraModal = $event"
    @capturedImage="handleCapturedImage"
  />
  <ImageCropper
    :show="openCropper"
    :workOrder_id="workOrder.id"
    :image="selectedImage"
    @fetch-attachments="handleFetchAttachment"
    @update:show="openCropper = $event"
  />
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
