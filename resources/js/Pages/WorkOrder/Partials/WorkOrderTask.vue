<script setup>
import { ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { Plus, Loader2, ListChecks } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import TaskCard from "@/Components/TaskCard.vue";
import { Button } from "@/Components/ui/button";
import { Switch } from "@/Components/ui/switch";
import { Label } from "@/Components/ui/label";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { Input } from "@/Components/ui/input";
import { Textarea } from "@/Components/ui/textarea";
import { Separator } from "@/Components/ui/separator";
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from "@/Components/ui/tooltip";

const props = defineProps({
    workOrderTasks: Array,
    service_status: Array,
    isEmergency: String,
    workOrder: Object,
    users: Object,
    isLoading: Boolean,
    handleTaskStatusChange: Function,
});
const { toast } = useToast();

const emit = defineEmits(["update-task-status"]);

const service_status_id = ref(props.workOrder.service_status_id);
const skip_automated_tasks = ref(props.workOrder.skip_automated_tasks);

const openModal = ref(false);

const taskForm = useForm({
    description: "",
    due_date: "",
    assigned_user_id: "",
    work_order_id: props.workOrder.id,
});

const handleEmits = () => {
    emit("update-task-status");
};

const handleServiceStatusChange = async (newValue) => {
    router.post(
        route("api.work_order.service_status_change", props.workOrder),
        { is_emergency: props.isEmergency, service_status_id: newValue },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description:
                        "Service status been been changed successfully!",
                });

                emit("update-task-status"); // use this to notify parent component that I need the new task to be fetch
                service_status_id.value = String(newValue);
            },
            onError: () => {
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

const handleSkipAutomatedTasksToggle = (newValue) => {
    router.patch(
        route("work_orders.update", props.workOrder.id),
        { skip_automated_tasks: newValue },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: newValue
                        ? "Automated tasks disabled for this work order"
                        : "Automated tasks enabled for this work order",
                });
                skip_automated_tasks.value = newValue;
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description:
                        "There was a problem updating the task settings. Please try again!",
                });
            },
        }
    );
};

const handleTaskSubmit = () => {
    taskForm.post(route("tasks.store"), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "Success",
                description: "Task has been created successfully!",
            });
            openModal.value = false;
            emit("update-task-status"); // use this to notify parent component that I need the new task to be fetch
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
</script>

<template>
    <div class="overflow-y-auto px-6 mb-6 w-full min-h-[300px]">
        <div class="flex justify-between items-center my-3">
            <p class="font-semibold uppercase text-xs mb-3">Task Details</p>
            <div class="flex gap-3 items-center">
                <TooltipProvider
                    v-if="
                        $page.props.auth.user.roles.includes('admin') ||
                        $page.props.auth.user.roles.includes('woc')
                    "
                >
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <div class="flex gap-2 items-center">
                                <Label
                                    for="skip-tasks-toggle"
                                    class="text-xs cursor-pointer whitespace-nowrap"
                                >
                                    Vacant
                                </Label>
                                <Switch
                                    id="skip-tasks-toggle"
                                    :checked="skip_automated_tasks"
                                    @update:checked="
                                        handleSkipAutomatedTasksToggle
                                    "
                                    :disabled="isLoading"
                                />
                            </div>
                        </TooltipTrigger>
                        <TooltipContent>
                            <p>
                                Toggle to disable automated task creation for
                                vacant units
                            </p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
                <Select
                    v-if="$page.props.auth.user.roles.includes('admin')"
                    :modelValue="String(service_status_id)"
                    @update:modelValue="handleServiceStatusChange"
                    :disabled="isLoading"
                >
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="Select an emergency" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <template
                                v-for="status in service_status"
                                :key="status.id"
                            >
                                <SelectItem :value="String(status.id)">
                                    {{ status.name }}
                                </SelectItem>
                            </template>
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <div
                    class="flex gap-2"
                    v-if="
                        $page.props.auth.user.roles.includes('admin') ||
                        $page.props.auth.user.roles.includes('woc')
                    "
                >
                    <Button
                        :disabled="isLoading"
                        size="icon"
                        @click="openModal = true"
                        title="Create Task"
                    >
                        <Plus v-if="!isLoading" class="" />
                        <Loader2 v-else class="w-4 h-4 animate-spin" />
                    </Button>
                </div>
                <!-- <div
                    class="flex gap-2"
                    v-if="
                        $page.props.auth.user.roles.includes('admin') ||
                        $page.props.auth.user.roles.includes('woc')
                    "
                >
                    <Button
                        :disabled="isLoading"
                        size="icon"
                        @click="openModal = true"
                        title="Generate Task"
                    >
                        <ListChecks v-if="!isLoading" class="" />
                        <Loader2 v-else class="w-4 h-4 animate-spin" />
                    </Button>
                </div> -->
            </div>
        </div>
        <div class="flex justify-center" v-if="isLoading">
            <Loader2 class="w-12 h-12 animate-spin text-primary" />
        </div>
        <div
            class="grid gap-3 overflow-y-auto px-6"
            v-if="!isLoading && workOrderTasks.length === 0"
        >
            <p class="font-semibold">No tasks available</p>
        </div>
        <div v-else>
            <TaskCard
                :tasks="workOrderTasks"
                @update-task-status="handleEmits"
            />
        </div>
    </div>

    <Dialog v-model:open="openModal">
        <DialogContent
            class="sm:max-w-[500px] grid-rows-[auto_minmax(0,1fr)_auto] p-0 max-h-[95dvh]"
        >
            <DialogHeader class="p-6 pb-0 text-left">
                <DialogTitle> Create Task </DialogTitle>
                <DialogDescription>
                    Add task details and then click submit if
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
                        v-model="taskForm.due_date"
                    />
                </div>
                <div class="mb-3">
                    <Label>Description</Label>
                    <Textarea class="mt-1" v-model="taskForm.description" />
                </div>
                <div class="">
                    <Label>Assigned User:</Label>

                    <Select v-model="taskForm.assigned_user_id">
                        <SelectTrigger class="w-full">
                            <SelectValue
                                placeholder="Select an user to assigned"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <template v-for="user in users" :key="user.id">
                                    <SelectItem :value="String(user.id)">
                                        {{ user.name }}
                                    </SelectItem>
                                </template>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <DialogFooter class="p-6 pt-0">
                <Button
                    type="submit"
                    :disabled="taskForm.processing"
                    @click.prevent="handleTaskSubmit"
                >
                    <Loader2
                        v-if="taskForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    Submit
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
