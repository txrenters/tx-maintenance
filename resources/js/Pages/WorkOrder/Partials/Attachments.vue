<script setup>
import { ref, watch, onMounted, computed } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import {
    Loader2,
    Camera,
    File,
    FileSpreadsheet,
    Download,
    Expand,
} from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import Files from "./Files.vue";
import CameraModal from "./CameraModal.vue";
import ImageCropper from "./ImageCropper.vue";
import FilePreviewDialog from "@/Components/FilePreviewDialog.vue";
import ImageGalleryDialog from "@/Components/ImageGalleryDialog.vue";
import { attachmentDownloadName } from "@/utils/attachmentDownloadName";

const { toast } = useToast();

const props = defineProps({
    workOrderAttachments: Object,
    workOrderDocuments: { type: Array, default: () => [] },
    isLoading: Boolean,
    workOrder: Object,
});

const emit = defineEmits(["fetch-attachments", "open-camera"]);

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

// PropertyWare documents are streamed through our download route (images are
// served inline), so the same URL works for both the thumbnail and the link.
const documentUrl = (doc) =>
    route("api.work_order_documents.download", doc.id);

const isImageDocument = (doc) => {
    if (doc.file_type) {
        return doc.file_type.startsWith("image/");
    }
    return /\.(jpe?g|png|gif|webp)$/i.test(doc.file_name || "");
};

const isPdfDocument = (doc) => {
    return (
        doc.file_type === "application/pdf" ||
        /\.pdf$/i.test(doc.file_name || "")
    );
};

// PDF and image documents open in the in-app preview dialog; everything else
// keeps the plain link since browsers can't render Office files inline.
const openDocumentPreview = ref(false);
const previewedDocument = ref(null);

const handlePreviewDocument = (doc) => {
    if (isImageDocument(doc)) {
        openDocumentGallery(doc);
        return;
    }

    previewedDocument.value = {
        url: documentUrl(doc),
        name: doc.file_name || "Document",
        mime: doc.file_type,
    };
    openDocumentPreview.value = true;
};

// Icon shown for non-image documents, matching the before/after Files component.
const documentIcon = (doc) => {
    const ext = (doc.file_name || "").split(".").pop().toLowerCase();
    switch (ext) {
        case "pdf":
            return "/icons/pdf.png";
        case "doc":
        case "docx":
            return "/icons/docx.png";
        case "xls":
        case "xlsx":
        case "txt":
            return "/icons/excel.png";
        default:
            return "/icons/file.png";
    }
};

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

// The viewer steps through the photos of the section the click came from
// (Attachments, Before, After). Files that aren't images never become a slide.
const openGallery = ref(false);
const galleryImages = ref([]);
const galleryIndex = ref(0);
const galleryCanDelete = ref(true);
const deleteFileForm = useForm({ id: "" });

// PropertyWare image documents step through the same viewer as uploaded
// photos. They are read-only here, so the Delete footer stays out.
const openDocumentGallery = (doc) => {
    const photos = (props.workOrderDocuments || []).filter(isImageDocument);
    galleryImages.value = photos.map((item) => ({
        id: item.id,
        url: documentUrl(item),
        name: item.file_name || "Document",
        downloadName: attachmentDownloadName(
            item.file_name,
            item.file_name,
            item.file_type
        ),
    }));
    galleryIndex.value = Math.max(
        0,
        photos.findIndex((item) => item.id === doc.id)
    );
    galleryCanDelete.value = false;
    openGallery.value = true;
};

const sectionFor = (file) => {
    if (file.type === "before") return beforePics.value;
    if (file.type === "after") return afterPics.value;
    return attachmentFile.value;
};

const handleExpandImage = (imageSelected) => {
    const photos = sectionFor(imageSelected).filter((file) =>
        String(file.filetype || "").startsWith("image/")
    );
    galleryImages.value = photos.map((file) => ({
        id: file.id,
        url: file.attachment_url,
        name: file.title,
        downloadName: attachmentDownloadName(
            file.title,
            file.filename,
            file.filetype
        ),
    }));
    galleryIndex.value = Math.max(
        0,
        photos.findIndex((file) => file.id === imageSelected.id)
    );
    deleteFileForm.id = imageSelected.id;
    galleryCanDelete.value = true;
    openGallery.value = true;
};

