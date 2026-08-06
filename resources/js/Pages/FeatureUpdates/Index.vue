<script setup>
import { computed, ref } from "vue";
import { Head } from "@inertiajs/vue3";
import AppLayout from "@/Layouts/AppLayout.vue";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Card, CardContent } from "@/Components/ui/card";
import { Input } from "@/Components/ui/input";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { RefreshCcw, Sparkles } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    updates: Array,
    areas: Array,
});

const search = ref("");
const area = ref("all");

const filteredUpdates = computed(() => {
    const term = search.value.trim().toLowerCase();

    return (props.updates || []).filter((update) => {
        if (area.value !== "all" && update.area !== area.value) return false;
        if (term === "") return true;

        return `${update.title} ${update.description} ${update.area}`
            .toLowerCase()
            .includes(term);
    });
});

// Newest first from the server; keep that order and bucket per release date.
const groupedByDate = computed(() => {
    const groups = [];

    for (const update of filteredUpdates.value) {
        const last = groups[groups.length - 1];

        if (last && last.date === update.date) {
            last.updates.push(update);
        } else {
            groups.push({ date: update.date, updates: [update] });
        }
    }

    return groups;
});

const formatDate = (dateString) => {
    // Anchor to midnight local time so the label never shifts a day.
    const date = new Date(`${dateString}T00:00:00`);

    return date.toLocaleDateString("en-US", {
        year: "numeric",
        month: "long",
        day: "numeric",
    });
};

const areaVariant = (name) => {
    switch (name) {
        case "Work Orders":
            return "default";
        case "Messaging":
            return "secondary";
        default:
            return "outline";
    }
};

const clearFilters = () => {
    search.value = "";
    area.value = "all";
};
</script>

<template>
    <Head :title="title || 'What\'s New'" />

    <div class="mx-auto w-full max-w-4xl p-4 md:p-6 space-y-6">
        <div class="flex flex-col gap-1">
            <h1 class="flex items-center gap-2 text-2xl font-semibold">
                <Sparkles class="h-6 w-6" />
                What's New
            </h1>
            <p class="text-sm text-muted-foreground">
                Every update shipped to the maintenance system, newest first.
            </p>
        </div>

        <Card>
            <CardContent class="p-4">
                <div class="grid items-end gap-3 md:grid-cols-[1fr_220px_auto]">
                    <div class="space-y-1">
                        <label class="text-xs font-medium">Search</label>
                        <Input
                            v-model="search"
                            type="search"
                            placeholder="Search updates…"
                        />
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium">Area</label>
                        <Select v-model="area">
                            <SelectTrigger>
                                <SelectValue placeholder="All areas" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All areas</SelectItem>
                                <SelectItem
                                    v-for="name in areas"
                                    :key="name"
                                    :value="name"
                                >
                                    {{ name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <Button
                        variant="outline"
                        class="gap-2"
                        @click="clearFilters"
                    >
                        <RefreshCcw class="h-4 w-4" />
                        Reset
                    </Button>
                </div>

                <p class="mt-3 text-xs text-muted-foreground">
                    {{ filteredUpdates.length }} update(s)
                </p>
            </CardContent>
        </Card>

        <div
            v-if="groupedByDate.length === 0"
            class="flex flex-col items-center justify-center py-16 text-center"
        >
            <Sparkles class="mb-3 h-10 w-10 text-muted-foreground" />
            <p class="text-sm font-medium">No updates match</p>
            <p class="text-xs text-muted-foreground">
                Try a different search or reset the filters.
            </p>
        </div>

        <div v-else class="space-y-8">
            <section
                v-for="group in groupedByDate"
                :key="group.date"
                class="relative border-l pl-6"
            >
                <span
                    class="absolute -left-[7px] top-1 h-3.5 w-3.5 rounded-full border-2 border-background bg-primary"
                />
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ formatDate(group.date) }}
                </h2>

                <div class="mt-3 space-y-3">
                    <Card v-for="update in group.updates" :key="update.title">
                        <CardContent class="p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-semibold">
                                    {{ update.title }}
                                </h3>
                                <Badge :variant="areaVariant(update.area)">
                                    {{ update.area }}
                                </Badge>
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ update.description }}
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </div>
    </div>
</template>
