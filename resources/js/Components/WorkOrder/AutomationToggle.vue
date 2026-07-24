<script setup>
import { ref, onMounted, watch } from "vue";
import axios from "axios";
import { Switch } from "@/Components/ui/switch";
import { useToast } from "@/Components/ui/toast/use-toast";
import { BellOff, Bell, Loader2 } from "lucide-vue-next";

const props = defineProps({
    // The work order these automations belong to.
    workOrderId: { type: [Number, String], required: true },
    // Which channel this tab controls: 'tenant' | 'owner' | 'vendor'.
    channel: { type: String, required: true },
});

const { toast } = useToast();

// enabled = automations ON (nothing muted). Paused = the channel is in the list.
const enabled = ref(true);
const loading = ref(false);
const ready = ref(false);

const label = {
    tenant: "tenant",
    owner: "owner",
    vendor: "vendor",
}[props.channel] ?? props.channel;

const load = async () => {
    if (props.workOrderId == null || props.workOrderId === "") return;
    try {
        const { data } = await axios.get(
            route("work_order.automation.show", props.workOrderId),
        );
        enabled.value = !(data.paused_automations ?? []).includes(props.channel);
    } catch {
        // Fail open: assume automations are on rather than imply they're muted.
        enabled.value = true;
    } finally {
        ready.value = true;
    }
};

onMounted(load);
// The tab may be reused for a different work order without remounting.
watch(
    () => props.workOrderId,
    () => {
        ready.value = false;
        load();
    },
);

const onToggle = (next) => {
    const paused = !next;
    loading.value = true;
    axios
        .patch(route("work_order.automation.toggle", props.workOrderId), {
            channel: props.channel,
            paused,
        })
        .then(() => {
            enabled.value = next;
            toast({
                title: next
                    ? `Automated ${label} messages resumed`
                    : `Automated ${label} messages paused`,
                description: next
                    ? `Automations will send for this work order again.`
                    : `No automated ${label} messages will go out for this work order. Manual messages still send.`,
            });
        })
        .catch(() => {
            toast({
                variant: "destructive",
                title: "Couldn't update automation",
                description: "Please try again.",
            });
        })
        .finally(() => {
            loading.value = false;
        });
};
</script>

<template>
    <div
        class="flex items-center gap-2 rounded-md border px-2 py-1"
        :class="enabled ? 'bg-background' : 'border-amber-400/50 bg-amber-50 dark:bg-amber-950/30'"
        :title="
            enabled
                ? `Automated ${label} messages are on for this work order`
                : `Automated ${label} messages are paused for this work order`
        "
    >
        <Loader2 v-if="loading || !ready" class="h-3.5 w-3.5 animate-spin text-muted-foreground" />
        <BellOff v-else-if="!enabled" class="h-3.5 w-3.5 text-amber-600 dark:text-amber-500" />
        <Bell v-else class="h-3.5 w-3.5 text-muted-foreground" />
        <span class="text-xs font-medium whitespace-nowrap">
            Automation: {{ enabled ? "On" : "Off" }}
        </span>
        <Switch
            :checked="enabled"
            :disabled="loading || !ready"
            @update:checked="onToggle"
        />
    </div>
</template>
