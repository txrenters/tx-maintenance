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
        { key: "tenant", title: "Tenant messages", accent: "bg-blue-500" },
        { key: "owner", title: "Owner messages", accent: "bg-emerald-500" },
        { key: "vendor", title: "Vendor messages", accent: "bg-amber-500" },
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
            The wording each automation sends. The parts in
            <span
                class="rounded bg-indigo-500/10 px-1 font-mono text-indigo-600 dark:text-indigo-400"
                >{curly braces}</span
            >
            are placeholders the system fills in automatically on every send —
            for example <span class="font-mono">{greeting}</span> becomes "Hi
            Jane," — so keep them in the text (click a chip to insert one) and
            never type a real name or address in their place. Use Preview to
            see the message with the details filled in. Sign-offs and portal
            links are added automatically and cannot be edited away.
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
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <span
                        :class="['h-2.5 w-2.5 rounded-full', section.accent]"
                    />
                    {{ section.title }}
                </h2>
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
