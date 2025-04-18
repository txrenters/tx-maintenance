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
    files: [],
    owner_portal: "No",
    tenant_portal: "No",
    work_order_id: props.workOrder.id,
});
const attachmentFile = computed(() => {
    return (props.workOrderAttachments || []).filter(
        (file) => file?.type === "attachment"
    );
});

const beforePics = computed(() => {
    return (props.workOrderAttachments || []).filter(
        (file) => file?.type === "before"
    );
});

const afterPics = computed(() => {
    return (props.workOrderAttachments || []).filter(
        (file) => file?.type === "after"
    );
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
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
    });
};

const handleFetchAttachment = () => {
    emit("fetch-attachments");
};

const handleFormSubmit = () => {
    if (!attachmentForm.title) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please try again!",
        });
        return;
    }
    attachmentForm.post(route("api.attachments.multiple_store"), {
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
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
    });
};

const previewFiles = ref([]);

function handleFiles(event) {
    const files = event.target.files;
    previewFiles.value = [];

    for (let i = 0; i < files.length; i++) {
        const file = files[i];

        previewFiles.value.push({
            name: file.name,
            type: file.type,
            url: URL.createObjectURL(file),
        });

        attachmentForm.files.push({
            name: file.name,
            type: file.type,
            file: file,
        });
    }
}
</script>

<template>
    <div class="overflow-y-auto px-6 w-full min-h-[300px] mb-10">
        <div class="flex justify-end gap-2 items-center mb-3">
            <Button
                :disabled="isLoading"
                size="icon"
                @click.prevent="openCameraModal = true"
            >
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
            <p class="font-semibold uppercase mb-4 p-2 bg-primary text-white">
                Attachments
            </p>

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
            <p class="font-semibold uppercase mb-4 p-2 bg-primary text-white">
                After Pictures
            </p>
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
            <div
                class="flex flex-row flex-nowrap overflow-x-auto scrollbar-hide px-6"
            >
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
                    <Loader2
                        v-if="deleteFileForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
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
                <DialogDescription>
                    Select any files and then click submit.</DialogDescription
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
                    <Label>File</Label>
                    <Input
                        multiple
                        type="file"
                        accept=".jpg, .jpeg, .png, .gif, .pdf, .doc, .docx, .xls, .xlsx"
                        @change="handleFiles"
                    />
                    <Progress
                        v-if="attachmentForm.progress"
                        :value="attachmentForm.progress.percentage"
                        :model-value="attachmentForm.progress.percentage"
                    >
                        {{ attachmentForm.progress.percentage }}%
                    </Progress>
                </div>
                <div class="mb-3">
                    <Label>Choose Option</Label>
                    <RadioGroup
                        default-value="comfortable"
                        class="flex gap-5 mt-2"
                        v-model="attachmentForm.type"
                    >
                        <div class="flex items-center space-x-2">
                            <RadioGroupItem id="r2" value="before" />
                            <Label for="r2">Before</Label>
                        </div>
                        <div class="flex items-center space-x-2">
                            <RadioGroupItem id="r3" value="after" />
                            <Label for="r3">After</Label>
                        </div>
                        <div class="flex items-center space-x-2">
                            <RadioGroupItem id="r3" value="attachment" />
                            <Label for="r3">Attachment</Label>
                        </div>
                    </RadioGroup>
                </div>
                <div class="mb-3">
                    <Label>Publish to Tenant Portal</Label>
                    <RadioGroup
                        default-value="comfortable"
                        class="flex gap-5 mt-2"
                        v-model="attachmentForm.tenant_portal"
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
                        v-model="attachmentForm.owner_portal"
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
                    <Label for="">Previews</Label>
                    <div class="flex gap-2 flex-wrap mb-6">
                        <div
                            v-for="(file, index) in previewFiles"
                            :key="index"
                            class="flex gap-2 border"
                        >
                            <!-- Image Preview -->
                            <img
                                v-if="file.type.startsWith('image/')"
                                :src="file.url"
                                class="w-32"
                            />

                            <!-- PDF Preview -->
                            <!-- <iframe
                            v-else-if="file.type === 'application/pdf'"
                            :src="file.url"
                            class="w-32"
                        ></iframe> -->

                            <!-- DOC, DOCX, XLS, XLSX and other file types -->
                            <div v-else class="text-5xl p-2">📄</div>
                        </div>
                    </div>
                </div>
            </div>
            <DialogFooter class="p-6 pt-0">
                <Button
                    @click="openAttachmentModal = false"
                    variant="destructive"
                    >Cancel</Button
                >

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
    <AlertDialog v-model:open="openDeleteModal">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Are you absolutely sure?</AlertDialogTitle>
                <AlertDialogDescription>
                    This action cannot be undone. This will permanently delete
                    files from our servers.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    class="destructive"
                    :disabled="deleteFileForm.processing"
                    @click.prevent="handleDeleteImageSubmit"
                >
                    <Loader2
                        v-if="deleteFileForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    Continue
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>

<style scoped>
.preview-container {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 1rem;
}
.preview-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}
.preview-img,
.preview-pdf {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border: 1px solid #ccc;
    border-radius: 8px;
}
.preview-pdf {
    object-fit: contain;
}
.preview-icon {
    font-size: 48px;
}
.file-name {
    font-size: 12px;
    text-align: center;
    margin-top: 0.5rem;
    word-break: break-word;
}
</style>
