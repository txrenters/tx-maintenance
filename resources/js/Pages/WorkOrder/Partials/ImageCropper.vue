<script setup>
import { ref, reactive, watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import VuePictureCropper, { cropper } from "vue-picture-cropper";
import { RadioGroup, RadioGroupItem } from "@/Components/ui/radio-group";
import { useToast } from "@/Components/ui/toast/use-toast";
const { toast } = useToast();

const props = defineProps({
    show: Boolean,
    image: String,
    onCrop: Function,
    workOrder_id: Number,
});

const emit = defineEmits(["update:show", "fetch-attachments", "imageEmpty"]);

const croppedImageForm = useForm({
    title: "",
    type: "",
    filename: "",
    date: "",
    owner_portal: "No",
    tenant_portal: "No",
    work_order_id: props.workOrder_id,
});

const showModal = ref(props.show);

const photoPreview = ref(null);

watch(
    () => props.show,
    (newVal) => {
        showModal.value = newVal;
    }
);

function closeModal() {
    showModal.value = false;
    emit("update:show", false);
    photoPreview.value = null;
}

function clear() {
    if (!cropper) return;
    cropper.clear();
}

function reset() {
    if (!cropper) return;
    cropper.reset();
}

function ready() {
    console.log("Cropper is ready.");
}

async function cropImage() {
    if (!cropper) return;
    const base64 = cropper.getDataURL();
    const blob = await cropper.getBlob();

    if (blob) {
        photoPreview.value = base64;
        croppedImageForm.filename = blob;
    }
}

const handleFormSubmit = () => {
    if (
        !croppedImageForm.title ||
        !croppedImageForm.type ||
        !croppedImageForm.filename ||
        !croppedImageForm.date
    ) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please try again!",
        });
        return;
    }
    croppedImageForm.post(route("api.attachments.store"), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Image has been saved successfully!",
            });
            croppedImageForm.value = false;
            croppedImageForm.reset();
            emit("fetch-attachments");
            closeModal();
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
</script>

<template>
    <Dialog v-model:open="showModal" @close="closeModal">
        <DialogContent
            @interactOutside="(e) => e.preventDefault()"
            @escapeKeyDown="(e) => e.preventDefault()"
            class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle> Image Cropping </DialogTitle>
                <DialogDescription>
                    Crop the image and click crop to save.</DialogDescription
                >
            </DialogHeader>
            <Separator />

            <div
                class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide px-6"
            >
                <VuePictureCropper
                    v-show="!photoPreview"
                    :boxStyle="{
                        width: '100%',
                        height: '100%',
                        backgroundColor: '#f8f8f8',
                        margin: 'auto',
                    }"
                    :img="image"
                    :options="{
                        viewMode: 1,
                        dragMode: 'crop',
                    }"
                    @ready="ready"
                />
                <div v-show="photoPreview" class="w-full">
                    <div class="mb-3">
                        <Label>Cropped Image</Label>
                        <img
                            width="100"
                            class="block bg-cover bg-no-repeat bg-center mt-2"
                            :src="photoPreview"
                        />
                    </div>

                    <div class="mb-2">
                        <Label>Title</Label>
                        <Input
                            type="text"
                            placeholder="Enter file description"
                            v-model="croppedImageForm.title"
                        />
                        <Progress
                            v-if="croppedImageForm.progress"
                            :value="croppedImageForm.progress.percentage"
                            :model-value="croppedImageForm.progress.percentage"
                        >
                            {{ croppedImageForm.progress.percentage }}%
                        </Progress>
                    </div>
                    <div class="mb-2">
                        <Label>Select Date</Label>
                        <Input
                            type="date"
                            placeholder="Enter file description"
                            v-model="croppedImageForm.date"
                            class="w-full"
                        />
                    </div>
                    <div class="mb-3">
                        <Label>Choose Option</Label>
                        <RadioGroup
                            default-value="comfortable"
                            class="flex gap-5 mt-2"
                            v-model="croppedImageForm.type"
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
                            v-model="croppedImageForm.tenant_portal"
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
                    <div class="mb-12 pb-12">
                        <Label>Publish to Owner Portal</Label>
                        <RadioGroup
                            default-value="comfortable"
                            class="flex gap-5 mt-2"
                            v-model="croppedImageForm.owner_portal"
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
            </div>
            <DialogFooter class="p-6 pt-0">
                <div class="flex gap-2 justify-end">
                    <Button class="" @click="closeModal" variant="destructive"
                        >Cancel</Button
                    >
                    <Button
                        @click="clear"
                        v-show="!photoPreview"
                        variant="secondary"
                        >Clear</Button
                    >
                    <Button
                        @click="reset"
                        v-show="!photoPreview"
                        variant="secondary"
                        >Reset</Button
                    >
                    <Button @click="cropImage" v-show="!photoPreview">
                        Crop
                    </Button>
                    <Button
                        v-show="photoPreview"
                        @click.prevent="handleFormSubmit"
                        type="submit"
                        :disabled="croppedImageForm.processing"
                    >
                        <Loader2
                            v-if="croppedImageForm.processing"
                            class="w-4 h-4 animate-spin"
                        />
                        Save
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
