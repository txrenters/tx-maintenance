<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import TaskCard from "@/Components/TaskCard.vue";
import { usePoll, router } from "@inertiajs/vue3";
import { ref, watch } from "vue";
import { WhenVisible } from "@inertiajs/vue3";
import debounce from "lodash/debounce";

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
    statuses: { type: Array, default: () => [] },
    filter: { type: Object, default: () => ({}) },
});

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
</script>

<template>
    <Head :title="title" />
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

                    <TaskCard :tasks="pastDueTasks" />
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

                    <TaskCard :tasks="dueTodayTasks" />
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

                    <TaskCard :tasks="upcomingTasks" />
                </WhenVisible>
                <ScrollBar orientation="vertical" />
            </ScrollArea>
        </div>
    </div>
</template>
