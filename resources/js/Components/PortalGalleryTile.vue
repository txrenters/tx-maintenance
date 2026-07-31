<script setup>
import { FileText } from "lucide-vue-next";

/**
 * One square tile in a portal photo gallery. Images open in the page's
 * lightbox, videos play inline, and anything else falls back to a labelled
 * file link.
 */
defineProps({
    item: { type: Object, required: true },
});

defineEmits(["open"]);
</script>

<template>
    <button
        v-if="item.is_image"
        type="button"
        class="block aspect-square rounded-lg overflow-hidden bg-muted"
        :title="item.title"
        @click="$emit('open', item.url)"
    >
        <img :src="item.url" :alt="item.title" class="w-full h-full object-cover" />
    </button>

    <video
        v-else-if="item.is_video"
        :src="item.url"
        controls
        playsinline
        preload="metadata"
        class="aspect-square w-full rounded-lg object-cover bg-black"
    />

    <a
        v-else
        :href="item.url"
        target="_blank"
        rel="noopener noreferrer"
        class="flex flex-col items-center justify-center gap-1 aspect-square rounded-lg bg-muted p-2"
    >
        <FileText class="w-6 h-6 text-muted-foreground" />
        <span
            class="text-[10px] leading-tight text-muted-foreground text-center w-full truncate px-1"
            >{{ item.title }}</span
        >
    </a>
</template>
