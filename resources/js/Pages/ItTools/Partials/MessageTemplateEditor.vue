<script setup>
import { computed, onMounted, ref } from "vue";
import axios from "axios";
import MessageTemplateCard from "./MessageTemplateCard.vue";
import { Button } from "@/Components/ui/button";
import { AlertCircle } from "lucide-vue-next";

// The Message Templates tab: every editable canned message grouped by who
// receives it. Loaded lazily so opening the log tab costs nothing extra.

const templates = ref([]);
const loading = ref(true);
const loadFailed = ref(false);

const load = () => {
    loading.value = true;
    loadFailed.value = false;
    axios
        .get(route("it-tools.automated-messages.templates"))
        .then(({ data }) => {
            templates.value = data.templates ?? [];
        })
        .catch(() => {
            loadFailed.value = true;
        })
        .finally(() => {
            loading.value = false;
        });
};

onMounted(load);

const sections = computed(() =>
    [
        { key: "tenant", title: "Tenant messages" },
        { key: "owner", title: "Owner messages" },
        { key: "vendor", title: "Vendor messages" },
    ]
        .map((section) => ({
            ...section,
            items: templates.value.filter(
                (template) => template.audience === section.key
            ),
        }))
        .filter((section) => section.items.length > 0)
);

const replaceTemplate = (updated) => {
    templates.value = templates.value.map((template) =>
        template.key === updated.key ? updated : template
    );
};
</script>

<template>
    <div class="space-y-6">
        <p class="text-sm text-muted-foreground">
            The wording each automation sends. Placeholders in
            <span class="font-mono">{curly braces}</span> are filled in
            per message — click one to insert it. Sign-offs and portal links
            are added automatically and cannot be edited away.
        </p>

        <div v-if="loading" class="space-y-4">
            <div
                v-for="n in 4"
                :key="n"
                class="h-40 animate-pulse rounded-lg border bg-muted/50"
            />
        </div>

        <div
            v-else-if="loadFailed"
            class="flex flex-col items-center gap-3 rounded-lg border py-12 text-center"
        >
            <AlertCircle class="h-8 w-8 text-muted-foreground" />
            <p class="text-sm font-medium">
                The message templates could not be loaded.
            </p>
            <Button variant="outline" size="sm" @click="load">Try again</Button>
        </div>

        <template v-else>
            <section
                v-for="section in sections"
                :key="section.key"
                class="space-y-3"
            >
                <h2 class="text-base font-semibold">{{ section.title }}</h2>
                <div class="space-y-4">
                    <MessageTemplateCard
                        v-for="template in section.items"
                        :key="template.key"
                        :template="template"
                        @updated="replaceTemplate"
                    />
                </div>
            </section>
        </template>
    </div>
</template>
