<script setup>
import { computed, ref, watch } from "vue";
import axios from "axios";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card, CardContent, CardHeader } from "@/Components/ui/card";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { Textarea } from "@/Components/ui/textarea";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Eye, EyeOff, Loader2, RotateCcw } from "lucide-vue-next";

// One editable canned message: the current wording (override or default),
// its placeholder chips, a live preview with sample data, Save, and Reset.
// The server is the validation authority — a 422 message is shown as-is.

const props = defineProps({
    template: { type: Object, required: true },
});

const emit = defineEmits(["updated"]);

const { toast } = useToast();

const effectiveText = () => props.template.override ?? props.template.default;

const draft = ref(effectiveText());
const saving = ref(false);
const resetting = ref(false);
const errorMessage = ref(null);
const showPreview = ref(false);
const confirmResetOpen = ref(false);
const textareaWrap = ref(null);

// The parent swaps the template object in after every save/reset; re-seed the
// draft so the card always reflects what the server holds.
watch(
    () => props.template,
    () => {
        draft.value = effectiveText();
        errorMessage.value = null;
    }
);

const isDirty = computed(() => draft.value !== effectiveText());

const isSms = computed(() => props.template.channel === "sms");

const channelLabel = computed(() =>
    props.template.channel === "sms_email" ? "Text + Email" : "Text (SMS)"
);

const segmentEstimate = computed(() => {
    const length = draft.value.length;
    return length <= 160 ? 1 : Math.ceil(length / 153);
});

const preview = computed(() => {
    let text = draft.value;
    for (const token of props.template.tokens) {
        text = text.split(`{${token.name}}`).join(
            props.template.sample[token.name] ?? ""
        );
    }
    return text;
});

const insertToken = (name) => {
    const textarea = textareaWrap.value?.querySelector("textarea");
    const snippet = `{${name}}`;

    if (!textarea) {
        draft.value += snippet;
        return;
    }

    const start = textarea.selectionStart ?? draft.value.length;
    const end = textarea.selectionEnd ?? start;
    draft.value =
        draft.value.slice(0, start) + snippet + draft.value.slice(end);

    requestAnimationFrame(() => {
        textarea.focus();
        textarea.setSelectionRange(
            start + snippet.length,
            start + snippet.length
        );
    });
};

const save = () => {
    saving.value = true;
    errorMessage.value = null;
    axios
        .put(
            route("it-tools.automated-messages.templates.update", {
                key: props.template.key,
            }),
            { text: draft.value }
        )
        .then(({ data }) => {
            emit("updated", data.template);
            toast({
                title: "Message updated",
                description: `"${props.template.label}" will use the new wording on the next send.`,
            });
        })
        .catch((error) => {
            errorMessage.value =
                error.response?.data?.errors?.text?.[0] ||
                error.response?.data?.message ||
                "Could not save the message. Please try again.";
        })
        .finally(() => {
            saving.value = false;
        });
};

const reset = () => {
    resetting.value = true;
    errorMessage.value = null;
    axios
        .delete(
            route("it-tools.automated-messages.templates.reset", {
                key: props.template.key,
            })
        )
        .then(({ data }) => {
            confirmResetOpen.value = false;
            emit("updated", data.template);
            toast({
                title: "Back to the default wording",
                description: `"${props.template.label}" was reset.`,
            });
        })
        .catch((error) => {
            errorMessage.value =
                error.response?.data?.message ||
                "Could not reset the message. Please try again.";
        })
        .finally(() => {
            resetting.value = false;
        });
};
</script>

<template>
    <Card>
        <CardHeader class="pb-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="space-y-1">
                    <p class="text-xs uppercase text-muted-foreground">
                        {{ template.automation_label }}
                    </p>
                    <p class="flex items-center gap-2 text-sm font-semibold">
                        {{ template.label }}
                        <Badge
                            v-if="template.is_overridden"
                            class="font-normal"
                        >
                            Customized
                        </Badge>
                        <Badge v-else variant="outline" class="font-normal">
                            Default
                        </Badge>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ template.sends_when }}
                    </p>
                </div>
                <span class="text-xs text-muted-foreground">
                    {{ channelLabel }}
                </span>
            </div>
        </CardHeader>
        <CardContent class="space-y-3">
            <div ref="textareaWrap">
                <Textarea
                    v-model="draft"
                    :rows="Math.min(14, Math.max(4, draft.split('\n').length + 1))"
                    class="font-mono text-sm"
                />
            </div>

            <div
                v-if="template.tokens.length"
                class="flex flex-wrap items-center gap-1.5"
            >
                <span class="text-xs text-muted-foreground">Placeholders:</span>
                <button
                    v-for="token in template.tokens"
                    :key="token.name"
                    type="button"
                    :title="token.description"
                    @click="insertToken(token.name)"
                >
                    <Badge variant="secondary" class="font-mono font-normal">
                        {{ "{" + token.name + "}" }}
                    </Badge>
                </button>
            </div>

            <p v-if="errorMessage" class="text-sm text-destructive">
                {{ errorMessage }}
            </p>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-xs text-muted-foreground">
                    {{ draft.length }} characters<template v-if="isSms">
                        · ~{{ segmentEstimate }} SMS segment{{
                            segmentEstimate === 1 ? "" : "s"
                        }}</template
                    >
                </span>
                <div class="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="gap-1"
                        @click="showPreview = !showPreview"
                    >
                        <EyeOff v-if="showPreview" class="h-4 w-4" />
                        <Eye v-else class="h-4 w-4" />
                        {{ showPreview ? "Hide preview" : "Preview" }}
                    </Button>
                    <Button
                        v-if="template.is_overridden"
                        variant="outline"
                        size="sm"
                        class="gap-1"
                        @click="confirmResetOpen = true"
                    >
                        <RotateCcw class="h-4 w-4" />
                        Reset to default
                    </Button>
                    <Button
                        size="sm"
                        :disabled="!isDirty || saving"
                        @click="save"
                    >
                        <Loader2
                            v-if="saving"
                            class="mr-1 h-4 w-4 animate-spin"
                        />
                        Save
                    </Button>
                </div>
            </div>

            <div v-if="showPreview" class="rounded-md border bg-muted/40 p-3">
                <p class="mb-2 text-xs uppercase text-muted-foreground">
                    Preview with sample details
                </p>
                <p class="whitespace-pre-wrap break-words text-sm">{{ preview }}</p>
            </div>
        </CardContent>
    </Card>

    <Dialog v-model:open="confirmResetOpen">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Reset to the default wording?</DialogTitle>
                <DialogDescription>
                    "{{ template.label }}" will go back to the original message
                    and your custom wording will be discarded.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    @click="confirmResetOpen = false"
                >
                    Keep my wording
                </Button>
                <Button
                    variant="destructive"
                    :disabled="resetting"
                    @click="reset"
                >
                    <Loader2
                        v-if="resetting"
                        class="mr-1 h-4 w-4 animate-spin"
                    />
                    Reset
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
