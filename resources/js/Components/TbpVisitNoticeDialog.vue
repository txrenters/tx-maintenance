<script setup>
import { ref, computed, watch } from "vue";
import axios from "axios";
import { router } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from "@/Components/ui/dialog";
import { Button } from "@/Components/ui/button";
import { Checkbox } from "@/Components/ui/checkbox";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Badge } from "@/Components/ui/badge";
import { ScrollArea } from "@/Components/ui/scroll-area";
import { Loader2, Send, Plus, AlertTriangle } from "lucide-vue-next";

// The "Send notification" dialog for a TBP visit: the canned visit notice
// with this visit's date filled in, and the phone numbers we could find for
// the property. Staff tick who gets it (or type a number) and press Send; the
// text goes through the same endpoint as the Messages tab composer, so it
// shows up in the visit's message history.
const props = defineProps({
    visit: { type: Object, default: null },
    senderNumber: { type: String, default: "" },
});

const emit = defineEmits(["update:visit", "sent"]);

const { toast } = useToast();

const open = computed(() => props.visit !== null);
const loading = ref(false);
const loadError = ref("");
const notice = ref(null);
const recipients = ref([]);
const customNumber = ref("");
const sending = ref(false);

const close = () => {
    if (sending.value) return;
    emit("update:visit", null);
};

