<script setup>
import { computed, ref, watch } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import { DateTime } from "luxon";
import AppLayout from "@/Layouts/AppLayout.vue";
import RichTextEditor from "@/Components/RichTextEditor.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import { ChevronDown } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    senderEmail: String,
    owner: Object,
    emailHistory: { type: Array, default: () => [] },
    emailHistoryHasMore: Boolean,
    propertyWorkOrders: { type: Array, default: () => [] },
});

const { toast } = useToast();
const activeTab = ref("emails");
const detailsOpen = ref(false);
const selectedWorkOrderId = ref(null);
const fileInput = ref(null);
const composeOpen = ref(false);
const threadEmails = ref([...props.emailHistory]);
const hasOlderEmails = ref(props.emailHistoryHasMore);
const nextBeforeId = ref(props.emailHistoryHasMore ? Math.min(...props.emailHistory.map((email) => email.id)) : null);
const loadingEmails = ref(false);
const selectedEmail = ref(null);
const tabs = [
    { id: "emails", label: "Email History" },
    { id: "property-work-orders", label: "Property Work Orders" },
];

const emailForm = useForm({
    work_order_id: selectedWorkOrderId.value,
    to: props.owner.email ?? "",
    from_email: props.senderEmail,
    subject: "",
    body: "",
    attachments: [],
});

const filteredEmails = computed(() =>
    selectedWorkOrderId.value
        ? threadEmails.value.filter(
              (email) => email.work_order?.id === selectedWorkOrderId.value,
          )
        : threadEmails.value,
);

const fetchEmailPage = async ({ reset = false } = {}) => {
    loadingEmails.value = true;
    try {
        const response = await axios.get(route("owner.email.index", props.owner.id), {
            params: {
                ...(selectedWorkOrderId.value ? { work_order_id: selectedWorkOrderId.value } : {}),
                ...(!reset && nextBeforeId.value ? { before_id: nextBeforeId.value } : {}),
            },
        });
        const emails = response.data.owner_emails ?? [];
        threadEmails.value = reset ? emails : [...threadEmails.value, ...emails];
        hasOlderEmails.value = response.data.has_more ?? false;
        nextBeforeId.value = response.data.next_before_id ?? null;
    } finally {
        loadingEmails.value = false;
    }
};

const changeWorkOrderFilter = () => {
    emailForm.work_order_id = selectedWorkOrderId.value;
    nextBeforeId.value = null;
    fetchEmailPage({ reset: true });
};

watch(
    () => props.emailHistory,
    (emails) => {
        if (!selectedWorkOrderId.value) {
            threadEmails.value = [...emails];
            hasOlderEmails.value = props.emailHistoryHasMore;
            nextBeforeId.value = props.emailHistoryHasMore && emails.length
                ? Math.min(...emails.map((email) => email.id))
                : null;
        }
    },
);

const formatDate = (value) =>
    value ? DateTime.fromISO(value).toLocaleString(DateTime.DATETIME_MED) : "—";

const workOrderLabel = (workOrder) => {
    const detail = workOrder.category || "Work order";
    const shortDetail = detail.length > 28 ? `${detail.slice(0, 28)}…` : detail;

    return `#${workOrder.work_order_no} · ${shortDetail}`;
};

const onFiles = (event) => {
    emailForm.attachments = Array.from(event.target.files ?? []);
};

