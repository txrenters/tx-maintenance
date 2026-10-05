<script setup>
import { ref, watch, onMounted, computed } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import {
    Loader2,
    Camera,
    File,
    FileText,
    Pencil,
    Plus,
    X,
} from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { DateTime } from "luxon";

const { toast } = useToast();
const page = usePage();

const props = defineProps({
    workOrderNotes: Object,
    // The THMP crew's notes off the Jobber job. Defaulted so a page that has
    // not been taught to pass them still renders rather than breaking.
    jobberNotes: { type: Array, default: () => [] },
    isLoading: Boolean,
    workOrder: Object,
});

const emit = defineEmits(["fetch-notes"]);

const openNoteModal = ref(false);

// The same dialog writes a new note and edits an existing one; this holds the
// note being edited, or null when the dialog is adding.
const editingNoteId = ref(null);

const notesForm = useForm({
    subject: "",
    body: "",
    work_order_id: props.workOrder.id,
    technician_id: "",
});

// The THMP crew shares one login, so that login has to say who is typing
// before a note can be saved. The server only sends these names to that
// login; for everyone else the list is empty and the picker never shows.
const NOT_LISTED = "not_listed";
const noteTechnicians = computed(() => page.props.note_technicians ?? []);
const mustPickTechnician = computed(() => noteTechnicians.value.length > 0);

watch(
    () => notesForm.technician_id,
    () => notesForm.clearErrors("technician_id")
);

// The name an existing note was signed with, as a picker value. Blank when it
// was never signed or the technician has since left the list; an edit that
// leaves it blank keeps whatever the note already says.
const signedTechnicianValue = (note) => {
    if (note.technician_id) {
        const id = String(note.technician_id);
        return noteTechnicians.value.some(
            (technician) => String(technician.id) === id
        )
            ? id
            : "";
    }

    return note.technician_name ? NOT_LISTED : "";
};

const openCreateNote = () => {
    editingNoteId.value = null;
    notesForm.reset();
    notesForm.clearErrors();
    openNoteModal.value = true;
};