const load = async (visitId) => {
    loading.value = true;
    loadError.value = "";
    notice.value = null;
    recipients.value = [];
    customNumber.value = "";

    try {
        const { data } = await axios.get(route("visits.tbp_notice", visitId));
        notice.value = data;
        recipients.value = (data.recipients || []).map((recipient) => ({ ...recipient }));
    } catch (error) {
        loadError.value =
            error.response?.data?.message || "Could not load the notice. Please try again.";
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.visit?.id,
    (visitId) => {
        if (visitId) load(visitId);
    },
    { immediate: true },
);

const selected = computed(() => recipients.value.filter((r) => r.selected && r.phone));
const tooLong = computed(() => !!notice.value && notice.value.length > notice.value.max_length);
const canSend = computed(
    () =>
        !!notice.value &&
        !tooLong.value &&
        selected.value.length > 0 &&
        !sending.value &&
        !!props.senderNumber,
);

const remindersSummary = computed(() => {
    const sent = notice.value?.reminders || {};
    const labels = { "14_day": "14-day", "7_day": "7-day", "3_day": "3-day", "1_day": "day-before" };
    const done = Object.keys(labels).filter((key) => sent[key]).map((key) => labels[key]);
    return done.length ? done.join(", ") : "none";
});

const digitsOf = (value) => String(value || "").replace(/\D/g, "");

const addCustomNumber = () => {
    const digits = digitsOf(customNumber.value);

    if (digits.length < 10) {
        toast({
            variant: "destructive",
            title: "Invalid number",
            description: "Enter a phone number with at least 10 digits.",
        });
        return;
    }

    const existing = recipients.value.find((r) => digitsOf(r.phone).endsWith(digits.slice(-10)));

    if (existing) {
        existing.selected = true;
    } else {
        recipients.value.push({
            name: customNumber.value.trim(),
            phone: customNumber.value.trim(),
            source: "custom",
            detail: "Entered by hand",
            selected: true,
        });
    }

    customNumber.value = "";
};

const send = () => {
    if (!canSend.value) return;

    sending.value = true;
    const count = selected.value.length;

    router.post(
        route("jobber-text-messages.store"),
        {
            messages: notice.value.message,
            sender_number: props.senderNumber,
            receiver_numbers: selected.value.map((r) => r.phone),
            jobber_id: props.visit.job.id,
            jobber_visit_id: props.visit.id,
        },
        {
            preserveState: true,
            preserveScroll: true,
            // Same as the Messages tab composer: nothing on the page itself
            // needs re-fetching after the redirect back.
            only: ["jobsByStatus"],
            onSuccess: () => {
                toast({
                    title: "Notice sent",
                    description: `Sent to ${count} recipient${count === 1 ? "" : "s"}. Delivery may take a moment.`,
                });
                emit("sent");
                emit("update:visit", null);
            },
            onError: (errors) => {
                toast({
                    variant: "destructive",
                    title: "Not sent",
                    description: errors?.error || "Failed to send the notice. Please try again.",
                });
            },
            onFinish: () => {
                sending.value = false;
            },
        },
    );
};
</script>

<template>
    <Dialog :open="open" @update:open="!$event && close()">
        <DialogContent class="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
            <div class="px-6 pb-3 pr-12 pt-5">
                <DialogHeader class="space-y-1 text-left">
                    <DialogTitle>Send notification</DialogTitle>
                    <DialogDescription>
                        The Tenant Benefit Package visit notice for
                        <span class="font-medium text-foreground">{{ visit?.title }}</span>,
                        texted now to the people ticked below. For visits the automated
                        reminders did not reach.
                    </DialogDescription>
                </DialogHeader>
            </div>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 pb-4">
                <div v-if="loading" class="flex justify-center py-10">
                    <Loader2 class="h-8 w-8 animate-spin text-primary" />
                </div>

                <div
                    v-else-if="loadError"
                    class="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
                >
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ loadError }}</span>
                </div>

                <template v-else-if="notice">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <Badge variant="secondary">Visit date: {{ notice.scheduled_date }}</Badge>
                        <Badge v-if="!notice.is_tbp" variant="destructive">Not a TBP visit</Badge>
                        <span class="text-muted-foreground">
                            Automated reminders already sent: {{ remindersSummary }}
                        </span>
                    </div>

                    <div class="space-y-2">
                        <Label class="text-xs uppercase text-muted-foreground">To</Label>

                        <p v-if="recipients.length === 0" class="text-sm text-muted-foreground">
                            No phone number was found for this property in PropertyWare or Jobber.
                            Enter one below.
                        </p>

                        <div
                            v-for="(recipient, index) in recipients"
                            :key="`${recipient.source}-${recipient.phone || recipient.name}-${index}`"
                            class="flex items-start gap-3 rounded-md border p-2"
                            :class="recipient.phone ? '' : 'opacity-60'"
                        >
                            <Checkbox
                                :id="`tbp-recipient-${index}`"
                                :checked="recipient.selected"
                                :disabled="!recipient.phone || sending"
                                class="mt-0.5"
                                @update:checked="recipient.selected = $event"
                            />
                            <label :for="`tbp-recipient-${index}`" class="min-w-0 flex-1 cursor-pointer text-sm">
                                <span class="font-medium">{{ recipient.name }}</span>
                                <span v-if="recipient.phone" class="ml-2 text-muted-foreground">{{ recipient.phone }}</span>
                                <span class="block text-xs text-muted-foreground">{{ recipient.detail }}</span>
                            </label>
                        </div>

                        <div class="flex items-center gap-2">
                            <Input
                                v-model="customNumber"
                                placeholder="Add another number..."
                                class="max-w-xs"
                                :disabled="sending"
                                @keydown.enter.prevent="addCustomNumber"
                            />
                            <Button variant="outline" size="sm" :disabled="sending" @click="addCustomNumber">
                                <Plus class="h-4 w-4" /> Add
                            </Button>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label class="text-xs uppercase text-muted-foreground">Message</Label>
                        <ScrollArea class="h-64 rounded-md border bg-muted/30 p-3">
                            <p class="whitespace-pre-wrap text-sm leading-relaxed">{{ notice.message }}</p>
                        </ScrollArea>
                        <p class="text-xs text-muted-foreground">
                            {{ notice.length }} / {{ notice.max_length }} characters. The wording lives under
                            IT Tools &gt; Automated Messages &gt; Message Templates ("Send notification button").
                        </p>
                        <p v-if="tooLong" class="text-xs font-medium text-destructive">
                            The template is longer than one text message allows. Shorten it in Message
                            Templates before sending.
                        </p>
                        <p v-if="!senderNumber" class="text-xs font-medium text-destructive">
                            No sending phone number is configured for your account.
                        </p>
                    </div>
                </template>
            </div>

            <DialogFooter class="flex justify-end gap-2 border-t p-4">
                <Button variant="outline" :disabled="sending" @click="close">Cancel</Button>
                <Button :disabled="!canSend" @click="send">
                    <Loader2 v-if="sending" class="h-4 w-4 animate-spin" />
                    <Send v-else class="h-4 w-4" />
                    Send to {{ selected.length }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
