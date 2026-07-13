<script setup>
import { ref, computed, watch, onMounted } from "vue";
import { router, useForm, usePage } from "@inertiajs/vue3";
import { useTaskSelection } from "@/composables/useTaskSelection";
import {
    Loader2,
    Undo2,
    Ellipsis,
    Pencil,
    Trash2,
    Search,
} from "lucide-vue-next";
import { DateTime } from "luxon";
import axios from "axios";
import { useToast } from "@/Components/ui/toast/use-toast";
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from "@/Components/ui/combobox";
const { toast } = useToast();

const props = defineProps({
    tasks: Object,
    workorder: Object,
    // Isolates this card's bulk selection. The modal falls back to the work
    // order id; the /tasks page passes a distinct scope per list (Past Due /
    // Due Today / Upcoming) so their selections don't bleed into each other.
    scope: { type: String, default: null },
    assignableUsers: { type: Array, default: () => [] },
});
const page = usePage();

const emit = defineEmits(["update-task-status"]);

// --- Bulk task selection & completion -------------------------------------
// Checking a task no longer completes it immediately; it selects the task so it
// can be completed in bulk via the "Complete All" action bar. Selection lives in
// a shared, per-scope store so it survives the Tasks tab being unmounted on tab
// switches without leaking between independent lists.
const {
    selectedTaskIds,
    isSelected,
    toggle: toggleSelection,
    setMany,
    clear: clearSelection,
    prune,
} = useTaskSelection(() => props.scope ?? props.workorder?.id);

// Keep the selection in sync with the tasks actually present (dropping ids for
// deleted/regenerated tasks) whenever the list changes or the tab re-mounts.
const syncSelection = () => {
    prune(props.tasks.map((task) => task.id));
};

onMounted(syncSelection);
watch(() => props.tasks, syncSelection, { deep: false });

// A task can be completed if it isn't done and (for optional tasks) has an option.
const isCompletable = (task) =>
    task.status !== "completed" &&
    (task.task?.is_optional ? !!task.option : true);

// The service status a task transitions to, given its (selected) option.
const resolvedNextStatusName = (task) => {
    if (task.task?.is_optional) {
        const detail = (task.task?.task_details || []).find(
            (d) => d.task_for === task.option
        );
        return detail?.task_service_status?.name ?? null;
    }
    return task.task?.next_service_status?.name ?? null;
};

// A task changes the work order's service status when it has a template and its
// resolved next status isn't "Not Changed".
const changesStatus = (task) => {
    const name = resolvedNextStatusName(task);
    return name !== null && name !== "Not Changed";
};

// Routine tasks just mark done (manual tasks or "Not Changed" template tasks);
// these are what "Select all" targets.
const isRoutineTask = (task) => !changesStatus(task);

const routineCompletableTasks = computed(() =>
    props.tasks.filter((task) => isCompletable(task) && isRoutineTask(task))
);

const allRoutineSelected = computed(
    () =>
        routineCompletableTasks.value.length > 0 &&
        routineCompletableTasks.value.every((task) =>
            selectedTaskIds.value.includes(task.id)
        )
);

const toggleSelectAll = (checked) => {
    const routineIds = routineCompletableTasks.value.map((task) => task.id);
    if (checked) {
        // Keep any manually-selected tasks, add all routine ones.
        setMany(Array.from(new Set([...selectedTaskIds.value, ...routineIds])));
    } else {
        setMany(
            selectedTaskIds.value.filter((id) => !routineIds.includes(id))
        );
    }
};

const selectedTasks = computed(() =>
    props.tasks.filter((task) => selectedTaskIds.value.includes(task.id))
);

const openCompleteModal = ref(false);
const completing = ref(false);

const submitBulkComplete = async () => {
    completing.value = true;
    try {
        const payload = {
            tasks: selectedTasks.value.map((task) => ({
                id: task.id,
                option: task.task?.is_optional ? task.option : null,
            })),
        };

        const { data } = await axios.post(
            route("api.work_order.tasks.bulk_complete", props.workorder?.id),
            payload
        );

        const skippedNote = data.skipped ? ` ${data.skipped} skipped.` : "";
        toast({
            title: "Success",
            description: `${data.completed} task(s) completed.${skippedNote}`,
        });

        clearSelection();
        openCompleteModal.value = false;
        emit("update-task-status");
    } catch (error) {
        toast({
            variant: "destructive",
            title: "Uh oh! Something went wrong.",
            description:
                "There was a problem completing the tasks. Please try again!",
        });
    } finally {
        completing.value = false;
    }
};

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    try {
        if (typeof date === "string") {
            if (date.includes("T")) {
                // Handle ISO format (2025-03-06T17:41:20.000000Z)
                parsedDate = DateTime.fromISO(date, { zone: "utc" });
            } else if (date.includes("-")) {
                // Handle date string (2025-03-06 or 2025-03-06 23:10:06)
                parsedDate = DateTime.fromFormat(
                    date.split(" ")[0],
                    "yyyy-MM-dd",
                    {
                        zone: "utc",
                    }
                );
            } else {
                return "Invalid Date Format";
            }
        } else if (date instanceof Date) {
            parsedDate = DateTime.fromJSDate(date);
        } else {
            return "Invalid Date";
        }

        if (!parsedDate.isValid) return "Invalid Date";

        // Format as "Sat, March 29, 2025"
        return parsedDate.toFormat("EEE, MMMM d, yyyy");
    } catch (error) {
        console.error("Date formatting error:", error);
        return "Invalid Date";
    }
};