// PropertyWare can take an edit (updateNote), so a note it owns is editable
// too — unlike deleting, which it has no call for.
const openEditNote = (note) => {
    editingNoteId.value = note.id;
    notesForm.subject = note.subject ?? "";
    notesForm.body = note.body ?? "";
    notesForm.technician_id = signedTechnicianValue(note);
    notesForm.clearErrors();
    openNoteModal.value = true;
};

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

    if (editingNoteId.value !== null) {
        handleEditSubmit();
        return;
    }

    if (mustPickTechnician.value && !notesForm.technician_id) {
        notesForm.setError(
            "technician_id",
            "Select your name before saving the note."
        );
        toast({
            variant: "destructive",
            title: "Select your name",
            description: "Pick your name from the list, then submit the note.",
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

// The server sends the change to PropertyWare first and only keeps it when
// PropertyWare accepts, so a warning here means nothing was changed at all.
const handleEditSubmit = () => {
    notesForm.put(route("api.work_order_notes.update", editingNoteId.value), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: (page) => {
            const warning = page?.props?.flash?.warning;
            toast(
                warning
                    ? { title: "Not changed", description: warning }
                    : {
                          title: "Success",
                          description: "The note has been updated.",
                      }
            );
            if (!warning) {
                openNoteModal.value = false;
                editingNoteId.value = null;
                notesForm.reset();
            }
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

const isDeleteDialogOpen = ref(false);

// PropertyWare has no delete-note call, so a note it holds cannot be removed
// from here at all. The X still shows on those, and the dialog explains where
// to go instead — it used to disappear, which read as a broken button.
const deleteBlockedByPropertyWare = ref(false);

// Deleting is unrecoverable, so a stray click must not be enough on its own.
const askDeleteNote = (note) => {
    deleteBlockedByPropertyWare.value = !!note.propertyware_id;
    deleteNoteForm.id = note.id;
    isDeleteDialogOpen.value = true;
};

const confirmDeleteNote = () => {
    isDeleteDialogOpen.value = false;
    props.isLoading = true;
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

// Staff see where a dashboard note stands with PropertyWare and can re-send
// it; vendors keep the plain view.
const isStaff = computed(() =>
    ["admin", "woc", "accounting"].some((role) =>
        page.props.auth.user.roles.includes(role)
    )
);

// A note read off the Jobber job. Jobber owns it: there is no write path back
// and it is not in PropertyWare at all, so editing, deleting or pushing one
// here would either do nothing or be undone by the next sync.
const isJobberNote = (note) => note.source === "jobber";

// One list on screen, two sources on the wire. The keys are namespaced
// because the two tables have separate id spaces and would otherwise collide.
const allNotes = computed(() => {
    const jobber = (props.jobberNotes ?? []).map((note) => ({
        ...note,
        source: "jobber",
    }));
    const dashboard = Object.values(props.workOrderNotes ?? {}).map((note) => ({
        ...note,
        source: "propertyware",
    }));

    return [...jobber, ...dashboard].sort(
        (a, b) =>
            new Date(b.added_at ?? b.created_at ?? 0) -
            new Date(a.added_at ?? a.created_at ?? 0)
    );
});

// A dashboard note with no PropertyWare id is one PropertyWare has not taken
// yet (the save's push failed, or its answer could not be read). The app
// re-sends it on a schedule; this is the same send, on the spot.
const isPendingInPropertyWare = (note) =>
    !isJobberNote(note) &&
    isStaff.value &&
    !!note.user_id &&
    !note.propertyware_id;

const pushingNoteId = ref(null);

const pushNote = (noteId) => {
    pushingNoteId.value = noteId;
    router.post(
        route("api.work_order_notes.push", noteId),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page) => {
                // The server reads PropertyWare first, so "not sent" can also
                // mean the note was already there and is now linked.
                const warning = page?.props?.flash?.warning;
                toast(
                    warning
                        ? { title: "Not sent", description: warning }
                        : {
                              title: "Sent to PropertyWare",
                              description:
                                  "The note is now in PropertyWare's Notes & Docs.",
                          }
                );
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
            onFinish: () => {
                pushingNoteId.value = null;
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

// Who wrote the note. A THMP field note comes through the shared vendor
// login: the name the technician picked when saving it (technician_name)
// wins, and a note from before the picker falls back to the Jobber-assigned
// technician the server could work out (jobber_technician). Otherwise: the
// dashboard user who typed it, or "PropertyWare" for synced notes —
// PropertyWare never reports an author. A Jobber note carries the name Jobber
// recorded against it.
const noteAuthor = (note) =>
    note.technician_name ||
    note.jobber_technician ||
    note.author_name ||
    note.user?.name ||
    "PropertyWare";

// Who may change a note: staff, or the person who wrote it. Mirrors the guard
// both update() and destroy() apply on the server. Not gated on
// propertyware_id — whether PropertyWare will take the change is the dialog's
// business, and hiding the buttons is what made this look broken.
const canModifyNote = (note) =>
    !isJobberNote(note) &&
    (isStaff.value || note.user_id === page.props.auth.user.id);

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
                        $page.props.auth.user.roles.includes('accounting') ||
                        $page.props.auth.user.roles.includes('vendor')
                    "
                >
                    <Button
                        :disabled="isLoading"
                        size="icon"
                        @click="openCreateNote()"
                    >
                        <Plus v-if="!isLoading" class="" />
                        <Loader2 v-else class="w-4 h-4 animate-spin" />
                    </Button>
                </div>
            </div>
            <div class="mb-14">
                <div v-if="allNotes.length">
                    <div
                        class="p-2 mb-2 border bg-secondary"
                        v-for="note in allNotes"
                        :key="`${note.source}-${note.id}`"
                    >
                        <div class="flex justify-between gap-2">
                            <p class="font-bold">
                                <span
                                    v-if="isJobberNote(note)"
                                    class="mr-1 inline-block rounded bg-blue-100 px-1.5 py-0.5 align-middle text-[10px] font-medium uppercase text-blue-800 dark:bg-blue-900 dark:text-blue-200"
                                >
                                    Jobber
                                </span>
                                <span v-if="!isJobberNote(note)">{{
                                    note.subject
                                }}</span>
                            </p>
                            <div
                                v-if="canModifyNote(note)"
                                class="flex shrink-0 items-center gap-1"
                            >
                                <button
                                    type="button"
                                    title="Edit this note"
                                    @click.stop="openEditNote(note)"
                                    class="bg-secondary-foreground/70 text-secondary rounded-full p-1 w-5 h-5"
                                >
                                    <Pencil class="w-3 h-3" />
                                </button>
                                <button
                                    type="button"
                                    title="Delete this note"
                                    @click.stop="askDeleteNote(note)"
                                    class="bg-red-500 text-white rounded-full p-1 w-5 h-5"
                                >
                                    <X class="w-3 h-3" />
                                </button>
                            </div>
                        </div>

                        <p class="whitespace-pre-wrap break-words">
                            {{ note.message ?? note.body }}
                        </p>

                        <p class="text-xs">
                            Added: {{ formatAddedAt(note) }} &middot;
                            {{ noteAuthor(note) }}
                        </p>
                        <p
                            v-if="isPendingInPropertyWare(note)"
                            class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-amber-700 dark:text-amber-400"
                        >
                            <span>Not in PropertyWare yet, the app keeps retrying.</span>
                            <button
                                type="button"
                                class="font-medium underline disabled:opacity-50"
                                :disabled="pushingNoteId === note.id"
                                @click.stop="pushNote(note.id)"
                            >
                                {{
                                    pushingNoteId === note.id
                                        ? "Sending..."
                                        : "Send to PropertyWare now"
                                }}
                            </button>
                        </p>
                    </div>
                </div>

                <div v-else>No notes found!</div>
            </div>
        </div>
        <AlertDialog v-model:open="isDeleteDialogOpen">
            <AlertDialogContent v-if="deleteBlockedByPropertyWare">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        This note lives in PropertyWare
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        PropertyWare has no way to delete a note from here, and
                        removing it only on the dashboard would bring it back at
                        the next sync. Delete it in PropertyWare instead. You can
                        still edit its text here.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Close</AlertDialogCancel>
                </AlertDialogFooter>
            </AlertDialogContent>
            <AlertDialogContent v-else>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete this note?</AlertDialogTitle>
                    <AlertDialogDescription>
                        This can't be undone — the note hasn't been sent to
                        PropertyWare yet, so nothing will bring it back.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        class="destructive"
                        @click.prevent="confirmDeleteNote"
                    >
                        Delete note
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
        <Dialog v-model:open="openNoteModal">
            <DialogContent
                class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
            >
                <DialogHeader class="p-6 pb-0 text-left">
                    <DialogTitle>
                        {{ editingNoteId ? "Edit Note" : "Create Notes" }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            editingNoteId
                                ? "The change is sent to PropertyWare as well."
                                : "Fill out the input fields and then click submit."
                        }}</DialogDescription
                    >
                </DialogHeader>
                <Separator />
                <div
                    class="flex flex-col flex-nowrap overflow-x-auto scrollbar-hide px-6"
                >
                    <div v-if="mustPickTechnician" class="mb-3">
                        <Label>Your name</Label>
                        <Select v-model="notesForm.technician_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Select your name" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectItem
                                        v-for="technician in noteTechnicians"
                                        :key="technician.id"
                                        :value="String(technician.id)"
                                    >
                                        {{ technician.name }}
                                    </SelectItem>
                                    <SelectItem :value="NOT_LISTED">
                                        My name is not listed
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="notesForm.errors.technician_id"
                            class="mt-1 text-xs text-red-600 dark:text-red-400"
                        >
                            {{ notesForm.errors.technician_id }}
                        </p>
                        <p v-else class="mt-1 text-xs text-muted-foreground">
                            The note is saved under the name you pick.
                        </p>
                    </div>
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