// Delete acts on whichever photo is on screen.
watch(galleryIndex, (index) => {
    const photo = galleryImages.value[index];
    if (photo) deleteFileForm.id = photo.id;
});

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
            openGallery.value = false;
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
    <div>
        <div class="grid gap-3 overflow-y-auto px-6">
            <div
                class="flex justify-end gap-2 items-center mb-3"
                v-if="
                    $page.props.auth.user.roles.includes('admin') ||
                    $page.props.auth.user.roles.includes('woc') ||
                    $page.props.auth.user.roles.includes('vendor')
                "
            >
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
                <p
                    class="font-semibold uppercase mb-4 p-2 bg-primary text-white"
                >
                    Attachments
                </p>

                <Files
                    :files="attachmentFile"
                    :loading="isLoading"
                    @expandImage="handleExpandImage"
                    @deleteImage="handleDeleteImage"
                />

                <!-- PropertyWare-synced documents (e.g. Work Order Information.pdf) -->
                <div v-if="isLoading" class="space-y-2 mt-3">
                    <div
                        v-for="n in 2"
                        :key="n"
                        class="h-10 rounded-md bg-muted animate-pulse"
                    />
                </div>
                <div
                    v-else-if="workOrderDocuments.length"
                    class="flex gap-5 flex-wrap mt-3"
                >
                    <div
                        v-for="doc in workOrderDocuments"
                        :key="doc.id"
                        class="rounded cursor-pointer hover:opacity-75"
                    >
                        <!-- Image documents show a thumbnail; opens the in-app preview -->
                        <div
                            v-if="isImageDocument(doc)"
                            class="relative group inline-block p-3 border"
                            title="Preview"
                            @click="handlePreviewDocument(doc)"
                        >
                            <div
                                class="relative flex items-center justify-center w-32 h-32"
                            >
                                <Expand
                                    width="40"
                                    height="40"
                                    stroke-width="1"
                                    class="absolute inset-0 m-auto opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                                />
                                <img
                                    :src="documentUrl(doc)"
                                    :alt="doc.file_name"
                                    class="w-full h-full object-contain opacity-100 group-hover:opacity-20 transition-opacity duration-200"
                                />
                            </div>
                        </div>

                        <!-- PDF documents preview in-app; a corner button still downloads -->
                        <div
                            v-else-if="isPdfDocument(doc)"
                            class="relative group inline-block p-3 border"
                            title="Preview"
                            @click="handlePreviewDocument(doc)"
                        >
                            <div
                                class="relative flex items-center justify-center w-32 h-32"
                            >
                                <Expand
                                    width="40"
                                    height="40"
                                    stroke-width="1"
                                    class="absolute inset-0 m-auto opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                                />
                                <img
                                    :src="documentIcon(doc)"
                                    class="w-full h-full object-contain opacity-100 group-hover:opacity-20 transition-opacity duration-200"
                                    alt="File Icon"
                                />
                                <a
                                    :href="documentUrl(doc)"
                                    download=""
                                    title="Download"
                                    @click.stop
                                    class="absolute bottom-0 right-0 p-1 rounded bg-secondary opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                                >
                                    <Download class="w-4 h-4" />
                                </a>
                            </div>
                        </div>

                        <!-- Other documents (Office files etc.) keep the plain link -->
                        <a
                            v-else
                            :href="documentUrl(doc)"
                            target="_blank"
                            rel="noopener"
                            class="relative group inline-block p-3 border"
                            title="View / Download"
                        >
                            <div
                                class="relative flex items-center justify-center w-32 h-32"
                            >
                                <Download
                                    width="40"
                                    height="40"
                                    stroke-width="1"
                                    class="absolute inset-0 m-auto opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                                />
                                <img
                                    :src="documentIcon(doc)"
                                    class="w-full h-full object-contain opacity-100 group-hover:opacity-20 transition-opacity duration-200"
                                    alt="File Icon"
                                />
                            </div>
                        </a>

                        <div class="w-32">
                            <p
                                class="text-xs mt-2 break-all line-clamp-2"
                                :title="doc.file_name"
                            >
                                {{ doc.file_name || "Document" }}
                            </p>
                            <p
                                v-if="doc.description"
                                class="text-xs text-muted-foreground break-all line-clamp-2"
                                :title="doc.description"
                            >
                                {{ doc.description }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <p
                    class="font-semibold uppercase mb-4 p-2 bg-primary text-white"
                >
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
                <p
                    class="font-semibold uppercase mb-4 p-2 bg-primary text-white"
                >
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
        <FilePreviewDialog
            v-model:open="openDocumentPreview"
            :file="previewedDocument"
        />
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
        <ImageGalleryDialog
            v-model:open="openGallery"
            v-model:index="galleryIndex"
            :images="galleryImages"
        >
            <template v-if="galleryCanDelete" #footer>
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
            </template>
        </ImageGalleryDialog>
        <Dialog v-model:open="openAttachmentModal">
            <DialogContent
                class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
            >
                <DialogHeader class="p-6 pb-0 text-left">
                    <DialogTitle> Upload Attachments </DialogTitle>
                    <DialogDescription>
                        Select any files and then click
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
                        <Label>File</Label>
                        <Input
                            multiple
                            type="file"
                            accept="image/*, video/*, .3gp, .jpg, .jpeg, .png, .gif, .pdf, .doc, .docx, .xls, .xlsx"
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
                    <AlertDialogTitle
                        >Are you absolutely sure?</AlertDialogTitle
                    >
                    <AlertDialogDescription>
                        This action cannot be undone. This will permanently
                        delete files from our servers.
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
    </div>
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
