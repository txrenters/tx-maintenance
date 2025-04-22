<script setup>
import { ref, watch } from "vue";
const props = defineProps({
    show: Boolean,
});
const emit = defineEmits(["update:show", "capturedImage"]);

const showCamera = ref(props.show);
const currentFacingMode = ref("user");

const video = ref(null);
const snapshotUrl = ref("");

const toggleCamera = () => {
    stopCamera();
    currentFacingMode.value =
        currentFacingMode.value === "user" ? "environment" : "user";
    startCamera();
    console.log(currentFacingMode.value);
};

// const startCamera = async (facingMode = "environment") => {
//     try {
//         const stream = await navigator.mediaDevices.getUserMedia({
//             video: { facingMode: { ideal: facingMode } },
//         });
//         video.value.srcObject = stream;
//         console.log("Camera started with mode:", facingMode);
//     } catch (error) {
//         console.error("Camera error:", error.name, error.message);
//         // Optional fallback to front camera if back is not available
//         if (
//             facingMode === "environment" &&
//             error.name === "OverconstrainedError"
//         ) {
//             console.log("Back camera not available, switching to front.");
//             startCamera("user");
//         }
//     }
// };

const startCamera = async () => {
    try {
        // 👇 Request camera once to unlock labels
        await navigator.mediaDevices.getUserMedia({ video: true });

        const deviceId = await getBackCameraDeviceId();

        if (!deviceId) {
            console.error("❌ No video input device found.");
            return;
        }

        const stream = await navigator.mediaDevices.getUserMedia({
            video: { deviceId: deviceId },
        });

        video.value.srcObject = stream;
        console.log("✅ Camera started with deviceId:", deviceId);
    } catch (error) {
        console.error("🚨 Camera error:", error.name, error.message);
    }
};

const getBackCameraDeviceId = async () => {
    const devices = await navigator.mediaDevices.enumerateDevices();

    // Some devices won't reveal anything without permission
    const videoDevices = devices.filter(
        (device) => device.kind === "videoinput"
    );

    console.log("🎥 Video devices found:", videoDevices);

    const backCam = videoDevices.find(
        (device) =>
            device.label.toLowerCase().includes("back") ||
            device.label.toLowerCase().includes("rear")
    );

    return backCam ? backCam.deviceId : videoDevices[0]?.deviceId;
};

const stopCamera = () => {
    if (video.value && video.value.srcObject) {
        const stream = video.value.srcObject;
        const tracks = stream.getTracks();
        tracks.forEach((track) => track.stop());
        video.value.srcObject = null;
    }
};
const snapshot = () => {
    if (video.value.srcObject) {
        const canvas = document.createElement("canvas");
        canvas.width = video.value.videoWidth;
        canvas.height = video.value.videoHeight;
        const context = canvas.getContext("2d");
        // Ensure transparency in the canvas context
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.globalAlpha = 1.0; // Full opacity for the video image
        context.drawImage(video.value, 0, 0, canvas.width, canvas.height);

        snapshotUrl.value = canvas.toDataURL("image/png");

        // Convert data URL to Blob
        const blob = dataURLToBlob(snapshotUrl.value);

        emit("capturedImage", { snapshotUrl: snapshotUrl.value, blob });
        closeModal();
    }
};

// Convert Data URL to Blob
function dataURLToBlob(dataUrl) {
    if (!dataUrl) {
        console.error("Invalid data URL provided.");
        return null;
    }

    const arr = dataUrl.split(",");
    const mime = arr[0].match(/:(.*?);/)[1];
    const bstr = atob(arr[1]);
    const u8arr = new Uint8Array(bstr.length);
    for (let i = 0; i < bstr.length; i++) {
        u8arr[i] = bstr.charCodeAt(i);
    }
    return new Blob([u8arr], { type: mime });
}

const closeModal = () => {
    showCamera.value = false;
    emit("update:show", false);
    stopCamera();
};

// Start camera when modal is shown, and stop when hidden
watch(
    () => props.show,
    (newVal) => {
        showCamera.value = newVal;
        if (!newVal) {
            stopCamera();
        }
    }
);
</script>

<template>
    <Dialog v-model:open="showCamera" @close="closeModal">
        <DialogContent
            @interactOutside="(e) => e.preventDefault()"
            @escapeKeyDown="(e) => e.preventDefault()"
            class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle> Camera </DialogTitle>
                <DialogDescription>
                    Capture a scene and then take snapshot.</DialogDescription
                >
            </DialogHeader>
            <Separator />
            <div
                class="flex flex-row flex-nowrap space-x-2 overflow-x-auto scrollbar-hide px-6"
            >
                <div class="video-wrapper">
                    <video ref="video" autoplay class="video-feed"></video>
                    <canvas ref="canvas" style="display: none"></canvas>
                </div>
            </div>
            <DialogFooter class="p-6 pt-0">
                <div class="flex gap-1 justify-end flex-wrap">
                    <Button @click="closeModal" variant="destructive"
                        >Cancel</Button
                    >
                    <Button @click="startCamera">Start</Button>
                    <Button @click="toggleCamera">Switch</Button>
                    <Button @click="snapshot">Capture</Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
video {
    width: 100%;
    height: 50%;
}
.video-wrapper {
    position: relative;
    width: 100%;
    height: auto;
    background-color: transparent;
}

.video-feed {
    width: 100%;
    height: auto;
    background-color: transparent;
    z-index: 1;
    position: relative;
}
</style>
