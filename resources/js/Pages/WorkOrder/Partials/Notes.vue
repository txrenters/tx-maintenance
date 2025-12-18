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
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Notes has been created successfully!",
            });
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
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Notes has been deleted successfully!",
                });
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

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        if (date.includes("T")) {
            parsedDate = DateTime.fromISO(date, { zone: "utc" });
        } else {
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd", {
                zone: "utc",
            });
        }
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("EEE, MMMM d, yyyy")
        : "Invalid Date";
};
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
                                    note.user_id === $page.props.auth.user.id ||
                                    !$page.props.auth.user.roles.includes(
                                        'vendor'
                                    )
                                "
                                @click.stop="deleteNote(note.id)"
                                class="bg-red-500 text-white rounded-full p-1 w-5 h-5"
                            >
                                <X class="w-3 h-3" />
                            </button>
                        </div>

                        <p>{{ note.body }}</p>
                        <p class="text-xs">
                            Date: {{ formatDate(note.created_at) }}
                        </p>
                        <p class="text-xs" v-if="note.user?.name">
                            Created by: {{ note.user?.name }}
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
