<script setup>
import { computed, nextTick, ref } from "vue";
import { FileText, Loader2, Paperclip, Send, X } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Button } from "@/Components/ui/button";
import { Textarea } from "@/Components/ui/textarea";

/**
 * Shared SMS composer for every work order conversation tab.
 *
 * The tabs differ in who they address and what they put in the request, so
 * sending stays in the tab; everything below the thread — the message body, the
 * work order reference suffix and attachments — lives here so the nine tabs
 * behave identically. Enter inserts a new line; only the Send button sends.
 */
const props = defineProps({
    /** The raw body the coordinator typed, without the reference suffix. */
    modelValue: { type: String, default: "" },
    /** e.g. " (Ref: WO#1234)" — appended unless the coordinator deletes it. */
    refSuffix: { type: String, default: "" },
    maxLength: { type: Number, default: 1600 },
    disabled: { type: Boolean, default: false },
    sending: { type: Boolean, default: false },
    placeholder: { type: String, default: "Type your message..." },
});

const emit = defineEmits(["update:modelValue", "send"]);

const { toast } = useToast();

const MAX_FILE_BYTES = 50 * 1024 * 1024;
const TEXTAREA_MAX_HEIGHT = 200;

const textareaRef = ref(null);
const fileInputRef = ref(null);
const attachedFiles = ref([]);

// The reference suffix is appended to the visible text but kept out of
// `modelValue`. Deleting it from the textarea opts this message out; it comes
// back on the next message because inbound replies are routed by it.
const includeRefSuffix = ref(true);

const activeRefSuffix = computed(() =>
    includeRefSuffix.value ? props.refSuffix : "",
);

const displayMessage = computed({
    get: () => `${props.modelValue}${activeRefSuffix.value}`,
    set: (value) => {
        const suffix = props.refSuffix;
        const withSuffixLimit = Math.max(0, props.maxLength - suffix.length);

        if (!suffix) {
            emit("update:modelValue", value.slice(0, props.maxLength));
            return;
        }
        if (value.endsWith(suffix)) {
            includeRefSuffix.value = true;
            emit(
                "update:modelValue",
                value.slice(0, -suffix.length).slice(0, withSuffixLimit),
            );
            return;
        }
        if (value.includes(suffix)) {
            includeRefSuffix.value = true;
            emit(
                "update:modelValue",
                value.replace(suffix, "").slice(0, withSuffixLimit),
            );
            return;
        }
        includeRefSuffix.value = false;
        emit("update:modelValue", value.slice(0, props.maxLength));
    },
});

const messageCount = computed(() => displayMessage.value.length);

const nearLimit = computed(() => messageCount.value > props.maxLength - 100);

const canSend = computed(
    () =>
        !props.disabled &&
        !props.sending &&
        (props.modelValue.trim() !== "" || attachedFiles.value.length > 0),
);

/** The shadcn Textarea wraps a single native element. */
const textareaEl = () => textareaRef.value?.$el ?? textareaRef.value ?? null;