const openEditModal = ref(false);

const editTaskForm = useForm({
    id: "",
    description: "",
    due_date: "",
    status: "",
    assigned_user_id: "",
});

const userSearchTerm = ref("");

const selectedAssignedUser = computed({
    get() {
        return (
            props.assignableUsers.find(
                (user) =>
                    String(user.id) === String(editTaskForm.assigned_user_id)
            ) || null
        );
    },
    set(user) {
        editTaskForm.assigned_user_id = user ? String(user.id) : "";
    },
});

const filteredAssignableUsers = computed(() => {
    const term = userSearchTerm.value.trim().toLowerCase();

    if (!term) {
        return props.assignableUsers;
    }

    return props.assignableUsers.filter((user) =>
        user.name.toLowerCase().includes(term)
    );
});

const handleEditForm = (task) => {
    if (task.status === "completed") {
        // if complete dont edit task
        return;
    }

    if (page.props.auth.user.roles.includes("vendor")) {
        // vendor can't edit task
        return;
    }

    openEditModal.value = true;
    userSearchTerm.value = "";
    editTaskForm.id = task.id;
    editTaskForm.description = task.description;
    editTaskForm.due_date = task.due_date;
    editTaskForm.assigned_user_id = task.assigned_user_id
        ? String(task.assigned_user_id)
        : "";
};

const handleUndoTask = (taskId) => {
    router.post(
        route("api.task.undo", taskId),
        { status: "pending" },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Task has been undone successfully!",
                });
                console.log("emitting update-task-status");

                emit("update-task-status"); // use this to notify parent component that I need the new task to be fetch
            },
            onError: () => {
                console.log("destructive");

                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description:
                        "There was a problem with your request. Please try again!",
                });
            },
        }
    );
};

const deletingTaskId = ref(null);
const openDeleteModal = ref(false);
const taskToDelete = ref(null);

const handleDeleteTask = (taskId) => {
    taskToDelete.value = taskId;
    openDeleteModal.value = true;
};

const confirmDeleteTask = () => {
    if (!taskToDelete.value) return;

    deletingTaskId.value = taskToDelete.value;

    router.delete(route("api.task.destroy", taskToDelete.value), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Task has been deleted successfully!",
            });
            emit("update-task-status");
            openDeleteModal.value = false;
            taskToDelete.value = null;
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
            deletingTaskId.value = null;
        },
    });
};

const EditFormSubmit = () => {
    editTaskForm.patch(route("tasks.update", editTaskForm.id), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Task has been changed successfully!",
            });
            emit("update-task-status"); // use this to notify parent component that I need the new task to be fetch
            openEditModal.value = false;
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
            openEditModal.value = false;
        },
    });
};

const checkDueTask = (task) => {
    const today = new Date().toISOString().split("T")[0];

    const scheduled_end_date =
        props.workorder?.scheduled_end_date ?? task?.scheduled_end_date;

    // Completed tasks take priority
    if (task.status === "completed") return "completed";

    // Determine based on scheduled_end_date if available
    if (scheduled_end_date) {
        if (scheduled_end_date === today) return "blue"; // Due today
        if (scheduled_end_date < today) return "red"; // Overdue
        if (scheduled_end_date > today) return "green"; // Upcoming
    }

    // Fallback to task's due_date if scheduled_end_date is missing
    if (task.due_date) {
        if (task.due_date === today) return "blue";
        if (task.due_date < today) return "red";
        if (task.due_date > today) return "green";
    }

    // Default color
    return "green";
};
</script>

