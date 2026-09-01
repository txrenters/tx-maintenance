<script setup>
import { ref, watch, onMounted, computed } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { Loader2, Camera, File, FileText, Plus, X } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { DateTime } from "luxon";

const { toast } = useToast();

const props = defineProps({
    workOrderNotes: Object,
    isLoading: Boolean,
    workOrder: Object,
});

const emit = defineEmits(["fetch-notes"]);

const openNoteModal = ref(false);

const notesForm = useForm({
    subject: "",
    body: "",
    work_order_id: props.workOrder.id,
});

const handleFormSubmit = () => {
    if (!notesForm.subject || !notesForm.body) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem with your request. Please try again!",
        });
        return;
    }
    notesForm.post(route("api.work_order_notes.store"), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: (page) => {
            // The note is always saved here; the warning means PropertyWare
            // refused the copy, so nobody is told "Success" for a half-save.
            const warning = page?.props?.flash?.warning;
            toast(
                warning
                    ? { title: "Saved here only", description: warning }
                    : {
                          title: "Success",
                          description: "Notes has been created successfully!",
                      }
            );
            openNoteModal.value = false;
            notesForm.reset();
            handleFetchNotes();
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
        },
    });
};

const deleteNoteForm = useForm({
    id: "",
});

const deleteNote = (note_id) => {
    props.isLoading = true;
    deleteNoteForm.id = note_id;
    deleteNoteForm.delete(
        route("api.work_order_notes.destroy", deleteNoteForm.id),
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const warning = page?.props?.flash?.warning;
                toast(
                    warning
                        ? { title: "Not deleted", description: warning }
                        : {
                              title: "Success",
                              description: "Notes has been deleted successfully!",
                          }
                );
                deleteNoteForm.reset();
                handleFetchNotes();
                props.isLoading = false;
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description:
                        "There was a problem with your request. Please try again!",
                });
                props.isLoading = false;
            },
        }
    );
};

const TIMEZONE = "America/Chicago";

// added_at is when the note was written: PropertyWare's own note date for a
// synced note, the save time for one typed here (see WorkOrderNotes::addedAt).
const formatAddedAt = (note) => {
    const raw = note.added_at ?? note.created_at;
    if (!raw) return "------";

    const parsed = DateTime.fromISO(raw, { zone: "utc" });
    if (!parsed.isValid) return "Invalid Date";

    // PropertyWare gives some of its own notes a bare day, which arrives as
    // midnight UTC. Show those as the day: shifting midnight to Central time
    // would print the evening before.
    if (parsed.hour === 0 && parsed.minute === 0 && parsed.second === 0) {
        return parsed.toFormat("EEE, MMMM d, yyyy");
    }

    return parsed.setZone(TIMEZONE).toFormat("EEE, MMMM d, yyyy h:mm a");
};

// Who wrote the note. A note typed on the dashboard carries its user; anything
// else came over from PropertyWare, which never reports an author name.
const noteAuthor = (note) => note.user?.name || "PropertyWare";

const handleFetchNotes = () => {
    emit("fetch-notes");
};
</script>

<template>
    <div>
        <div class="overflow-y-auto px-6 w-full min-h-[300px] mb-10">
            <div class="flex justify-between gap-2 items-center mb-3">
                <div>
                    <p class="font-semibold uppercase text-xs">Notes</p>
                </div>
                <div
                    class="flex gap-2"
                    v-if="
                        $page.props.auth.user.roles.includes('admin') ||
                        $page.props.auth.user.roles.includes('woc') ||
                        $page.props.auth.user.roles.includes('vendor')
                    "
                >
                    <Button
                        :disabled="isLoading"
                        size="icon"
                        @click="openNoteModal = true"
                    >
                        <Plus v-if="!isLoading" class="" />
                        <Loader2 v-else class="w-4 h-4 animate-spin" />
                    </Button>
                </div>
            </div>
            <div class="mb-14">
                <div v-if="workOrderNotes">
                    <div
                        class="p-2 mb-2 border bg-secondary"
                        v-for="note in workOrderNotes"
                        :key="note.id"
                    >
                        <div class="flex justify-between">
                            <p class="font-bold">{{ note.subject }}</p>
                            <button
                                v-if="
                                    !note.propertyware_id &&
                                    (note.user_id ===
                                        $page.props.auth.user.id ||
                                        !$page.props.auth.user.roles.includes(
                                            'vendor'
                                        ))
                                "
                                @click.stop="deleteNote(note.id)"
                                class="bg-red-500 text-white rounded-full p-1 w-5 h-5"
                            >
                                <X class="w-3 h-3" />
                            </button>
                        </div>

                        <p>{{ note.body }}</p>
                        <p class="text-xs">
                            Added: {{ formatAddedAt(note) }} &middot;
                            {{ noteAuthor(note) }}
                        </p>
                    </div>
                </div>

                <div v-else>No notes found!</div>
            </div>
        </div>
        <Dialog v-model:open="openNoteModal">
            <DialogContent
                class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
            >
                <DialogHeader class="p-6 pb-0 text-left">
                    <DialogTitle> Create Notes </DialogTitle>
                    <DialogDescription>
                        Fill out the input fields and then click
                        submit.</DialogDescription
                    >
                </DialogHeader>
                <Separator />
                <div
                    class="flex flex-col flex-nowrap overflow-x-auto scrollbar-hide px-6"
                >
                    <div class="mb-3">
                        <Label>Title</Label>
                        <Input
                            type="text"
                            placeholder="Enter file description"
                            v-model="notesForm.subject"
                        />
                    </div>
                    <div class="mb-3">
                        <Label>Description</Label>
                        <Textarea v-model="notesForm.body"></Textarea>
                    </div>
                </div>
                <DialogFooter class="p-6 pt-0">
                    <Button
                        type="submit"
                        :disabled="notesForm.processing"
                        @click.prevent="handleFormSubmit"
                    >
                        <Loader2
                            v-if="notesForm.processing"
                            class="w-4 h-4 animate-spin"
                        />
                        Submit
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
