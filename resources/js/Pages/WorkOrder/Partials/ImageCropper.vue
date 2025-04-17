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
                            class="block bg-cover bg-no-repeat bg-center mt-2"
                            :src="photoPreview"
                        />
                    </div>

                    <div class="mb-3">
                        <Label>Title</Label>
                        <Input
                            type="text"
                            class="mt-2"
                            placeholder="Enter file description"
                            v-model="croppedImageForm.title"
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
                    <div class="mb-12">
                        <Label>Select Date</Label>
                        <Input
                            type="date"
                            placeholder="Enter file description"
                            v-model="croppedImageForm.date"
                            class="w-full mt-2"
                        />
                    </div>
                    <Progress
                        v-if="croppedImageForm.progress"
                        :value="croppedImageForm.progress.percentage"
                        :model-value="croppedImageForm.progress.percentage"
                    >
                        {{ croppedImageForm.progress.percentage }}%
                    </Progress>
                </div>
            </div>
            <DialogFooter class="p-6 pt-0">
                <Button @click="closeModal" variant="destructive"
                    >Cancel</Button
                >
                <Button @click="clear" v-show="!photoPreview">Clear</Button>
                <Button @click="reset" v-show="!photoPreview">Reset</Button>
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
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
