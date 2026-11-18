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
import { groupByTurnoverArea } from "@/utils/turnoverAreas";
import { rejectionReason, uploadInBatches } from "@/utils/uploadBatches";

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

// Turnover vendors file each after photo under a part of the home; when any
// photo carries one, the section is shown room by room instead of as one list.
const afterPicGroups = computed(() => groupByTurnoverArea(afterPics.value));

// PropertyWare documents are streamed through our download route (images are
// served inline), so the same URL works for both the thumbnail and the link.
// The remembered rotation rides along as a query string so a turn never shows
// a cached copy of the old way up.
const documentUrl = (doc) =>
    route("api.work_order_documents.download", doc.id) +
    (doc.rotation ? `?r=${doc.rotation}` : "");

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

// Which list the open viewer is showing: our uploads (Delete + Rotate) or
// PropertyWare documents (Rotate only — their bytes live in PropertyWare).
const galleryMode = ref("attachment");

const documentPhotos = () =>
    (props.workOrderDocuments || []).filter(isImageDocument);

const documentToSlide = (item) => ({
    id: item.id,
    url: documentUrl(item),
    name: item.file_name || "Document",
    downloadName: attachmentDownloadName(
        item.file_name,
        item.file_name,
        item.file_type
    ),
});

// PropertyWare image documents step through the same viewer as uploaded
// photos. They can't be deleted here, so the Delete footer stays out.
const openDocumentGallery = (doc) => {
    const photos = documentPhotos();
    galleryImages.value = photos.map(documentToSlide);
    galleryIndex.value = Math.max(
        0,
        photos.findIndex((item) => item.id === doc.id)
    );
    galleryMode.value = "document";
    galleryCanDelete.value = false;
    openGallery.value = true;
};

const sectionFor = (file) => {
    if (file.type === "before") return beforePics.value;
    if (file.type === "after") return afterPics.value;
    return attachmentFile.value;
};

const sectionPhotos = (section) =>
    sectionFor({ type: section }).filter((file) =>
        String(file.filetype || "").startsWith("image/")
    );

const toSlide = (file) => ({
    id: file.id,
    url: file.attachment_url,
    name: file.title,
    downloadName: attachmentDownloadName(
        file.title,
        file.filename,
        file.filetype
    ),
});

// Remembered so the slides can be rebuilt from fresh props after a rotate.
const gallerySection = ref("attachment");
const galleryRotationPreview = ref(0);

const handleExpandImage = (imageSelected) => {
    gallerySection.value = imageSelected.type;
    const photos = sectionPhotos(imageSelected.type);
    galleryImages.value = photos.map(toSlide);
    galleryIndex.value = Math.max(
        0,
        photos.findIndex((file) => file.id === imageSelected.id)
    );
    deleteFileForm.id = imageSelected.id;
    galleryMode.value = "attachment";
    galleryCanDelete.value = true;
    openGallery.value = true;
};

// Delete and Rotate act on whichever photo is on screen.
watch(galleryIndex, (index) => {
    const photo = galleryImages.value[index];
    if (photo) deleteFileForm.id = photo.id;
    galleryRotationPreview.value = 0;
});

// Rotate saves the turn: an upload is rewritten on disk (new filename, new
// thumbnail); a PropertyWare document remembers the turn and is served turned.
// Either way the photo stays upright for everyone from then on. The picture
// turns on screen right away and the saved copy replaces it when the refetch
// lands.
const rotateForm = useForm({ direction: "" });

const handleRotateImage = (direction) => {
    const photo = galleryImages.value[galleryIndex.value];
    if (!photo || rotateForm.processing) return;

    const url =
        galleryMode.value === "document"
            ? route("api.work_order_documents.rotate", photo.id)
            : route("api.attachments.rotate", photo.id);

    galleryRotationPreview.value += direction === "clockwise" ? 90 : -90;
    rotateForm.direction = direction;
    rotateForm.post(url, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            handleFetchAttachment();
        },
        onError: (errors) => {
            galleryRotationPreview.value = 0;
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    errors?.direction ||
                    "The photo could not be rotated. Please try again!",
            });
        },
    });
};

// When the refreshed list arrives, swap the slides for the saved files and
// drop the on-screen preview — the new file already has the turn baked in.
const refreshGallerySlides = (photos, toSlideFor) => {
    const current = galleryImages.value[galleryIndex.value];
    if (!photos.length) {
        openGallery.value = false;
        return;
    }
    galleryImages.value = photos.map(toSlideFor);
    galleryIndex.value = Math.max(
        0,
        photos.findIndex((item) => item.id === current?.id)
    );
    galleryRotationPreview.value = 0;
};

watch(
    () => props.workOrderAttachments,
    () => {
        if (!openGallery.value || galleryMode.value !== "attachment") return;
        refreshGallerySlides(sectionPhotos(gallerySection.value), toSlide);
    }
);

watch(
    () => props.workOrderDocuments,
    () => {
        if (!openGallery.value || galleryMode.value !== "document") return;
        refreshGallerySlides(documentPhotos(), documentToSlide);
    }
);

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

// Set while an upload is in flight: { done, total, percent }, then
// { ..., complete: true } for a moment after the last batch lands.
const uploadProgress = ref(null);
const submitting = ref(false);

