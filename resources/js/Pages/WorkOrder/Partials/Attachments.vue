<script setup>
import { ref, watch, onMounted, computed } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { Loader2, Camera, File, FileText, FileSpreadsheet } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
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

console.log(attachmentFile);

const isImage = (file) => {
  return file.filetype.startsWith("image/");
};

const getFileIcon = (filename) => {
  const ext = filename.split(".").pop().toLowerCase();
  switch (ext) {
    case "pdf":
      return FileText;
    case "doc":
    case "docx":
      return FileText;
    case "xls":
    case "xlsx":
    case "txt":
      return FileSpreadsheet;
    default:
      return File;
  }
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
        description: "Service schedule has been set successfully!",
      });
      openAttachmentModal.value = false;
      attachmentForm.reset();
      emit("fetch-attachments");
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
      <p class="font-semibold uppercase mb-2 p-2 bg-primary text-white">Attachments</p>
      <div class="flex gap-5 flex-wrap" v-if="!isLoading">
        <div
          v-for="file in attachmentFile"
          :key="file.id"
          class="rounded cursor-pointer hover:opacity-75"
          :title="file.title"
        >
          <template v-if="isImage(file)">
            <div class="w-48">
              <img
                :src="file.attachment_url"
                alt="Preview"
                class="w-full h-auto border"
              />
            </div>
          </template>
          <template v-else>
            <!-- Show file icon -->
            <div class="file-icon p-2 border rouded">
              <a :href="file.attachment_url" download="">
                <component
                  :is="getFileIcon(file.filename)"
                  width="90"
                  height="90"
                  stroke-width="1"
                ></component>
              </a>
            </div>
          </template>
          <p class="text-xs text-wrap w-20">{{ file.title }}</p>
        </div>
      </div>
    </div>
    <div class="mb-3">
      <p class="font-semibold uppercase mb-2 p-2 bg-primary text-white">
        Before Pictures
      </p>
      <div class="flex gap-5 flex-wrap" v-if="!isLoading">
        <div
          v-for="file in beforePics"
          :key="file.id"
          class="rounded cursor-pointer hover:opacity-75"
          :title="file.title"
        >
          <template v-if="isImage(file)">
            <div class="w-48">
              <img
                :src="file.attachment_url"
                alt="Preview"
                class="w-full h-auto border"
              />
            </div>
          </template>
          <template v-else>
            <!-- Show file icon -->
            <div class="file-icon p-2 border rouded">
              <a :href="file.attachment_url" download="">
                <component
                  :is="getFileIcon(file.filename)"
                  width="90"
                  height="90"
                  stroke-width="1"
                ></component>
              </a>
            </div>
          </template>
          <p class="text-xs text-wrap w-20">{{ file.title }}</p>
        </div>
      </div>
    </div>
    <div class="mb-3">
      <p class="font-semibold uppercase mb-2 p-2 bg-primary text-white">After Pictures</p>
      <div class="flex gap-5 flex-wrap" v-if="!isLoading">
        <div
          v-for="file in afterPics"
          :key="file.id"
          class="rounded cursor-pointer hover:opacity-75"
          :title="file.title"
        >
          <template v-if="isImage(file)">
            <div class="w-48">
              <img
                :src="file.attachment_url"
                alt="Preview"
                class="w-full h-auto border"
              />
            </div>
          </template>
          <template v-else>
            <!-- Show file icon -->
            <div class="file-icon p-2 border rouded">
              <a :href="file.attachment_url" download="">
                <component
                  :is="getFileIcon(file.filename)"
                  width="90"
                  height="90"
                  stroke-width="1"
                ></component>
              </a>
            </div>
          </template>
          <p class="text-xs text-wrap w-20">{{ file.title }}</p>
        </div>
      </div>
    </div>
  </div>
  <Dialog v-model:open="openAttachmentModal">
    <DialogContent
      class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
    >
      <DialogHeader class="p-6 pb-0 text-left">
        <DialogTitle> Upload Attachments </DialogTitle>
        <DialogDescription> Select any files and then click submit.</DialogDescription>
      </DialogHeader>
      <Separator />
      <div class="px-4">
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
</template>
