<script setup>
/**
 * Photo/file grid for a Jobber job, matching the work order Attachments tab
 * (Pages/WorkOrder/Partials/Attachments.vue + Files.vue): a blue section band
 * per group, bordered thumbnails with a red delete pip, title and date beneath.
 *
 * Shared by the board modal and the full job page so the two can't drift.
 */
import { computed } from "vue";
import { DateTime } from "luxon";
import { Download, Expand, X } from "lucide-vue-next";

const props = defineProps({
    attachments: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(["deleteImage"]);

const groups = computed(() => [
    {
        label: "Attachments",
        files: props.attachments.filter((f) => f.type === "attachment"),
    },
    {
        label: "Before Pictures",
        files: props.attachments.filter((f) => f.type === "before"),
    },
    {
        label: "After Pictures",
        files: props.attachments.filter((f) => f.type === "after"),
    },
]);

const getFileIcon = (filename) => {
    const ext = String(filename || "").split(".").pop().toLowerCase();
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
    const parsed =
        typeof date === "string" && date.includes("T")
            ? DateTime.fromISO(date, { zone: "utc" })
            : DateTime.fromFormat(String(date), "yyyy-MM-dd HH:mm:ss", {
                  zone: "utc",
              });
    return parsed.isValid ? parsed.toFormat("MM/dd/yyyy") : "------";
};
</script>

<template>
    <div class="space-y-6">
        <div v-for="group in groups" :key="group.label">
            <p class="font-semibold uppercase mb-4 p-2 bg-primary text-white">
                {{ group.label }}
            </p>

            <div v-if="group.files.length" class="flex gap-5 flex-wrap">
                <div
                    v-for="file in group.files"
                    :key="file.id"
                    class="rounded cursor-pointer hover:opacity-75"
                >
                    <div class="relative group inline-block p-3 border">
                        <button
                            v-if="canManage"
                            @click.stop="emit('deleteImage', file.id)"
                            class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 z-10"
                            title="Delete"
                        >
                            <X class="w-3 h-3" />
                        </button>

                        <a
                            :href="file.url"
                            target="_blank"
                            class="relative flex items-center justify-center w-32 h-32"
                            :title="file.is_image ? 'View' : 'Download'"
                        >
                            <component
                                :is="file.is_image ? Expand : Download"
                                width="40"
                                height="40"
                                stroke-width="1"
                                class="absolute inset-0 m-auto opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                            />

                            <img
                                :src="
                                    file.is_image
                                        ? file.url
                                        : getFileIcon(file.url)
                                "
                                :alt="file.title"
                                class="w-full h-full object-contain opacity-100 group-hover:opacity-20 transition-opacity duration-200"
                            />
                        </a>
                    </div>

                    <p class="max-w-32 break-words text-sm">{{ file.title }}</p>
                    <p class="text-xs text-muted-foreground">
                        Date: {{ formatDate(file.created_at) }}
                    </p>
                </div>
            </div>

            <p v-else class="text-sm text-muted-foreground">
                No {{ group.label.toLowerCase() }} yet.
            </p>
        </div>
    </div>
</template>
