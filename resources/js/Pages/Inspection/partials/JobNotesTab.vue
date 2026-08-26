<script setup>
/**
 * Notes tab for a Jobber job: a composer on top and the office's notes
 * underneath, newest first, each with who wrote it and when.
 *
 * The notes are ours alone — nothing is read from or sent to Jobber, and there
 * is no PropertyWare copy as there is for work order notes. Self-contained so
 * the board modal and the full job page render exactly the same thing. Emits
 * `saved` after a successful write so the modal — which keeps job data in
 * local state rather than Inertia props — can refetch.
 */
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { DateTime } from "luxon";
import { Loader2, X } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Button } from "@/Components/ui/button";
import { Card } from "@/Components/ui/card";
import { Textarea } from "@/Components/ui/textarea";

const props = defineProps({
    jobId: [Number, String],
    notes: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(["saved"]);

const { toast } = useToast();

const body = ref("");
const isSaving = ref(false);

// A note leaves the list the moment its delete is sent. The modal only
// refetches after the redirect lands, and a second click on the stale row in
// that gap would hit a note that is already gone (a 404). The set clears when
// fresh notes arrive.
const deletedIds = ref(new Set());
watch(
    () => props.notes,
    () => (deletedIds.value = new Set())
);
const visibleNotes = computed(() =>
    props.notes.filter((note) => !deletedIds.value.has(note.id))
);

// Several notes can land on one day, so unlike the photo and invoice dates
// this one carries the time, on the office clock.
const formatDate = (date) => {
    if (!date) return "------";
    const parsed =
        typeof date === "string" && date.includes("T")
            ? DateTime.fromISO(date, { zone: "utc" })
            : DateTime.fromFormat(String(date), "yyyy-MM-dd HH:mm:ss", {
                  zone: "utc",
              });
    return parsed.isValid
        ? parsed.setZone("America/Chicago").toFormat("MM/dd/yyyy h:mm a")
        : "------";
};

const submit = () => {
    if (!body.value.trim()) {
        toast({
            variant: "destructive",
            title: "Error",
            description: "Type a note first.",
        });
        return;
    }

    isSaving.value = true;
    router.post(
        route("jobber.notes.store", props.jobId),
        { body: body.value },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({ title: "Success", description: "Note added." });
                body.value = "";
                emit("saved");
            },
            onError: () =>
                toast({
                    variant: "destructive",
                    title: "Error",
                    description: "Failed to add the note.",
                }),
            onFinish: () => (isSaving.value = false),
        }
    );
};

const deleteNote = (id) => {
    if (deletedIds.value.has(id)) return;
    deletedIds.value = new Set([...deletedIds.value, id]);

    router.delete(route("jobber.notes.destroy", id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({ title: "Deleted", description: "Note removed." });
            emit("saved");
        },
        onError: () => {
            deletedIds.value = new Set(
                [...deletedIds.value].filter((noteId) => noteId !== id)
            );
            toast({
                variant: "destructive",
                title: "Error",
                description: "Failed to remove the note.",
            });
        },
    });
};
</script>

<template>
    <div>
        <div v-if="canManage" class="mb-6 space-y-2">
            <Textarea
                v-model="body"
                rows="3"
                placeholder="Add an internal note... (Ctrl+Enter to save)"
                :disabled="isSaving"
                @keydown.ctrl.enter.prevent="submit"
            />
            <div class="flex justify-end">
                <Button
                    size="sm"
                    :disabled="isSaving || !body.trim()"
                    @click.prevent="submit"
                >
                    <Loader2 v-if="isSaving" class="w-4 h-4 animate-spin" />
                    Add note
                </Button>
            </div>
        </div>

        <p class="font-semibold uppercase mb-4 p-2 bg-primary text-white">
            Notes
        </p>

        <div v-if="visibleNotes.length" class="space-y-3">
            <Card
                v-for="note in visibleNotes"
                :key="note.id"
                class="relative p-3"
            >
                <button
                    v-if="canManage"
                    @click.stop="deleteNote(note.id)"
                    class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 z-10"
                    title="Delete"
                >
                    <X class="w-3 h-3" />
                </button>

                <p class="text-sm whitespace-pre-wrap break-words">
                    {{ note.body }}
                </p>
                <p class="text-xs text-muted-foreground mt-2">
                    {{ note.author }} &middot; {{ formatDate(note.created_at) }}
                </p>
            </Card>
        </div>

        <p v-else class="text-sm text-muted-foreground">No notes yet.</p>
    </div>
</template>