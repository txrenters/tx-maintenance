<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import TaskCard from "@/Components/TaskCard.vue";
import { router } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";
import { WhenVisible } from "@inertiajs/vue3";
import debounce from "lodash/debounce";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/Components/ui/tabs";
import { Checkbox } from "@/Components/ui/checkbox";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import { Loader2 } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    dueTodayTasks: Object,
    upcomingTasks: Object,
    pastDueTasks: Object,
    completedTasks: Object,
    total_dueTodayTasks: Number,
    total_upcomingTasks: Number,
    total_pastDueTasks: Number,
    total_completedTasks: Number,
    assignableUsers: { type: Array, default: () => [] },
    closedWorkOrders: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    filter: { type: Object, default: () => ({}) },
});

const { toast } = useToast();

const url = route("tasks.index");
const search = ref(props.filter.search ?? "");
const assigned = ref(props.filter.assigned ?? "");
const status = ref(props.filter.status ?? "");

const applyFilters = () => {
    router.get(
        url,
        {
            search: search.value || undefined,
            assigned: assigned.value || undefined,
            status: status.value || undefined,
        },
        {
            // The task buckets are Inertia::optional props, so they must be
            // requested explicitly here — otherwise changing the filter only
            // refreshes the count badges and the task lists stay stale.
            only: [
                "pastDueTasks",
                "dueTodayTasks",
                "upcomingTasks",
                "completedTasks",
                "total_pastDueTasks",
                "total_dueTodayTasks",
                "total_upcomingTasks",
                "total_completedTasks",
                "filter",
            ],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
};

watch(search, debounce(applyFilters, 400));

/* ---- Closed work order cleanup tab ---- */

// `closedWorkOrders` is a deferred (Inertia::optional) prop — it is not present on
// the initial /tasks render and is fetched only when the cleanup tab is opened.
const activeTab = ref("board");
const closedLoading = ref(false);
const closedLoaded = ref(false);

// Local copy so we can keep the unfinished_count badge in sync after completing.
const closedList = ref([...props.closedWorkOrders]);

// Keep the local copy in sync whenever the deferred prop arrives/refreshes.
watch(
    () => props.closedWorkOrders,
    (list) => {
        closedList.value = [...(list ?? [])];
    }
);

const loadClosedWorkOrders = () => {
    if (closedLoaded.value || closedLoading.value) return;
    closedLoading.value = true;
    router.reload({
        only: ["closedWorkOrders"],
        onSuccess: () => {
            closedLoaded.value = true;
        },
        onFinish: () => {
            closedLoading.value = false;
        },
    });
};

watch(activeTab, (tab) => {
    if (tab === "cleanup") loadClosedWorkOrders();
});

const cleanupSearch = ref("");
const selectedWoId = ref("");
const cleanupTasks = ref([]);
const selectedTaskIds = ref([]);
const loadingTasks = ref(false);
const completing = ref(false);

const filteredClosedList = computed(() => {
    const term = cleanupSearch.value.trim().toLowerCase();
    if (!term) return closedList.value;
    return closedList.value.filter((wo) =>
        String(wo.work_order_no).toLowerCase().includes(term)
    );
});

// Totals for the "complete all closed" sweep, derived from the loaded list.
const totalUnfinishedAll = computed(() =>
    closedList.value.reduce((sum, wo) => sum + (wo.unfinished_count || 0), 0)
);
const affectedWoCount = computed(
    () => closedList.value.filter((wo) => (wo.unfinished_count || 0) > 0).length
);

const allSelected = computed(
    () =>
        cleanupTasks.value.length > 0 &&
        selectedTaskIds.value.length === cleanupTasks.value.length
);

const toggleSelectAll = (checked) => {
    selectedTaskIds.value = checked
        ? cleanupTasks.value.map((t) => t.id)
        : [];
};

const toggleTask = (taskId, checked) => {
    if (checked) {
        if (!selectedTaskIds.value.includes(taskId)) {
            selectedTaskIds.value.push(taskId);
        }
    } else {
        selectedTaskIds.value = selectedTaskIds.value.filter(
            (id) => id !== taskId
        );
    }
};

const loadIncompleteTasks = async (woId) => {
    selectedWoId.value = woId;
    selectedTaskIds.value = [];
    cleanupTasks.value = [];
    if (!woId) return;

    loadingTasks.value = true;
    try {
        const { data } = await window.axios.get(
            route("tasks.incomplete", woId)
        );
        cleanupTasks.value = data;
    } catch (e) {
        toast({
            title: "Could not load tasks",
            description:
                e?.response?.data?.message ?? "Please try again.",
            variant: "destructive",
        });
    } finally {
        loadingTasks.value = false;
    }
};

const showConfirmModal = ref(false);

const completeSelected = () => {
    if (selectedTaskIds.value.length === 0 || !selectedWoId.value) return;
    showConfirmModal.value = true;
};

const confirmComplete = async () => {
    if (selectedTaskIds.value.length === 0 || !selectedWoId.value) return;

    completing.value = true;
    try {
        const { data } = await window.axios.post(
            route("tasks.bulk_complete", selectedWoId.value),
            { task_ids: selectedTaskIds.value }
        );

        // Drop completed rows and refresh the badge count.
        const completedIds = new Set(selectedTaskIds.value);
        cleanupTasks.value = cleanupTasks.value.filter(
            (t) => !completedIds.has(t.id)
        );
        selectedTaskIds.value = [];

        const wo = closedList.value.find(
            (w) => String(w.id) === String(selectedWoId.value)
        );
        if (wo) {
            wo.unfinished_count = cleanupTasks.value.length;
        }

        toast({
            title: "Tasks completed",
            description: `${data.completed_count} task(s) marked complete.`,
        });
    } catch (e) {
        toast({
            title: "Could not complete tasks",
            description:
                e?.response?.data?.message ?? "Please try again.",
            variant: "destructive",
        });
    } finally {
        completing.value = false;
        showConfirmModal.value = false;
    }
};

/* ---- Complete ALL incomplete tasks across every closed work order ---- */
const showCompleteAllModal = ref(false);
const completingAll = ref(false);

const confirmCompleteAll = async () => {
    completingAll.value = true;
    try {
        const { data } = await window.axios.post(
            route("tasks.bulk_complete_all_closed")
        );

        // Everything closed is now done — zero the badges and clear any open checklist.
        closedList.value.forEach((wo) => {
            wo.unfinished_count = 0;
        });
        cleanupTasks.value = [];
        selectedTaskIds.value = [];

        toast({
            title: "All closed work orders cleaned up",
            description: `${data.completed_count} task(s) marked complete.`,
        });
    } catch (e) {
        toast({
            title: "Could not complete tasks",
            description: e?.response?.data?.message ?? "Please try again.",
            variant: "destructive",
        });
    } finally {
        completingAll.value = false;
        showCompleteAllModal.value = false;
    }
};
</script>

<template>
    <div>
    <Head :title="title" />
    <Tabs v-model="activeTab" default-value="board" class="w-full">
        <TabsList class="mb-3">
            <TabsTrigger value="board">Active board</TabsTrigger>
            <TabsTrigger value="cleanup">Closed cleanup</TabsTrigger>
        </TabsList>
        <TabsContent value="board">
    <div class="flex flex-col sm:flex-row gap-2 mb-3">
        <Input
            v-model="search"
            placeholder="Search work order #"
            class="w-full sm:w-[220px]"
        />
        <Select
            :modelValue="assigned"
            @update:modelValue="
                (v) => {
                    assigned = v === 'all' ? '' : v;
                    applyFilters();
                }
            "
        >
            <SelectTrigger class="w-full sm:w-[200px]">
                <SelectValue placeholder="Assigned to" />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectItem value="all">All assignees</SelectItem>
                    <SelectItem
                        v-for="u in assignableUsers"
                        :key="u.id"
                        :value="String(u.id)"
                        >{{ u.name }}</SelectItem
                    >
                </SelectGroup>
            </SelectContent>
        </Select>
        <Select
            :modelValue="status"
            @update:modelValue="
                (v) => {
                    status = v === 'all' ? '' : v;
                    applyFilters();
                }
            "
        >
            <SelectTrigger class="w-full sm:w-[160px]">
                <SelectValue placeholder="Status" />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem
                        v-for="s in statuses"
                        :key="s"
                        :value="s"
                        class="capitalize"
                        >{{ s }}</SelectItem
                    >
                </SelectGroup>
            </SelectContent>
        </Select>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="flex-1">
            <h2 class="text-xl font-bold mb-2 p-2 border">
                Past Due ({{ total_pastDueTasks }})
            </h2>
            <ScrollArea class="h-auto md:h-[80vh] mb-5">
                <!-- <TaskCard :tasks="pastDueTasks" /> -->
                <WhenVisible data="pastDueTasks">
                    <template #fallback>
                        <div>Loading...</div>
                    </template>

                    <TaskCard :tasks="pastDueTasks" :assignable-users="assignableUsers" />
                </WhenVisible>

                <ScrollBar orientation="vertical" />
            </ScrollArea>
        </div>
        <div class="flex-1">
            <h2 class="text-xl font-bold mb-2 p-2 border">
                Due Today ({{ total_dueTodayTasks }})
            </h2>
            <ScrollArea class="h-auto md:h-[80vh] mb-5">
                <!-- <TaskCard :tasks="dueTodayTasks" /> -->
                <WhenVisible data="dueTodayTasks">
                    <template #fallback>
                        <div>Loading...</div>
                    </template>

                    <TaskCard :tasks="dueTodayTasks" :assignable-users="assignableUsers" />
                </WhenVisible>
                <ScrollBar orientation="vertical" />
            </ScrollArea>
        </div>
        <div class="flex-1">
            <h2 class="text-xl font-bold mb-2 p-2 border">
                Pending ({{ total_upcomingTasks }})
            </h2>
            <ScrollArea class="h-auto md:h-[80vh] mb-5">
                <!-- <TaskCard :tasks="upcomingTasks" /> -->
                <WhenVisible data="upcomingTasks">
                    <template #fallback>
                        <div>Loading...</div>
                    </template>

                    <TaskCard :tasks="upcomingTasks" :assignable-users="assignableUsers" />
                </WhenVisible>
                <ScrollBar orientation="vertical" />
            </ScrollArea>
        </div>
    </div>
        </TabsContent>

        <TabsContent value="cleanup">
            <div class="max-w-3xl">
                <p class="text-sm text-muted-foreground mb-3">
                    Pick a closed work order to finish off any tasks that were
                    left incomplete when it was closed. Completing them here
                    won't reopen or otherwise change the work order.
                </p>

                <div
                    class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4 p-3 border rounded-md bg-muted/40"
                >
                    <p class="text-sm">
                        <span class="font-medium">{{ totalUnfinishedAll }}</span>
                        incomplete task(s) across
                        <span class="font-medium">{{ affectedWoCount }}</span>
                        closed work order(s).
                    </p>
                    <Button
                        size="sm"
                        variant="destructive"
                        :disabled="
                            closedLoading ||
                            completingAll ||
                            totalUnfinishedAll === 0
                        "
                        @click="showCompleteAllModal = true"
                    >
                        <Loader2
                            v-if="completingAll"
                            class="w-4 h-4 mr-2 animate-spin"
                        />
                        Complete all
                    </Button>
                </div>

                <div class="flex flex-col sm:flex-row gap-2 mb-4">
                    <Input
                        v-model="cleanupSearch"
                        placeholder="Search closed work order #"
                        class="w-full sm:w-[240px]"
                    />
                    <Select
                        :modelValue="selectedWoId"
                        @update:modelValue="(v) => loadIncompleteTasks(v)"
                    >
                        <SelectTrigger class="w-full sm:w-[320px]">
                            <SelectValue placeholder="Select a closed work order" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectItem
                                    v-for="wo in filteredClosedList"
                                    :key="wo.id"
                                    :value="String(wo.id)"
                                >
                                    WO-{{ wo.work_order_no }} ·
                                    {{ wo.unfinished_count }} unfinished
                                </SelectItem>
                                <div
                                    v-if="closedLoading"
                                    class="px-2 py-1.5 text-sm text-muted-foreground flex items-center gap-2"
                                >
                                    <Loader2 class="w-3 h-3 animate-spin" />
                                    Loading closed work orders…
                                </div>
                                <div
                                    v-else-if="filteredClosedList.length === 0"
                                    class="px-2 py-1.5 text-sm text-muted-foreground"
                                >
                                    No closed work orders found
                                </div>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Loading skeleton -->
                <div v-if="loadingTasks" class="space-y-2">
                    <div
                        v-for="n in 4"
                        :key="n"
                        class="h-10 rounded-md bg-muted animate-pulse"
                    />
                </div>

                <!-- Task checklist -->
                <div
                    v-else-if="selectedWoId && cleanupTasks.length > 0"
                    class="border rounded-md"
                >
                    <div
                        class="flex items-center gap-3 p-3 border-b bg-muted/50"
                    >
                        <Checkbox
                            :checked="allSelected"
                            @update:checked="toggleSelectAll"
                        />
                        <span class="text-sm font-medium">
                            Select all ({{ cleanupTasks.length }})
                        </span>
                        <Button
                            class="ml-auto"
                            size="sm"
                            :disabled="
                                selectedTaskIds.length === 0 || completing
                            "
                            @click="completeSelected"
                        >
                            <Loader2
                                v-if="completing"
                                class="w-4 h-4 mr-1 animate-spin"
                            />
                            Complete selected ({{ selectedTaskIds.length }})
                        </Button>
                    </div>
                    <ul>
                        <li
                            v-for="task in cleanupTasks"
                            :key="task.id"
                            class="flex items-center gap-3 p-3 border-b last:border-b-0"
                        >
                            <Checkbox
                                :checked="selectedTaskIds.includes(task.id)"
                                @update:checked="
                                    (c) => toggleTask(task.id, c)
                                "
                            />
                            <div class="min-w-0">
                                <p class="text-sm truncate">
                                    {{ task.task?.name ?? task.description }}
                                </p>
                                <p
                                    class="text-xs text-muted-foreground flex items-center gap-2"
                                >
                                    <span v-if="task.due_date">
                                        Due {{ task.due_date }}
                                    </span>
                                    <Badge variant="secondary" class="capitalize">
                                        {{ task.status }}
                                    </Badge>
                                </p>
                            </div>
                            <span
                                v-if="task.assigned_user"
                                class="ml-auto text-xs text-muted-foreground shrink-0"
                            >
                                {{ task.assigned_user.name }}
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Empty state -->
                <div
                    v-else-if="selectedWoId"
                    class="border rounded-md p-8 text-center text-sm text-muted-foreground"
                >
                    No unfinished tasks 🎉
                </div>
            </div>
        </TabsContent>
    </Tabs>

    <Dialog v-model:open="showConfirmModal">
        <DialogContent class="sm:max-w-[420px]">
            <DialogHeader>
                <DialogTitle>Complete selected tasks?</DialogTitle>
                <DialogDescription>
                    Mark {{ selectedTaskIds.length }} task(s) complete. This
                    won't reopen or otherwise change the work order.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    @click="showConfirmModal = false"
                    :disabled="completing"
                >
                    Cancel
                </Button>
                <Button @click="confirmComplete" :disabled="completing">
                    <Loader2
                        v-if="completing"
                        class="w-4 h-4 mr-2 animate-spin"
                    />
                    Complete tasks
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="showCompleteAllModal">
        <DialogContent class="sm:max-w-[440px]">
            <DialogHeader>
                <DialogTitle>Complete all closed work order tasks?</DialogTitle>
                <DialogDescription>
                    This will mark
                    <span class="font-medium">{{ totalUnfinishedAll }}</span>
                    incomplete task(s) across
                    <span class="font-medium">{{ affectedWoCount }}</span>
                    closed work order(s) as complete. It won't reopen or
                    otherwise change the work orders, and it can't be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    @click="showCompleteAllModal = false"
                    :disabled="completingAll"
                >
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    @click="confirmCompleteAll"
                    :disabled="completingAll"
                >
                    <Loader2
                        v-if="completingAll"
                        class="w-4 h-4 mr-2 animate-spin"
                    />
                    Complete all
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
    </div>
</template>
