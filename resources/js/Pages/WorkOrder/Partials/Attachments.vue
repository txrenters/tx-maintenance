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
import Files from "./Files.vue";
import CameraModal from "./CameraModal.vue";
import ImageCropper from "./ImageCropper.vue";

const { toast } = useToast();

const props = defineProps({
  workOrderAttachments: Object,
  isLoading: Boolean,
  workOrder: Object,
});

const emit = defineEmits(["fetch-attachments"]);

const openCameraModal = ref(false);
const openAttachmentModal = ref(false);

const attachmentForm = useForm({
  title: "",
  type: "attachment",
  filename: "",
  work_order_id: props.workOrder.id,
});
const attachmentFile = computed(() => {
  return (props.workOrderAttachments || []).filter((file) => file?.type === "attachment");
});

const beforePics = computed(() => {
  return (props.workOrderAttachments || []).filter((file) => file?.type === "before");
});

const afterPics = computed(() => {
  return (props.workOrderAttachments || []).filter((file) => file?.type === "after");
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
const deleteFileForm = useForm({ id: "" });

const handleExpandImage = (imageSelected) => {
  expandedImage.value = imageSelected.attachment_url;
  expandedImageName.value = imageSelected.title;
  deleteFileForm.id = imageSelected.id;
  openExpandModal.value = true;
};

const openDeleteModal = ref(false);
const handleDeleteImage = (imageSelected) => {
  deleteFileForm.id = imageSelected;
  openDeleteModal.value = true;
};

const handleDeleteImageSubmit = () => {
  deleteFileForm.delete(route("api.attachments.destroy", deleteFileForm.id), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      toast({
        title: "Success",
        description: "Attachments has been deleted successfully!",
      });
      openExpandModal.value = false;
      openDeleteModal.value = false;
      deleteFileForm.reset();
      handleFetchAttachment();
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

const handleFetchAttachment = () => {
  emit("fetch-attachments");
};

const handleFormSubmit = () => {
  if (!attachmentForm.title || !attachmentForm.type || !attachmentForm.filename) {
    toast({
      variant: "destructive",
      title: "Uh oh! Something went wrong.",
      description: "There was a problem with your request. Please try again!",
    });
    return;
  }
  attachmentForm.post(route("api.attachments.store"), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      toast({
        title: "Success",
        description: "Attachments has been saved successfully!",
      });
      openAttachmentModal.value = false;
      attachmentForm.reset();
      handleFetchAttachment();
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
</script>

<template>
  <div class="overflow-y-auto px-6 w-full min-h-[300px] mb-10">
    <div class="flex justify-end gap-2 items-center mb-3">
      <Button :disabled="isLoading" size="icon" @click.prevent="openCameraModal = true">
        <Camera v-if="!isLoading" class="" />
        <Loader2 v-else class="w-4 h-4 animate-spin" />
      </Button>
      <Button
        :disabled="isLoading"
        size="icon"
        @click.prevent="openAttachmentModal = true"
      >
        <File v-if="!isLoading" class="" />
        <Loader2 v-else class="w-4 h-4 animate-spin" />
      </Button>
    </div>
    <div class="mb-3">
      <p class="font-semibold uppercase mb-4 p-2 bg-primary text-white">Attachments</p>

      <Files
        :files="attachmentFile"
        :loading="isLoading"
        @expandImage="handleExpandImage"
        @deleteImage="handleDeleteImage"
      />
    </div>
    <div class="mb-3">
      <p class="font-semibold uppercase mb-4 p-2 bg-primary text-white">
        Before Pictures
      </p>
      <Files
        :files="beforePics"
        :loading="isLoading"
        @expandImage="handleExpandImage"
        @deleteImage="handleDeleteImage"
      />
    </div>
    <div class="mb-3">
      <p class="font-semibold uppercase mb-4 p-2 bg-primary text-white">After Pictures</p>
      <Files
        :files="afterPics"
        :loading="isLoading"
        @expandImage="handleExpandImage"
        @deleteImage="handleDeleteImage"
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
      <div class="flex flex-row flex-nowrap overflow-x-auto scrollbar-hide px-6">
        <div class="mb-3 w-full">
          <img :src="expandedImage" alt="" class="w-full mt-3" />
          <Label>{{ expandedImageName }}</Label>
        </div>
      </div>
      <DialogFooter class="p-6 pt-0">
        <Button
          type="submit"
          :disabled="deleteFileForm.processing"
          @click.prevent="handleDeleteImageSubmit"
          variant="destructive"
        >
          <Loader2 v-if="deleteFileForm.processing" class="w-4 h-4 animate-spin" />
          Delete
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
  <Dialog v-model:open="openAttachmentModal">
    <DialogContent
      class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
    >
      <DialogHeader class="p-6 pb-0 text-left">
        <DialogTitle> Upload Attachments </DialogTitle>
        <DialogDescription> Select any files and then click submit.</DialogDescription>
      </DialogHeader>
      <Separator />
      <div class="flex flex-col flex-nowrap overflow-x-auto scrollbar-hide px-6">
        <div class="mb-3">
          <Label>Title</Label>
          <Input
            type="text"
            placeholder="Enter file description"
            v-model="attachmentForm.title"
          />
        </div>
        <div class="mb-3">
          <Label>File</Label>
          <Input
            type="file"
            accept=".jpg, .jpeg, .png, .gif, .pdf, .doc, .docx, .xls, .xlsx"
            @input="attachmentForm.filename = $event.target.files[0]"
          />
        </div>
        <Progress
          v-if="attachmentForm.progress"
          :value="attachmentForm.progress.percentage"
          :model-value="attachmentForm.progress.percentage"
        >
          {{ attachmentForm.progress.percentage }}%
        </Progress>
      </div>
      <DialogFooter class="p-6 pt-0">
        <Button @click="openAttachmentModal = false" variant="destructive">Cancel</Button>

        <Button
          type="submit"
          :disabled="attachmentForm.processing"
          @click.prevent="handleFormSubmit"
        >
          <Loader2 v-if="attachmentForm.processing" class="w-4 h-4 animate-spin" />
          Submit
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
  <AlertDialog v-model:open="openDeleteModal">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Are you absolutely sure?</AlertDialogTitle>
        <AlertDialogDescription>
          This action cannot be undone. This will permanently delete files from our
          servers.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Cancel</AlertDialogCancel>
        <AlertDialogAction
          class="destructive"
          :disabled="deleteFileForm.processing"
          @click.prevent="handleDeleteImageSubmit"
        >
          <Loader2 v-if="deleteFileForm.processing" class="w-4 h-4 animate-spin" />
          Continue
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
