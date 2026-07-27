<script setup>
import { watch } from "vue";
import Link from "@tiptap/extension-link";
import Underline from "@tiptap/extension-underline";
import StarterKit from "@tiptap/starter-kit";
import { EditorContent, useEditor } from "@tiptap/vue-3";

const props = defineProps({
    modelValue: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue"]);

const editor = useEditor({
    content: props.modelValue,
    editorProps: {
        attributes: {
            class: "prose prose-sm min-h-32 max-w-none px-3 py-2 focus:outline-none",
        },
    },
    extensions: [
        StarterKit,
        Underline,
        Link.configure({
            openOnClick: false,
            protocols: ["http", "https", "mailto"],
        }),
    ],
    onUpdate: ({ editor: currentEditor }) => {
        emit("update:modelValue", currentEditor.isEmpty ? "" : currentEditor.getHTML());
    },
});

const setLink = () => {
    const previousUrl = editor.value?.getAttributes("link").href ?? "";
    const url = window.prompt("Enter a URL", previousUrl);

    if (url === null || !editor.value) {
        return;
    }

    if (url.trim() === "") {
        editor.value.chain().focus().extendMarkRange("link").unsetLink().run();

        return;
    }

    editor.value
        .chain()
        .focus()
        .extendMarkRange("link")
        .setLink({ href: url.trim() })
        .run();
};

watch(
    () => props.modelValue,
    (value) => {
        if (editor.value && editor.value.getHTML() !== value) {
            editor.value.commands.setContent(value || "", false);
        }
    },
);
</script>

<template>
    <div class="overflow-hidden rounded-md border border-input bg-background">
        <div
            v-if="editor"
            class="flex flex-wrap gap-1 border-b border-input bg-muted/50 p-1"
        >
            <button
                type="button"
                class="rounded px-2 py-1 text-sm font-bold hover:bg-muted"
                :class="{ 'bg-muted': editor.isActive('bold') }"
                aria-label="Bold"
                @click="editor.chain().focus().toggleBold().run()"
            >
                B
            </button>
            <button
                type="button"
                class="rounded px-2 py-1 text-sm italic hover:bg-muted"
                :class="{ 'bg-muted': editor.isActive('italic') }"
                aria-label="Italic"
                @click="editor.chain().focus().toggleItalic().run()"
            >
                I
            </button>
            <button
                type="button"
                class="rounded px-2 py-1 text-sm underline hover:bg-muted"
                :class="{ 'bg-muted': editor.isActive('underline') }"
                aria-label="Underline"
                @click="editor.chain().focus().toggleUnderline().run()"
            >
                U
            </button>
            <button
                type="button"
                class="rounded px-2 py-1 text-sm hover:bg-muted"
                :class="{ 'bg-muted': editor.isActive('bulletList') }"
                @click="editor.chain().focus().toggleBulletList().run()"
            >
                • List
            </button>
            <button
                type="button"
                class="rounded px-2 py-1 text-sm hover:bg-muted"
                :class="{ 'bg-muted': editor.isActive('orderedList') }"
                @click="editor.chain().focus().toggleOrderedList().run()"
            >
                1. List
            </button>
            <button
                type="button"
                class="rounded px-2 py-1 text-sm hover:bg-muted"
                :class="{ 'bg-muted': editor.isActive('link') }"
                @click="setLink"
            >
                Link
            </button>
        </div>

        <EditorContent :editor="editor" />
    </div>
</template>
