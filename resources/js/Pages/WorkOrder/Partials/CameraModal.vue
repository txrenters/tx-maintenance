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
  currentFacingMode.value = currentFacingMode.value === "user" ? "environment" : "user";
  startCamera(currentFacingMode.value);
  console.log(currentFacingMode.value);
};

const startCamera = async (facingMode = "user") => {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode },
    });
    video.value.srcObject = stream;
    console.log("Camera started with mode:", facingMode); // Debug
  } catch (error) {
    if (error.name === "NotAllowedError") {
      console.error("Permission denied for camera.");
    } else if (error.name === "NotFoundError") {
      console.error("No camera device found.");
    } else {
      console.error("Unexpected error:", error);
    }
  }
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
    if (newVal) {
      startCamera();
    } else {
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
        <DialogDescription> Capture a scene and then take snapshot.</DialogDescription>
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
        <Button @click="closeModal" variant="destructive">Cancel</Button>
        <Button @click="toggleCamera">Switch Camera</Button>
        <Button @click="snapshot"> Take Snapshot </Button>
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
