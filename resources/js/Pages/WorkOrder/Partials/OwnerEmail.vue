<script setup>
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import RichTextEditor from "@/Components/RichTextEditor.vue";
import { useToast } from "@/Components/ui/toast/use-toast";

const props = defineProps({
    workOrder: { type: Object, required: true },
    ownerEmails: { type: Array, default: () => [] },
    owners: { type: Array, default: () => [] },
    ownerId: { type: Number, default: null },
    senderEmail: { type: String, default: "" },
});

const emit = defineEmits(["select-owner", "update-owner-email"]);
const { toast } = useToast();

const subject = ref("");
const body = ref("");
const files = ref([]);
const fileInput = ref(null);
const sending = ref(false);
const composeOpen = ref(false);
const fromEmail = ref(props.senderEmail);
const toEmail = ref("");
const selectedOwner = computed(() =>
    props.owners.find((owner) => owner.id === props.ownerId),
);

watch(
    () => props.senderEmail,
    (email) => {
        fromEmail.value = email;
    },
    { immediate: true },
);

watch(
    selectedOwner,
    (owner) => {
        toEmail.value = owner?.email ?? "";
    },
    { immediate: true },
);

const formatDate = (value) =>
    value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: "medium",
              timeStyle: "short",
          }).format(new Date(value))
        : "";

const onFiles = (event) => {
    files.value = Array.from(event.target.files ?? []);
};

const send = () => {
    if (!props.ownerId || !subject.value.trim() || !body.value.trim()) {
        return;
    }

    sending.value = true;
    const formData = new FormData();
    formData.append("owner_id", props.ownerId);
    formData.append("from_email", fromEmail.value);
    formData.append("to", toEmail.value);
    formData.append("subject", subject.value.trim());
    formData.append("body", body.value);
    files.value.forEach((file) => formData.append("attachments[]", file));

    router.post(
        route("work_order.owner_email.send", props.workOrder.id),
        formData,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                subject.value = "";
                body.value = "";
                files.value = [];
                composeOpen.value = false;
                if (fileInput.value) {
                    fileInput.value.value = "";
                }
                toast({
                    title: "Email sent",
                    description: "The owner email was added to the work-order thread.",
                });
                emit("update-owner-email");
            },
            onError: () =>
                toast({
                    variant: "destructive",
                    title: "Email not sent",
                    description: "Check the email details and try again.",
                }),
            onFinish: () => {
                sending.value = false;
            },
        },
    );
};
</script>

<template>
    <div class="flex min-h-[32rem] flex-col gap-4">
        <label class="flex w-full flex-col gap-1 text-sm sm:w-80">
            <span class="font-medium">Property owner</span>
            <select
                :value="ownerId"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                @change="emit('select-owner', Number($event.target.value) || null)"
            >
                <option value="">Select an owner</option>
                <option v-for="owner in owners" :key="owner.id" :value="owner.id">
                    {{ owner.name }}{{ owner.email ? ` — ${owner.email}` : " — No email" }}
                </option>
            </select>
        </label>

        <div class="flex-1 space-y-3 overflow-y-auto p-2">
            <article
                v-for="email in ownerEmails"
                :key="email.id"
                class="rounded-lg border p-3"
                :class="
                    email.direction === 'outbound'
                        ? 'ml-4 border-primary bg-primary text-primary-foreground shadow-md sm:ml-8'
                        : 'mr-4 bg-white text-black shadow-md dark:bg-muted dark:text-foreground sm:mr-8'
                "
            >
                <div class="flex flex-col gap-1 text-xs sm:flex-row sm:justify-between" :class="email.direction === 'outbound' ? 'text-primary-foreground/80' : 'text-muted-foreground'">
                    <span class="font-semibold">
                        {{ email.direction === "outbound" ? "Sent to" : "Received from" }}
                        {{ email.direction === "outbound" ? email.to_email : email.from_email }}
                    </span>
                    <time>{{ formatDate(email.emailed_at || email.sent_at) }}</time>
                </div>
                <h3 class="mt-2 text-sm font-medium">{{ email.subject }}</h3>
                <div
                    v-if="email.body_html"
                    class="prose prose-sm mt-2 max-w-none"
                    v-html="email.body_html"
                />
                <p v-else class="mt-2 whitespace-pre-wrap text-sm">{{ email.body_text }}</p>
                <div v-if="email.attachments?.length" class="mt-3 flex flex-wrap gap-2">
                    <a
                        v-for="attachment in email.attachments"
                        :key="attachment.id"
                        :href="route('owner.email.attachment', attachment.id)"
                        class="rounded border border-input bg-background px-2 py-1 text-xs text-primary hover:underline"
                    >
                        {{ attachment.filename }}
                    </a>
                </div>
            </article>
            <p v-if="!ownerId" class="py-8 text-center text-sm text-muted-foreground">
                Select an actual property owner to view their email thread.
            </p>
            <p
                v-else-if="ownerEmails.length === 0"
                class="py-8 text-center text-sm text-muted-foreground"
            >
                No owner emails recorded for this work order yet.
            </p>
        </div>

        <div class="flex justify-end border-t border-border p-2 pt-4">
            <Button
                type="button"
                :disabled="!ownerId || !selectedOwner?.email"
                @click="composeOpen = true"
            >
                Compose Email
            </Button>
        </div>

        <Dialog v-model:open="composeOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Compose owner email</DialogTitle>
                    <DialogDescription>
                        Send an email for work order #{{ workOrder.work_order_no }}.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="send">
            <p v-if="!ownerId" class="text-sm text-destructive">
                Select a property owner before sending an email.
            </p>
            <p v-else-if="!selectedOwner?.email" class="text-sm text-destructive">
                This property owner has no email address.
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex flex-col gap-1 text-sm">
                    <span class="font-medium text-muted-foreground">From</span>
                    <input
                        v-model="fromEmail"
                        type="email"
                        required
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </label>
                <label class="flex flex-col gap-1 text-sm">
                    <span class="font-medium text-muted-foreground">To</span>
                    <input
                        v-model="toEmail"
                        type="email"
                        required
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                    />
                </label>
            </div>
            <input
                v-model="subject"
                type="text"
                required
                maxlength="255"
                placeholder="Subject"
                :disabled="!ownerId || !selectedOwner?.email || sending"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm disabled:opacity-50"
            />
            <RichTextEditor v-model="body" />
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    :disabled="!ownerId || !selectedOwner?.email || sending"
                    class="text-xs file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-2 file:text-xs file:font-medium"
                    @change="onFiles"
                />
                <button
                    type="submit"
                    :disabled="!ownerId || !selectedOwner?.email || sending || !subject.trim() || !body.trim()"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ sending ? "Sending..." : "Send Email" }}
                </button>
            </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
