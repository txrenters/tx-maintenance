<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import TaskCard from "@/Components/TaskCard.vue";
import { usePoll } from "@inertiajs/vue3";
import { ref } from "vue";
defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    dueTodayTasks: Object,
    upcomingTasks: Object,
    pastDueTasks: Object,
    completedTasks: Object,
});

const url = route("tasks.index");
const search = ref("");

usePoll(10000);
</script>

<template>
    <Head :title="title" />
    <div>
        <SearchBar :url="url" v-model="search" class="w-full" />
    </div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="flex-1">
            <h2 class="text-xl font-bold mb-2 p-2 border">
                Past Due ({{ pastDueTasks.length }})
            </h2>
            <ScrollArea class="h-auto md:h-[85vh] mb-5">
                <TaskCard :tasks="pastDueTasks" />

                <ScrollBar orientation="vertical" />
            </ScrollArea>
        </div>
        <div class="flex-1">
            <h2 class="text-xl font-bold mb-2 p-2 border">
                Due Today ({{ dueTodayTasks.length }})
            </h2>
            <ScrollArea class="h-auto md:h-[85vh] mb-5">
                <TaskCard :tasks="dueTodayTasks" />
                <ScrollBar orientation="vertical" />
            </ScrollArea>
        </div>
        <div class="flex-1">
            <h2 class="text-xl font-bold mb-2 p-2 border">
                Pending ({{ upcomingTasks.length }})
            </h2>
            <ScrollArea class="h-auto md:h-[85vh] mb-5">
                <TaskCard :tasks="upcomingTasks" />
                <ScrollBar orientation="vertical" />
            </ScrollArea>
        </div>
        <div class="flex-1">
            <h2 class="text-xl font-bold mb-2 p-2 border">
                Completed ({{ completedTasks.length }})
            </h2>
            <ScrollArea class="h-auto md:h-[85vh] mb-5">
                <TaskCard :tasks="completedTasks" />
                <ScrollBar orientation="vertical" />
            </ScrollArea>
        </div>
    </div>
</template>
