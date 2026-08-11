<script setup>
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import { Button } from "@/Components/ui/button";
import { Separator } from "@/Components/ui/separator";
import { Switch } from "@/Components/ui/switch";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Bot, Loader2 } from "lucide-vue-next";

// App-wide kill-switch for Jobber's automated messages, shown in the header of
// the Jobber pages (admin + WOC). One master switch plus one per automation.
// Only automated sends consult this — manual staff messages are never gated.

const MASTER = "all";

const { toast } = useToast();

// Keys currently DISABLED (may include the "all" master sentinel).
const disabledKeys = ref([]);
// automation key => human label, served by the endpoint.
const automations = ref({});
const ready = ref(false);
const saving = ref(false);

const masterOn = computed(() => !disabledKeys.value.includes(MASTER));
const offCount = computed(() =>
    disabledKeys.value.includes(MASTER)
        ? Object.keys(automations.value).length || 1
        : disabledKeys.value.length,
);

const isOn = (key) =>
    masterOn.value && !disabledKeys.value.includes(key);

const load = async () => {
    try {
        const { data } = await axios.get(route("jobber.automation.show"));
        disabledKeys.value = data.disabled ?? [];
        automations.value = data.automations ?? {};
    } catch {
        // Fail open in the display: assume everything is on rather than imply
        // automations are muted. Flipping a switch still round-trips.
        disabledKeys.value = [];
    } finally {
        ready.value = true;
    }
};

onMounted(load);

const toggle = (key, next) => {
    const isMaster = key === MASTER;
    saving.value = true;
    axios
        .patch(route("jobber.automation.toggle"), {
            automation: key,
            disabled: !next,
        })
        .then(({ data }) => {
            disabledKeys.value = data.disabled ?? [];
            toast({
                title: next
                    ? isMaster
                        ? "Jobber automation resumed"
                        : "Automation resumed"
                    : isMaster
                      ? "All Jobber automation off"
                      : "Automation off",
                description: next
                    ? isMaster
                        ? "Jobber automated messages will send again."
                        : `"${automations.value[key] ?? key}" will send again.`
                    : isMaster
                      ? "No Jobber automated messages will go out. Manual messages still send."
                      : `"${automations.value[key] ?? key}" will not go out. Manual messages still send.`,
            });
        })
        .catch(() => {
            toast({
                variant: "destructive",
                title: "Couldn't update Jobber automation",
                description: "Please try again.",
            });
        })
        .finally(() => {
            saving.value = false;
        });
};
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button
                variant="icon"
                class="relative"
                title="Jobber automation"
                aria-label="Jobber automation settings"
            >
                <Bot class="h-4 w-4" :class="offCount ? 'text-amber-600 dark:text-amber-500' : ''" />
                <span
                    v-if="offCount"
                    class="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white"
                >
                    {{ offCount }}
                </span>
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-80 p-3">
            <div class="flex items-center gap-2 pb-1">
                <Bot class="h-4 w-4 text-muted-foreground" />
                <span class="text-sm font-semibold">Jobber automation</span>
                <Loader2
                    v-if="!ready || saving"
                    class="ml-auto h-3.5 w-3.5 animate-spin text-muted-foreground"
                />
            </div>
            <p class="pb-2 text-xs text-muted-foreground">
                Switched-off automations stop sending everywhere until turned
                back on. Manual messages are never affected.
            </p>

            <div
                class="flex items-center justify-between rounded-md border px-2 py-2"
                :class="masterOn ? 'bg-background' : 'border-amber-400/50 bg-amber-50 dark:bg-amber-950/30'"
            >
                <span class="text-sm font-medium">
                    All Jobber automation: {{ masterOn ? "On" : "Off" }}
                </span>
                <Switch
                    :checked="masterOn"
                    :disabled="!ready || saving"
                    @update:checked="(next) => toggle(MASTER, next)"
                />
            </div>

            <Separator class="my-2" />

            <div class="space-y-1">
                <div
                    v-for="(label, key) in automations"
                    :key="key"
                    class="flex items-center justify-between rounded-md px-2 py-1.5"
                    :class="!masterOn ? 'opacity-50' : ''"
                >
                    <span
                        class="pr-2 text-sm"
                        :class="isOn(key) ? '' : 'text-muted-foreground line-through'"
                    >
                        {{ label }}
                    </span>
                    <Switch
                        :checked="isOn(key)"
                        :disabled="!ready || saving || !masterOn"
                        @update:checked="(next) => toggle(key, next)"
                    />
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
