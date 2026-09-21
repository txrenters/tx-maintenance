<script setup>
import { computed } from "vue";
import { Download } from "lucide-vue-next";
import { attachmentDownloadName } from "@/utils/attachmentDownloadName";

const props = defineProps({
    open: Boolean,
    // { url, name, mime } — mime may be empty for PropertyWare documents,
    // so the extension is used as a fallback.
    file: { type: Object, default: null },
});

const emit = defineEmits(["update:open"]);

const extension = computed(() =>
    (props.file?.name || props.file?.url || "").split(".").pop().toLowerCase()
);

const isImage = computed(() => {
    if (!props.file) return false;
    if (props.file.mime) return props.file.mime.startsWith("image/");
    return ["jpg", "jpeg", "png", "gif", "webp"].includes(extension.value);
});

const isPdf = computed(() => {
    if (!props.file) return false;
    return props.file.mime === "application/pdf" || extension.value === "pdf";
});

const downloadName = computed(() =>
    props.file
        ? attachmentDownloadName(props.file.name, props.file.url, props.file.mime)
        : ""
);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="sm:max-w-[1100px] w-[95vw] grid-rows-[auto_minmax(0,1fr)] p-0 h-[95dvh]"
        >
            <DialogHeader class="p-4 pb-0 pr-12 text-left">
                <DialogTitle class="break-all line-clamp-1">
                    {{ file?.name || "Preview" }}
                </DialogTitle>
                <DialogDescription class="sr-only">
                    File preview
                </DialogDescription>
                <!-- Previewable files still get a one-click save; the
                     unpreviewable branch below keeps its own button. -->
                <div v-if="file && (isImage || isPdf)" class="pt-1">
                    <Button as-child size="sm" variant="secondary">
                        <a
                            :href="file.url"
                            :download="downloadName"
                            data-testid="preview-download"
                        >
                            <Download class="w-4 h-4 mr-2" /> Download
                        </a>
                    </Button>
                </div>
            </DialogHeader>
            <div class="min-h-0 px-4 pb-4">
                <iframe
                    v-if="file && isPdf"
                    :src="file.url"
                    class="w-full h-full border rounded"
                    :title="file.name"
                ></iframe>
                <div
                    v-else-if="file && isImage"
                    class="w-full h-full overflow-auto"
                >
                    <img
                        :src="file.url"
                        :alt="file.name"
                        class="max-w-full mx-auto"
                    />
                </div>
                <div
                    v-else-if="file"
                    class="flex flex-col items-center justify-center gap-3 h-full"
                >
                    <p class="text-sm text-muted-foreground">
                        This file type can't be previewed in the browser.
                    </p>
                    <Button as-child>
                        <a :href="file.url" download="">
                            <Download class="w-4 h-4 mr-2" /> Download
                        </a>
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
