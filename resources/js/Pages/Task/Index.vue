<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import TaskCard from "@/Components/TaskCard.vue";
import { usePoll } from "@inertiajs/vue3";
import { ref } from "vue";
import { WhenVisible } from "@inertiajs/vue3";

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
});

const url = route("tasks.index");
const search = ref("");
</script>

<template>
    <Head :title="title" />
    <div>
        <SearchBar :url="url" v-model="search" class="w-full" />
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
