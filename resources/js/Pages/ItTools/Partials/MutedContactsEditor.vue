<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import axios from "axios";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { useToast } from "@/Components/ui/toast/use-toast";
import { AlertCircle, BellOff, BellRing, Search } from "lucide-vue-next";

// The Do Not Message tab: owners and tenants who never receive automated
// texts or emails. Staff search for a person and mute or unmute them; manual
// sends from a conversation tab still reach them.

const { toast } = useToast();

const muted = ref([]);
const loading = ref(true);
const loadFailed = ref(false);

const query = ref("");
const results = ref([]);
const searching = ref(false);
const searched = ref(false);
const savingKey = ref(null);

const keyOf = (contact) => `${contact.type}:${contact.id}`;

const load = () => {
    loading.value = true;
    loadFailed.value = false;
    axios
        .get(route("it-tools.automated-messages.muted"))
        .then(({ data }) => {
            muted.value = data.contacts ?? [];
        })
        .catch(() => {
            loadFailed.value = true;
        })
        .finally(() => {
            loading.value = false;
        });
};

onMounted(load);

let searchTimer = null;
let searchRequest = 0;

const runSearch = (term) => {
    const requestId = ++searchRequest;
    searching.value = true;
    axios
        .get(route("it-tools.automated-messages.muted.search"), {
            params: { q: term },
        })
        .then(({ data }) => {
            if (requestId === searchRequest) {
                results.value = data.contacts ?? [];
                searched.value = true;
            }
        })
        .catch(() => {
            if (requestId === searchRequest) {
                results.value = [];
                searched.value = true;
            }
        })
        .finally(() => {
            if (requestId === searchRequest) {
                searching.value = false;
            }
        });
};

watch(query, (value) => {
    clearTimeout(searchTimer);
    const term = value.trim();
    if (term.length < 2) {
        searchRequest++;
        results.value = [];
        searched.value = false;
        searching.value = false;
        return;
    }
    searchTimer = setTimeout(() => runSearch(term), 300);
});

onBeforeUnmount(() => clearTimeout(searchTimer));

const replaceIn = (list, updated) =>
    list.map((contact) =>
        keyOf(contact) === keyOf(updated) ? updated : contact
    );

const setMuted = (contact, nextMuted) => {
    savingKey.value = keyOf(contact);
    axios
        .put(
            route("it-tools.automated-messages.muted.update", {
                type: contact.type,
                id: contact.id,
            }),
            { muted: nextMuted }
        )
        .then(({ data }) => {
            const updated = data.contact;
            results.value = replaceIn(results.value, updated);
            muted.value = nextMuted
                ? [updated, ...muted.value.filter((c) => keyOf(c) !== keyOf(updated))]
                : muted.value.filter((c) => keyOf(c) !== keyOf(updated));
            toast({
                title: nextMuted ? "Automations muted" : "Automations resumed",
                description: nextMuted
                    ? `${updated.name} will no longer get automated messages.`
                    : `${updated.name} will get automated messages again.`,
            });
        })
        .catch(() => {
            toast({
                title: "Could not save",
                description: "Please try again.",
                variant: "destructive",
            });
        })
        .finally(() => {
            savingKey.value = null;
        });
};

const formatDate = (iso) =>
    iso ? new Date(iso).toLocaleDateString() : "";
</script>

<template>
    <div class="space-y-6">
        <p class="text-sm text-muted-foreground">
            People on this list never receive automated maintenance texts or
            emails, on any work order. Use it for an owner whose property
            someone else handles, or anyone who asked not to be contacted
            automatically. Coordinators can still message them by hand from
            the conversation tabs.
        </p>

        <section class="space-y-3">
            <h2 class="text-base font-semibold">Add someone</h2>
            <div class="relative max-w-md">
                <Search
                    class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="query"
                    type="search"
                    class="pl-9"
                    placeholder="Search owners and tenants by name, email or phone"
                />
            </div>

            <div v-if="searching" class="space-y-2">
                <div
                    v-for="n in 3"
                    :key="n"
                    class="h-14 animate-pulse rounded-lg border bg-muted/50"
                />
            </div>
            <p
                v-else-if="searched && results.length === 0"
                class="text-sm text-muted-foreground"
            >
                No owners or tenants match "{{ query.trim() }}".
            </p>
            <ul v-else-if="results.length" class="divide-y rounded-lg border">
                <li
                    v-for="contact in results"
                    :key="keyOf(contact)"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ contact.name }}</span>
                            <Badge variant="outline" class="capitalize">{{
                                contact.type
                            }}</Badge>
                            <Badge v-if="contact.muted" variant="secondary"
                                >Muted</Badge
                            >
                        </div>
                        <p class="truncate text-xs text-muted-foreground">
                            {{
                                [contact.detail, ...contact.phones, contact.email]
                                    .filter(Boolean)
                                    .join(" · ")
                            }}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        :variant="contact.muted ? 'outline' : 'default'"
                        :disabled="savingKey === keyOf(contact)"
                        class="gap-1.5"
                        @click="setMuted(contact, !contact.muted)"
                    >
                        <component
                            :is="contact.muted ? BellRing : BellOff"
                            class="h-4 w-4"
                        />
                        {{ contact.muted ? "Unmute" : "Mute automations" }}
                    </Button>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-semibold">
                Muted ({{ muted.length }})
            </h2>

            <div v-if="loading" class="space-y-2">
                <div
                    v-for="n in 2"
                    :key="n"
                    class="h-14 animate-pulse rounded-lg border bg-muted/50"
                />
            </div>

            <div
                v-else-if="loadFailed"
                class="flex flex-col items-center gap-3 rounded-lg border py-12 text-center"
            >
                <AlertCircle class="h-8 w-8 text-muted-foreground" />
                <p class="text-sm font-medium">
                    The muted list could not be loaded.
                </p>
                <Button variant="outline" size="sm" @click="load"
                    >Try again</Button
                >
            </div>

            <p
                v-else-if="muted.length === 0"
                class="rounded-lg border py-8 text-center text-sm text-muted-foreground"
            >
                Nobody is muted. Everyone receives automated messages.
            </p>

            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="contact in muted"
                    :key="keyOf(contact)"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ contact.name }}</span>
                            <Badge variant="outline" class="capitalize">{{
                                contact.type
                            }}</Badge>
                        </div>
                        <p class="truncate text-xs text-muted-foreground">
                            {{
                                [contact.detail, ...contact.phones, contact.email]
                                    .filter(Boolean)
                                    .join(" · ")
                            }}
                            <span v-if="contact.muted_at">
                                · muted {{ formatDate(contact.muted_at) }}</span
                            >
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="savingKey === keyOf(contact)"
                        class="gap-1.5"
                        @click="setMuted(contact, false)"
                    >
                        <BellRing class="h-4 w-4" />
                        Unmute
                    </Button>
                </li>
            </ul>
        </section>
    </div>
</template>