// Staff pick the whole set and press Submit once; the files go up in small
// requests so the server never sees more than it can take. Each batch that
// lands is saved; a failure keeps the unsent files in the dialog for a retry.
const handleFormSubmit = async () => {
    if (!attachmentForm.title) {
        toast({
            variant: "destructive",
            title: "Title is required",
            description: "Enter a short description for these files first.",
        });
        return;
    }
    if (attachmentForm.files.length === 0 || submitting.value) {
        return;
    }
    submitting.value = true;

    const entries = [...attachmentForm.files];
    const files = entries.map((entry) => entry.file);
    const entryOf = new Map(entries.map((entry) => [entry.file, entry]));
    const fields = {
        title: attachmentForm.title,
        type: attachmentForm.type,
        owner_portal: attachmentForm.owner_portal,
        tenant_portal: attachmentForm.tenant_portal,
        work_order_id: attachmentForm.work_order_id,
    };

    const { uploaded, remaining, error } = await uploadInBatches({
        url: route("api.attachments.multiple_store"),
        files,
        buildData: (batch) => ({
            ...fields,
            files: batch.map((file) => entryOf.get(file)),
        }),
        onProgress: (progress) => {
            uploadProgress.value = progress;
        },
    });

    if (uploaded.length > 0) {
        handleFetchAttachment();
    }

    if (error) {
        const sent = new Set(uploaded);
        attachmentForm.files = entries.filter((entry) => !sent.has(entry.file));
        previewFiles.value = previewFiles.value.filter(
            (preview) => !sent.has(preview.file),
        );
        uploadProgress.value = null;
        submitting.value = false;
        toast({
            variant: "destructive",
            title:
                uploaded.length > 0
                    ? `${uploaded.length} of ${files.length} files uploaded`
                    : "Files not uploaded",
            description:
                remaining.length > 0
                    ? `${error} ${remaining.length} file(s) are still in the dialog. Press Submit to try them again.`
                    : error,
            duration: 10000,
        });
        return;
    }

    uploadProgress.value = { ...uploadProgress.value, complete: true };
    toast({
        title: "Complete",
        description: `${uploaded.length} file(s) uploaded. PropertyWare sync is processing in the background.`,
    });
    setTimeout(() => {
        openAttachmentModal.value = false;
        attachmentForm.reset();
        previewFiles.value = [];
        uploadProgress.value = null;
        submitting.value = false;
    }, 1500);
};

const previewFiles = ref([]);

function handleFiles(event) {
    const files = event.target.files;
    previewFiles.value = [];
    attachmentForm.files = [];

    const refused = [];
    for (let i = 0; i < files.length; i++) {
        const file = files[i];

        // Said now, in words, rather than as a server error page later.
        const reason = rejectionReason(file);
        if (reason) {
            refused.push(reason);
            continue;
        }

        previewFiles.value.push({
            name: file.name,
            type: file.type,
            url: URL.createObjectURL(file),
            file,
        });

        attachmentForm.files.push({
            name: file.name,
            type: file.type,
            file: file,
        });
    }

    // One toast for the whole pick: the toaster shows a single message at a
    // time, so per-file toasts would overwrite each other.
    if (refused.length > 0) {
        toast({
            variant: "destructive",
            title:
                refused.length === 1
                    ? "That file can't be uploaded"
                    : `${refused.length} files can't be uploaded`,
            description: refused.join(" "),
            duration: 10000,
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
                <template v-if="afterPicGroups">
                    <div
                        v-for="group in afterPicGroups"
                        :key="group.label"
                        class="mb-4"
                    >
                        <p class="text-sm font-semibold mb-2 pb-1 border-b">
                            {{ group.label }}
                            <span class="font-normal text-muted-foreground"
                                >({{ group.files.length }})</span
                            >
                        </p>
                        <Files
                            :files="group.files"
                            :loading="isLoading"
                            @expandImage="handleExpandImage"
                            @deleteImage="handleDeleteImage"
                        />
                    </div>
                </template>
                <Files
                    v-else
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
            rotatable
            :rotating="rotateForm.processing"
            :rotation-preview="galleryRotationPreview"
            @rotate="handleRotateImage"
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
                        <!-- Fills a step per batch, then reads Complete. Big
                             sets go up in several requests; without this the
                             button just spins and the upload looks stuck. -->
                        <div
                            v-if="uploadProgress"
                            class="mt-2 space-y-1"
                            role="status"
                            aria-live="polite"
                        >
                            <div
                                class="flex items-center justify-between text-sm font-medium"
                                :class="uploadProgress.complete ? 'text-emerald-600' : ''"
                            >
                                <span>
                                    {{
                                        uploadProgress.complete
                                            ? "Complete"
                                            : `Uploading ${uploadProgress.done} of ${uploadProgress.total}`
                                    }}
                                </span>
                                <span class="tabular-nums">{{ uploadProgress.percent }}%</span>
                            </div>
                            <Progress :model-value="uploadProgress.percent" />
                            <p
                                v-if="!uploadProgress.complete"
                                class="text-xs text-muted-foreground"
                            >
                                Files are sent a few at a time and each group is saved as it lands.
                            </p>
                        </div>
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
                        :disabled="submitting"
                        @click="openAttachmentModal = false"
                        variant="destructive"
                        >Cancel</Button
                    >

                    <Button
                        type="submit"
                        :disabled="submitting"
                        @click.prevent="handleFormSubmit"
                    >
                        <Loader2
                            v-if="submitting"
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
