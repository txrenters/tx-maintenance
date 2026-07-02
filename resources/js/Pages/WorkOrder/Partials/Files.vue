<script setup>
import { Download, Eye, Expand, CircleX, X } from "lucide-vue-next";
import { DateTime } from "luxon";

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
                        <img
                            :src="file.attachment_url"
                            :alt="file.title"
                            class="w-full h-full object-contain opacity-100 group-hover:opacity-20 transition-opacity duration-200"
                        />
                    </div>
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
                    <a
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
    </div>
</template>