const sendEmail = () => {
    emailForm.work_order_id = selectedWorkOrderId.value;
    emailForm.post(route("owner.email.send", props.owner.id), {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            activeTab.value = "emails";
            emailForm.reset("subject", "body", "attachments");
            composeOpen.value = false;
            if (fileInput.value) {
                fileInput.value.value = "";
            }
            toast({
                title: "Email sent",
                description: "The owner email was added to the work-order thread.",
            });
        },
        onError: () =>
            toast({
                variant: "destructive",
                title: "Email not sent",
                description: "Check the email details and try again.",
            }),
    });
};
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="title" />

        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ owner.name }}</h1>
            <p class="text-sm text-muted-foreground">{{ owner.email || "No email address" }}</p>
        </div>

        <Card>
            <CardHeader class="p-0">
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 p-6 text-left"
                    :aria-expanded="detailsOpen"
                    @click="detailsOpen = !detailsOpen"
                >
                    <CardTitle>Owner details</CardTitle>
                    <ChevronDown class="size-5 shrink-0 transition-transform" :class="detailsOpen && 'rotate-180'" />
                </button>
            </CardHeader>
            <CardContent v-if="detailsOpen" class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="item in [
                    ['Email', owner.email], ['Mobile', owner.mobile], ['Phone', owner.phone],
                    ['Company', owner.company], ['Address', owner.address], ['Status', owner.status],
                    ['Name on check', owner.name_on_check], ['PropertyWare ID', owner.propertyware_id],
                ]" :key="item[0]" class="flex flex-col gap-1">
                    <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ item[0] }}</span>
                    <span class="text-sm">{{ item[1] || "—" }}</span>
                </div>
            </CardContent>
        </Card>

        <div class="flex gap-1 overflow-x-auto border-b">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                type="button"
                class="whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors"
                :class="activeTab === tab.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                @click="activeTab = tab.id"
            >
                {{ tab.label }}
            </button>
        </div>

        <Card v-if="activeTab === 'emails'">
            <CardHeader class="gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-1">
                    <CardTitle>Email History</CardTitle>
                    <CardDescription>Showing the newest 25 emails. Load older messages when needed.</CardDescription>
                </div>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <label class="w-full sm:w-64">
                        <span class="sr-only">Filter by property work order</span>
                        <select v-model="selectedWorkOrderId" class="w-full truncate rounded-md border border-input bg-background px-3 py-2 text-sm" @change="changeWorkOrderFilter">
                            <option :value="null">All owner emails</option>
                            <option v-for="workOrder in propertyWorkOrders" :key="workOrder.id" :value="workOrder.id">{{ workOrderLabel(workOrder) }}</option>
                        </select>
                    </label>
                    <Button type="button" :disabled="!owner.email" @click="composeOpen = true">Compose Email</Button>
                </div>
            </CardHeader>
            <CardContent class="flex min-h-[34rem] flex-col gap-4">
                <div class="flex max-h-[34rem] flex-1 flex-col gap-3 overflow-y-auto pr-1">
                    <Button
                        v-if="hasOlderEmails"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="self-center"
                        :disabled="loadingEmails"
                        @click="fetchEmailPage()"
                    >
                        {{ loadingEmails ? "Loading…" : "Load older emails" }}
                    </Button>
                    <article
                        v-for="email in filteredEmails"
                        :key="email.id"
                        class="rounded-lg border p-4"
                        :class="email.direction === 'inbound' ? 'mr-4 bg-white text-black shadow-md dark:bg-muted dark:text-foreground sm:mr-10' : 'ml-4 border-primary bg-primary text-primary-foreground shadow-md sm:ml-10'"
                    >
                        <div class="flex flex-col gap-1 sm:flex-row sm:justify-between">
                            <div>
                                <p class="font-medium">{{ email.subject }}</p>
                                <p class="text-xs" :class="email.direction === 'outbound' ? 'text-primary-foreground/80' : 'text-muted-foreground'">{{ email.from_email }} → {{ email.to_email }}</p>
                            </div>
                            <time class="text-xs" :class="email.direction === 'outbound' ? 'text-primary-foreground/80' : 'text-muted-foreground'">{{ formatDate(email.sent_at) }}</time>
                        </div>
                        <button type="button" class="mt-3 block w-full text-left text-sm hover:underline" @click="selectedEmail = email">
                            <span class="line-clamp-1">{{ email.body_text || "No message preview available." }}</span>
                        </button>
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
                    <p v-if="filteredEmails.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                        No owner emails recorded for this filter yet.
                    </p>
                </div>

            </CardContent>
        </Card>

        <Dialog :open="selectedEmail !== null" @update:open="!$event && (selectedEmail = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader><DialogTitle>{{ selectedEmail?.subject || "Email message" }}</DialogTitle><DialogDescription>{{ selectedEmail?.from_email }} → {{ selectedEmail?.to_email }} · {{ formatDate(selectedEmail?.sent_at) }}</DialogDescription></DialogHeader>
                <div v-if="selectedEmail?.body_html" class="prose prose-sm max-w-none" v-html="selectedEmail.body_html" />
                <p v-else class="whitespace-pre-wrap text-sm">{{ selectedEmail?.body_text }}</p>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="composeOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Compose owner email</DialogTitle>
                    <DialogDescription>
                        This email will be saved in the owner's email history.
                    </DialogDescription>
                </DialogHeader>
                <form class="flex flex-col gap-3" @submit.prevent="sendEmail">
                    <p v-if="!owner.email" class="text-sm text-destructive">This owner has no email address.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="font-medium text-muted-foreground">From</span>
                            <input
                                v-model="emailForm.from_email"
                                type="email"
                                required
                                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="font-medium text-muted-foreground">To</span>
                            <input
                                v-model="emailForm.to"
                                type="email"
                                required
                                placeholder="No owner email address"
                                class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                        </label>
                    </div>
                    <input
                        v-model="emailForm.subject"
                        type="text"
                        required
                        maxlength="255"
                        placeholder="Subject"
                                :disabled="!owner.email || emailForm.processing"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm disabled:opacity-50"
                    />
                    <RichTextEditor v-model="emailForm.body" />
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <input
                            ref="fileInput"
                            type="file"
                            multiple
                            :disabled="!owner.email || emailForm.processing"
                            class="text-xs file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-2 file:text-xs file:font-medium"
                            @change="onFiles"
                        />
                        <button
                            type="submit"
                            :disabled="!owner.email || emailForm.processing || !emailForm.subject.trim() || !emailForm.body.trim()"
                            class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ emailForm.processing ? "Sending..." : "Send Email" }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Card v-if="activeTab === 'property-work-orders'">
            <CardHeader>
                <CardTitle>Property work orders</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <Table>
                    <TableHeader><TableRow><TableHead>Work order</TableHead><TableHead>Description</TableHead><TableHead>Status</TableHead><TableHead>Created</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="workOrder in propertyWorkOrders" :key="workOrder.id">
                            <TableCell><Link :href="route('work_orders.details', workOrder.id)" class="font-medium text-primary hover:underline">#{{ workOrder.work_order_no }}</Link></TableCell>
                            <TableCell class="max-w-md truncate">{{ workOrder.description || "—" }}</TableCell>
                            <TableCell>{{ workOrder.status || "—" }}</TableCell>
                            <TableCell>{{ formatDate(workOrder.created_date) }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
