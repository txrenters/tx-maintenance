<script setup>
/**
 * Photos tab for a Jobber job, mirroring the work order Attachments tab:
 * camera + file buttons top-right, an upload dialog with title / file picker /
 * before-after-attachment radios / previews, and the grouped thumbnail grid.
 *
 * Self-contained so the board modal and the full job page render exactly the
 * same thing. Emits `saved` after a successful write so the modal — which keeps
 * job data in local state rather than Inertia props — can refetch.
 */
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { Camera, File, Loader2 } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Separator } from "@/Components/ui/separator";
import { RadioGroup, RadioGroupItem } from "@/Components/ui/radio-group";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import CameraModal from "@/Pages/WorkOrder/Partials/CameraModal.vue";
import JobPhotos from "./JobPhotos.vue";

const props = defineProps({
    jobId: [Number, String],
    attachments: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(["saved"]);

const { toast } = useToast();

const openCameraModal = ref(false);
const openUploadModal = ref(false);
const isUploading = ref(false);
const form = ref({ title: "", type: "after", files: [] });
const previewFiles = ref([]);
const fileInputKey = ref(0);

const resetForm = () => {
    previewFiles.value.forEach((file) => URL.revokeObjectURL(file.url));
    previewFiles.value = [];
    form.value = { title: "", type: "after", files: [] };
    fileInputKey.value++;
};

const handleFiles = (event) => {
    const files = Array.from(event.target.files || []);
    form.value.files = files;
    previewFiles.value.forEach((file) => URL.revokeObjectURL(file.url));
    previewFiles.value = files.map((file) => ({
        type: file.type,
        url: URL.createObjectURL(file),
    }));
};

// A snapshot arrives as a Blob, so wrap it in a File the upload can carry.
const handleCapturedImage = ({ snapshotUrl, blob }) => {
    const file = new window.File([blob], `photo_${Date.now()}.png`, {
        type: "image/png",
    });
    form.value.files.push(file);
    previewFiles.value.push({ type: "image/png", url: snapshotUrl });
    openCameraModal.value = false;
    openUploadModal.value = true;
};

const submit = () => {
    if (!form.value.title.trim() || form.value.files.length === 0) {
        toast({
            variant: "destructive",
            title: "Error",
            description: "Add a title and choose at least one file.",
        });
        return;
    }

    const formData = new FormData();
    formData.append("title", form.value.title);
    formData.append("type", form.value.type);
    form.value.files.forEach((file) => formData.append("files[]", file));

    isUploading.value = true;
    router.post(route("jobber.attachments.store", props.jobId), formData, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({ title: "Success", description: "Photos uploaded." });
            openUploadModal.value = false;
            resetForm();
            emit("saved");
        },
        onError: () =>
            toast({
                variant: "destructive",
                title: "Error",
                description: "Failed to upload photos.",
            }),
        onFinish: () => (isUploading.value = false),
    });
};

const deletePhoto = (id) => {
    router.delete(route("jobber.attachments.destroy", id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({ title: "Deleted", description: "Photo removed." });
            emit("saved");
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
                title="Take a photo"
                @click.prevent="openCameraModal = true"
            >
                <Camera v-if="!isUploading" />
                <Loader2 v-else class="w-4 h-4 animate-spin" />
            </Button>
            <Button
                size="icon"
                :disabled="isUploading"
                title="Upload files"
                @click.prevent="openUploadModal = true"
            >
                <File v-if="!isUploading" />
                <Loader2 v-else class="w-4 h-4 animate-spin" />
            </Button>
        </div>

        <JobPhotos
            :attachments="attachments"
            :can-manage="canManage"
            @deleteImage="deletePhoto"
        />

        <CameraModal
            :show="openCameraModal"
            @update:show="openCameraModal = $event"
            @capturedImage="handleCapturedImage"
        />

        <Dialog v-model:open="openUploadModal">
            <DialogContent
                class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
            >
                <DialogHeader class="p-6 pb-0 text-left">
                    <DialogTitle>Upload Attachments</DialogTitle>
                    <DialogDescription>
                        Select any files and then click submit.
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
                            placeholder="Enter file description"
                            v-model="form.title"
                        />
                    </div>
                    <div class="mb-3">
                        <Label>File</Label>
                        <Input
                            :key="fileInputKey"
                            multiple
                            type="file"
                            accept=".jpg, .jpeg, .png, .gif, .pdf, .doc, .docx, .xls, .xlsx"
                            @change="handleFiles"
                        />
                    </div>
                    <div class="mb-3">
                        <Label>Choose Option</Label>
                        <RadioGroup class="flex gap-5 mt-2" v-model="form.type">
                            <div class="flex items-center space-x-2">
                                <RadioGroupItem
                                    id="job-photo-before"
                                    value="before"
                                />
                                <Label for="job-photo-before">Before</Label>
                            </div>
                            <div class="flex items-center space-x-2">
                                <RadioGroupItem
                                    id="job-photo-after"
                                    value="after"
                                />
                                <Label for="job-photo-after">After</Label>
                            </div>
                            <div class="flex items-center space-x-2">
                                <RadioGroupItem
                                    id="job-photo-attachment"
                                    value="attachment"
                                />
                                <Label for="job-photo-attachment"
                                    >Attachment</Label
                                >
                            </div>
                        </RadioGroup>
                    </div>
                    <div class="mb-3" v-if="previewFiles.length">
                        <Label>Previews</Label>
                        <div class="flex gap-2 flex-wrap mb-6">
                            <div
                                v-for="(file, index) in previewFiles"
                                :key="index"
                                class="flex gap-2 border"
                            >
                                <img
                                    v-if="file.type.startsWith('image/')"
                                    :src="file.url"
                                    class="w-32"
                                />
                                <div v-else class="text-5xl p-2">📄</div>
                            </div>
                        </div>
                    </div>
                </div>
                <DialogFooter class="p-6 pt-0">
                    <Button variant="destructive" @click="openUploadModal = false">
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
    </div>
</template>
