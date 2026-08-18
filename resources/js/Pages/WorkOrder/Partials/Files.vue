<script setup>
import { ref } from "vue";
import { Download, Eye, Expand, CircleX, X } from "lucide-vue-next";
import { DateTime } from "luxon";
import FilePreviewDialog from "@/Components/FilePreviewDialog.vue";

const props = defineProps({
    files: Object,
    loading: Boolean,
});

const emit = defineEmits(["expandImage", "deleteImage"]);

const openImageModal = (image) => {
    emit("expandImage", image);
};
const deleteFile = (image) => {
    emit("deleteImage", image);
};
const isImage = (file) => {
    return file.filetype.startsWith("image/");
};
const isPdf = (file) => {
    return (
        file.filetype === "application/pdf" ||
        (file.filename || "").toLowerCase().endsWith(".pdf")
    );
};

const openPreview = ref(false);
const previewedFile = ref(null);

const openPreviewModal = (file) => {
    previewedFile.value = {
        url: file.attachment_url,
        name: file.title || file.filename,
        mime: file.filetype,
    };
    openPreview.value = true;
};

const getFileIcon = (filename) => {
    const ext = filename.split(".").pop().toLowerCase();
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

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        parsedDate = DateTime.fromISO(date, { zone: "utc" }).isValid
            ? DateTime.fromISO(date, { zone: "utc" })
            : DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", { zone: "utc" });
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("MM/dd/yyyy")
        : "Invalid Date";
};
</script>

<template>
    <div class="flex gap-5 flex-wrap">
        <div
            v-for="file in files"
            :key="file.id"
            class="rounded cursor-pointer hover:opacity-75"
        >
            <template v-if="isImage(file)">
                <div
                    class="relative group inline-block p-3 border"
                    title="View"
                    v-if="!loading"
                    @click="openImageModal(file)"
                >
                    <!-- Delete Icon (Outside the div) -->
                    <button
                        v-if="
                            $page.props.auth.user.roles.includes('admin') ||
                            $page.props.auth.user.roles.includes('woc') ||
                            $page.props.auth.user.roles.includes('vendor')
                        "
                        @click.stop="deleteFile(file.id)"
                        class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1"
                    >
                        <X class="w-3 h-3" />
                    </button>

                    <div
                        class="relative flex items-center justify-center w-32 h-32"
                    >
                        <!-- Expand Icon -->
                        <Expand
                            width="40"
                            height="40"
                            stroke-width="1"
                            class="absolute inset-0 m-auto opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                        />

                        <!-- Image Preview (Fixed Size) -->
                        <!-- Small WebP thumbnail; the expand and preview paths
                             below still load the full-resolution original. -->
                        <img
                            :src="file.thumbnail_url || file.attachment_url"
                            :alt="file.title"
                            loading="lazy"
                            decoding="async"
                            class="w-full h-full object-contain opacity-100 group-hover:opacity-20 transition-opacity duration-200"
                        />
                    </div>

                    <!-- Read-only AI photo-review flag (staff only; the API
                         omits ai_review for everyone else). Only doubtful
                         verdicts render — a clean review stays silent. -->
                    <span
                        v-if="
                            file.ai_review &&
                            file.ai_review.verdict !== 'looks_resolved'
                        "
                        class="absolute bottom-1 left-1 right-1 truncate rounded px-1 py-0.5 text-center text-[10px] font-medium"
                        :class="
                            file.ai_review.verdict === 'mismatch'
                                ? 'bg-red-100 text-red-800'
                                : 'bg-amber-100 text-amber-800'
                        "
                        :title="file.ai_review.note"
                        @click.stop="openImageModal(file)"
                    >
                        {{
                            file.ai_review.verdict === "mismatch"
                                ? "AI: check this photo"
                                : "AI: unclear photo"
                        }}
                    </span>
                </div>

                <div
                    v-else
                    class="w-32 h-32 bg-gray-200 animate-pulse p-3 border"
                ></div>
            </template>

            <template v-else>
                <!-- Show file icon -->
                <div
                    class="relative group inline-block p-3 border"
                    v-if="!loading"
                >
                    <button
                        v-if="
                            $page.props.auth.user.roles.includes('admin') ||
                            $page.props.auth.user.roles.includes('woc') ||
                            $page.props.auth.user.roles.includes('vendor')
                        "
                        @click.stop="deleteFile(file.id)"
                        class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1"
                    >
                        <X class="w-3 h-3" />
                    </button>
                    <!-- PDFs preview in-app; a small corner button still downloads -->
                    <div
                        v-if="isPdf(file)"
                        class="relative flex items-center justify-center w-32 h-32"
                        title="Preview"
                        @click="openPreviewModal(file)"
                    >
                        <Eye
                            width="40"
                            height="40"
                            stroke-width="1"
                            class="absolute inset-0 m-auto opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                        />
                        <img
                            :src="getFileIcon(file.filename)"
                            class="w-full h-full object-contain opacity-100 group-hover:opacity-20 transition-opacity duration-200"
                            alt="File Icon"
                        />
                        <a
                            :href="file.attachment_url"
                            download=""
                            title="Download"
                            @click.stop
                            class="absolute bottom-0 right-0 p-1 rounded bg-secondary opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                        >
                            <Download class="w-4 h-4" />
                        </a>
                    </div>
                    <a
                        v-else
                        :href="file.attachment_url"
                        download=""
                        class="relative flex items-center justify-center w-32 h-32"
                        title="Download"
                    >
                        <!-- Delete Icon (Small X) -->

                        <!-- Download icon (Hidden by default, shown on hover) -->
                        <Download
                            width="40"
                            height="40"
                            stroke-width="1"
                            class="absolute inset-0 m-auto opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                        />

                        <!-- File Icon -->
                        <img
                            :src="getFileIcon(file.filename)"
                            class="w-full h-full object-contain opacity-100 group-hover:opacity-20 transition-opacity duration-200"
                            alt="File Icon"
                        />
                    </a>
                </div>
                <div
                    v-else
                    class="w-32 h-32 bg-gray-200 animate-pulse p-3 border"
                ></div>
            </template>
            <div class="w-32">
                <p
                    class="text-xs mt-2 break-all line-clamp-2"
                    :title="file.title"
                >
                    {{ file.title }}
                </p>
                <p class="text-xs">Date: {{ formatDate(file.created_at) }}</p>
            </div>
        </div>
        <FilePreviewDialog v-model:open="openPreview" :file="previewedFile" />
    </div>
</template>