const autoResize = () => {
    const el = textareaEl();
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.min(el.scrollHeight, TEXTAREA_MAX_HEIGHT)}px`;
};

const triggerFileInput = () => {
    fileInputRef.value?.click();
};

const handleFileSelect = (event) => {
    const files = Array.from(event.target.files || []);
    const allowed = files.filter(
        (file) =>
            file.type.startsWith("image/") ||
            file.type.startsWith("video/") ||
            file.type === "application/pdf",
    );

    if (allowed.length !== files.length) {
        toast({
            variant: "destructive",
            title: "Invalid file type",
            description: "Only image, video, or PDF files are allowed.",
        });
    }

    allowed.forEach((file) => {
        if (file.size > MAX_FILE_BYTES) {
            toast({
                variant: "destructive",
                title: "File too large",
                description: `${file.name} is too large. Maximum size is 50MB.`,
            });
            return;
        }

        const isVideo = file.type.startsWith("video/");

        // PDFs get a filename tile rather than a data-URL thumbnail.
        if (file.type === "application/pdf") {
            attachedFiles.value.push({
                file,
                url: null,
                name: file.name,
                isPdf: true,
            });
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            attachedFiles.value.push({
                file,
                url: e.target.result,
                name: file.name,
                isVideo,
            });
        };
        reader.readAsDataURL(file);
    });

    // Clear the input so the same file can be selected again
    event.target.value = "";
};

const removeFile = (index) => {
    attachedFiles.value.splice(index, 1);
};

const submit = () => {
    if (!canSend.value) return;

    emit("send", {
        text: displayMessage.value,
        files: attachedFiles.value.map((item) => item.file),
    });
};

/** Called by the parent once the send succeeds. */
const reset = () => {
    emit("update:modelValue", "");
    includeRefSuffix.value = true;
    attachedFiles.value = [];

    const el = textareaEl();
    if (el) el.style.height = "auto";
};

/** Drop a canned message into the box, reference suffix included. */
const insert = (text) => {
    includeRefSuffix.value = true;
    emit("update:modelValue", String(text ?? "").slice(0, props.maxLength));
    nextTick(autoResize);
};

defineExpose({ reset, insert });
</script>

<template>
    <div class="w-full">
        <div v-if="attachedFiles.length > 0" class="mb-2">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                <div
                    v-for="(item, index) in attachedFiles"
                    :key="index"
                    class="relative"
                >
                    <video
                        v-if="item.isVideo"
                        :src="item.url"
                        class="h-20 w-full rounded-lg border bg-black object-cover"
                        muted
                        playsinline
                    />
                    <div
                        v-else-if="item.isPdf"
                        class="bg-muted flex h-20 w-full flex-col items-center justify-center gap-1 rounded-lg border p-1 text-center"
                    >
                        <FileText class="text-muted-foreground h-6 w-6" />
                        <span
                            class="text-muted-foreground w-full truncate text-[10px]"
                            >{{ item.name }}</span
                        >
                    </div>
                    <img
                        v-else
                        :src="item.url"
                        :alt="item.name"
                        class="h-20 w-full rounded-lg border object-cover"
                    />
                    <Button
                        size="icon"
                        variant="destructive"
                        class="absolute -right-2 -top-2 h-6 w-6 rounded-full"
                        :title="`Remove ${item.name}`"
                        @click="removeFile(index)"
                    >
                        <X class="h-3 w-3" />
                    </Button>
                </div>
            </div>
        </div>

        <Textarea
            ref="textareaRef"
            v-model="displayMessage"
            :placeholder="placeholder"
            class="max-h-[200px] min-h-[44px] w-full resize-none overflow-y-auto rounded-lg"
            rows="3"
            :disabled="disabled || sending"
            :maxlength="maxLength"
            @input="autoResize"
        />

        <div class="mt-2 flex items-center justify-between gap-2">
            <div class="flex min-w-0 items-center gap-1">
                <Button
                    size="icon"
                    variant="ghost"
                    :disabled="disabled || sending"
                    title="Attach an image, video or PDF"
                    @click="triggerFileInput"
                >
                    <Paperclip class="h-4 w-4" />
                </Button>
                <span
                    class="text-muted-foreground hidden truncate text-xs sm:inline"
                >
                    Enter for a new line · click Send when ready
                </span>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <span
                    class="text-xs tabular-nums"
                    :class="nearLimit ? 'text-destructive' : 'text-muted-foreground'"
                >
                    {{ messageCount }}/{{ maxLength }}
                </span>
                <Button size="sm" :disabled="!canSend" @click.prevent="submit">
                    <Loader2 v-if="sending" class="mr-1.5 h-4 w-4 animate-spin" />
                    <Send v-else class="mr-1.5 h-4 w-4" />
                    Send
                </Button>
            </div>
        </div>

        <input
            ref="fileInputRef"
            type="file"
            multiple
            accept="image/*,video/*,application/pdf"
            class="hidden"
            @change="handleFileSelect"
        />
    </div>
</template>
