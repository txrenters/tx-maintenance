<script setup>
import { computed, onBeforeUnmount, watch } from "vue";
import { ChevronLeft, ChevronRight, Download } from "lucide-vue-next";

/**
 * Full-size photo viewer that steps through a list: arrow buttons, arrow keys,
 * a counter, and a one-click Download that saves under the photo's title.
 * The caller owns the list and the index; a `footer` slot takes any action
 * that belongs to the photo on screen (the Attachments tab puts Delete there).
 */
const props = defineProps({
    open: Boolean,
    // [{ id, url, name, downloadName }]
    images: { type: Array, default: () => [] },
    index: { type: Number, default: 0 },
});

const emit = defineEmits(["update:open", "update:index"]);

const count = computed(() => props.images.length);
const current = computed(() => props.images[props.index] || null);
const hasPrevious = computed(() => props.index > 0);
const hasNext = computed(() => props.index < count.value - 1);

// No wrap-around: someone counting photos should hit a hard end.
const go = (step) => {
    const next = props.index + step;
    if (next < 0 || next >= count.value) return;
    emit("update:index", next);
};

const onKeydown = (event) => {
    if (event.key === "ArrowLeft") {
        event.preventDefault();
        go(-1);
    } else if (event.key === "ArrowRight") {
        event.preventDefault();
        go(1);
    }
};

// The dialog moves focus to its own controls, so the keys are read on the
// window while it is open rather than on the image.
watch(
    () => props.open,
    (open) => {
        if (open) {
            window.addEventListener("keydown", onKeydown);
        } else {
            window.removeEventListener("keydown", onKeydown);
        }
    },
    { immediate: true }
);

onBeforeUnmount(() => window.removeEventListener("keydown", onKeydown));

// Warm the neighbours so the arrows feel instant on a slow connection.
watch(
    () => [props.open, props.index, props.images],
    () => {
        if (!props.open) return;
        [props.index - 1, props.index + 1]
            .map((i) => props.images[i]?.url)
            .filter(Boolean)
            .forEach((url) => {
                new Image().src = url;
            });
    },
    { immediate: true }
);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="sm:max-w-[1100px] w-[95vw] grid-rows-[auto_minmax(0,1fr)_auto] p-0 h-[95dvh]"
        >
            <DialogHeader class="p-4 pb-0 pr-12 text-left">
                <DialogTitle class="break-all line-clamp-1">
                    {{ current?.name || "Image Preview" }}
                </DialogTitle>
                <DialogDescription class="sr-only">
                    Photo viewer
                </DialogDescription>
                <div class="flex items-center gap-4 pt-1">
                    <Button
                        v-if="current"
                        as-child
                        size="sm"
                        variant="secondary"
                    >
                        <a
                            :href="current.url"
                            :download="current.downloadName"
                            data-testid="gallery-download"
                        >
                            <Download class="w-4 h-4 mr-2" /> Download
                        </a>
                    </Button>
                    <span
                        v-if="count > 1"
                        class="text-sm text-muted-foreground tabular-nums"
                        data-testid="gallery-counter"
                    >
                        {{ index + 1 }} / {{ count }}
                    </span>
                </div>
            </DialogHeader>

            <div class="relative min-h-0 px-4 flex items-center justify-center">
                <img
                    v-if="current"
                    :src="current.url"
                    :alt="current.name"
                    class="max-w-full max-h-full object-contain"
                    data-testid="gallery-image"
                />

                <template v-if="count > 1">
                    <button
                        type="button"
                        :disabled="!hasPrevious"
                        aria-label="Previous photo"
                        data-testid="gallery-prev"
                        class="absolute left-6 top-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white hover:bg-black/80 disabled:opacity-30 disabled:cursor-not-allowed"
                        @click="go(-1)"
                    >
                        <ChevronLeft class="w-8 h-8" />
                    </button>
                    <button
                        type="button"
                        :disabled="!hasNext"
                        aria-label="Next photo"
                        data-testid="gallery-next"
                        class="absolute right-6 top-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white hover:bg-black/80 disabled:opacity-30 disabled:cursor-not-allowed"
                        @click="go(1)"
                    >
                        <ChevronRight class="w-8 h-8" />
                    </button>
                </template>
            </div>

            <DialogFooter v-if="$slots.footer" class="p-4 pt-2">
                <slot name="footer" />
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