<template>
    <!-- Top action bar: select-all plus the bulk Complete All actions, kept at
         the top (and sticky) so staff never scroll past completed tasks just
         to reach the button. -->
    <div
        v-if="routineCompletableTasks.length || selectedTaskIds.length"
        class="sticky top-0 z-10 -mx-1 mb-2 flex flex-wrap items-center justify-between gap-2 border-b bg-background/95 px-2 py-2 backdrop-blur"
    >
        <div class="flex items-center gap-2">
            <Checkbox
                v-if="routineCompletableTasks.length"
                :checked="allRoutineSelected"
                @update:checked="toggleSelectAll"
            />
            <span
                v-if="routineCompletableTasks.length"
                class="text-xs text-muted-foreground"
            >
                Select all completable tasks
            </span>
        </div>
        <div v-if="selectedTaskIds.length" class="flex items-center gap-2">
            <span class="text-xs font-medium">
                {{ selectedTaskIds.length }} selected
            </span>
            <Button variant="outline" size="sm" @click="clearSelection">
                Cancel
            </Button>
            <Button size="sm" @click="openCompleteModal = true">
                Complete All ({{ selectedTaskIds.length }})
            </Button>
        </div>
    </div>
    <div v-for="task in tasks" :key="task.id" class="hover:bg-opacity-50">
        <Card
            class="w-full p-2 mb-2 cursor-pointer hover:shadow-lg transition-all"
            :class="{
                'bg-secondary': checkDueTask(task) === 'completed',
                'bg-red-500 text-white': checkDueTask(task) === 'red',
                'bg-primary text-white': checkDueTask(task) === 'blue',
                'bg-green-500 text-white': checkDueTask(task) === 'green',
            }"
        >
            <p v-if="task.work_order_no">#{{ task.work_order_no }}</p>
            <Separator class="mb-2" v-if="task.work_order_no" />

            <div class="flex justify-between">
                <div class="flex flex-col gap-2 w-full">
                    <div class="flex text-xs items-center gap-2">
                        <p class="text-xs">
                            📅 Due
                            {{
                                task.status === "completed"
                                    ? formatDate(task.updated_at)
                                    : formatDate(task.due_date) ?? "No due date"
                            }}
                        </p>
                        <button
                            v-if="
                                task.status !== 'completed' &&
                                ($page.props.auth.user.roles.includes(
                                    'admin'
                                ) ||
                                    $page.props.auth.user.roles.includes('woc'))
                            "
                            @click.stop="handleEditForm(task)"
                            class="hover:opacity-70 transition-opacity"
                            title="Edit task"
                            type="button"
                        >
                            <Pencil class="w-3 h-3" />
                        </button>
                        <button
                            v-if="
                                $page.props.auth.user.roles.includes('admin') ||
                                $page.props.auth.user.roles.includes('superadmin') ||
                                $page.props.auth.user.roles.includes('woc')
                            "
                            @click.stop="handleDeleteTask(task.id)"
                            class="hover:opacity-70 transition-opacity"
                            title="Delete task"
                            type="button"
                            :disabled="deletingTaskId === task.id"
                        >
                            <Loader2
                                v-if="deletingTaskId === task.id"
                                class="w-3 h-3 animate-spin"
                            />
                            <Trash2 v-else class="w-3 h-3" />
                        </button>
                    </div>
                    <p class="text-sm" @click="handleEditForm(task)">
                        {{ task.description }}
                    </p>
                </div>
                <div v-if="isCompletable(task)" class="mr-2">
                    <Checkbox
                        class="bg-white"
                        :checked="isSelected(task.id)"
                        @update:checked="
                            (checked) => toggleSelection(task.id, checked)
                        "
                    />
                </div>
                <div v-else class="mr-2">
                    <button
                        type="button"
                        v-if="
                            task.status === 'completed' &&
                            task.task?.next_service_status?.name === 'Not Changed'
                        "
                        class="hover:text-red-500"
                        title="Undo"
                        @click.prevent="handleUndoTask(task.id)"
                    >
                        <Undo2 class="w-4 h-4" />
                    </button>
                </div>
            </div>
            <div v-if="!task.task?.type">
                <div
                    class="flex flex-col text-xs gap-1"
                    @click="handleEditForm(task)"
                >
                    <p>Assigned:</p>
                    <p class="flex gap-1 items-center">
                        <Avatar class="w-5 h-5">
                            <AvatarImage
                                :src="
                                    task.assigned_user?.profile_photo_url ||
                                    'default.jpg'
                                "
                            />
                            <AvatarFallback></AvatarFallback>
                        </Avatar>
                        {{ task.assigned_user.name }}
                    </p>
                </div>
            </div>
            <div class="flex justify-between mt-2 items-end mr-2" v-else>
                <div
                    class="flex flex-col text-xs gap-1"
                    @click="handleEditForm(task)"
                >
                    <p>Assigned: {{ task.task.type }}</p>
                    <p class="flex gap-1 items-center">
                        <Avatar class="w-5 h-5">
                            <AvatarImage
                                :src="
                                    task.assigned_user?.profile_photo_url ||
                                    'default.jpg'
                                "
                            />
                            <AvatarFallback></AvatarFallback>
                        </Avatar>
                        {{ task.assigned_user.name }}
                    </p>
                </div>
                <div
                    class="flex flex-col text-xs gap-1 justify-end"
                    v-if="task.task.is_optional"
                >
                    <div class="flex justify-end">
                        <p class="">
                            Selected:
                            <span v-if="task.status === 'completed'">{{
                                task.option ?? "No"
                            }}</span>
                        </p>
                        <select
                            v-model="task.option"
                            v-if="task.status !== 'completed'"
                            class="text-black"
                        >
                            <option selected value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                    <p v-if="task.option">
                        Done:
                        <span
                            v-for="detail in task.task.task_details"
                            :key="detail.id"
                        >
                            <span v-if="detail.task_for === task.option">{{
                                detail.task_service_status.name
                            }}</span>
                        </span>
                    </p>
                </div>
                <div v-else>
                    <div class="flex justify-end">
                        <p class="text-xs">
                            Done:
                            {{ task.task.next_service_status.name }}
                        </p>
                    </div>
                </div>
            </div>
        </Card>
    </div>
    <Dialog v-model:open="openCompleteModal">
        <DialogContent class="sm:max-w-[420px]">
            <DialogHeader>
                <DialogTitle>Complete selected tasks</DialogTitle>
                <DialogDescription>
                    Are you sure you want to mark
                    {{ selectedTaskIds.length }} task(s) as completed?
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    @click="openCompleteModal = false"
                    :disabled="completing"
                >
                    Cancel
                </Button>
                <Button @click="submitBulkComplete" :disabled="completing">
                    <Loader2
                        v-if="completing"
                        class="w-4 h-4 mr-2 animate-spin"
                    />
                    Complete All
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="openEditModal">
        <DialogContent
            class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle> Edit Task </DialogTitle>
                <DialogDescription>
                    Edit task details and then click save if
                    done.</DialogDescription
                >
            </DialogHeader>
            <Separator />
            <div
                class="flex flex-col flex-nowrap overflow-x-auto scrollbar-hide px-6"
            >
                <div class="mb-3">
                    <Label>Due Date</Label>
                    <Input
                        type="date"
                        placeholder="Enter invoice amount"
                        v-model="editTaskForm.due_date"
                    />
                </div>
                <div class="mb-3">
                    <Label>Description</Label>
                    <Textarea class="mt-1" v-model="editTaskForm.description" />
                </div>
                <div class="mb-3" v-if="assignableUsers.length">
                    <Label>Assigned User</Label>
                    <Combobox
                        v-model="selectedAssignedUser"
                        by="id"
                        :ignore-filter="true"
                    >
                        <ComboboxAnchor class="w-full mt-1">
                            <div
                                class="relative flex w-full items-center border rounded-md"
                            >
                                <Search
                                    class="absolute left-2 h-4 w-4 text-muted-foreground"
                                />
                                <ComboboxInput
                                    class="w-full pl-8 pr-2 border-none focus-visible:ring-0"
                                    :display-value="(user) => user?.name ?? ''"
                                    :model-value="userSearchTerm"
                                    @update:model-value="
                                        userSearchTerm = $event
                                    "
                                    placeholder="Search a user to assign..."
                                />
                            </div>
                        </ComboboxAnchor>
                        <ComboboxList
                            class="w-[--reka-popper-anchor-width] max-h-48"
                        >
                            <ComboboxEmpty>No user found.</ComboboxEmpty>
                            <ComboboxGroup>
                                <ComboboxItem
                                    v-for="user in filteredAssignableUsers"
                                    :key="user.id"
                                    :value="user"
                                    @select="userSearchTerm = ''"
                                >
                                    {{ user.name }}
                                </ComboboxItem>
                            </ComboboxGroup>
                        </ComboboxList>
                    </Combobox>
                    <p
                        v-if="editTaskForm.errors.assigned_user_id"
                        class="text-xs text-red-500 mt-1"
                    >
                        {{ editTaskForm.errors.assigned_user_id }}
                    </p>
                </div>
            </div>
            <DialogFooter class="p-6 pt-0">
                <Button
                    type="submit"
                    :disabled="editTaskForm.processing"
                    @click.prevent="EditFormSubmit"
                >
                    <Loader2
                        v-if="editTaskForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    Save Changes
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="openDeleteModal">
        <DialogContent class="sm:max-w-[400px]">
            <DialogHeader>
                <DialogTitle>Delete Task</DialogTitle>
                <DialogDescription>
                    Are you sure you want to delete this task? This action
                    cannot be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    @click="openDeleteModal = false"
                    :disabled="deletingTaskId !== null"
                >
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    @click="confirmDeleteTask"
                    :disabled="deletingTaskId !== null"
                >
                    <Loader2
                        v-if="deletingTaskId !== null"
                        class="w-4 h-4 mr-2 animate-spin"
                    />
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
