<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import RichTextEditor from "@/Components/RichTextEditor.vue";
import EmailViewerDialog from "@/Components/EmailViewerDialog.vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Link, useForm } from "@inertiajs/vue3";
import { DateTime } from "luxon";
import { computed, ref } from "vue";
import { ChevronDown } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    tenant: Object,
    emailHistory: Array,
    workOrders: Array,
    jobberJobs: Array,
    senderEmail: String,
});

const { toast } = useToast();

const activeTab = ref("emails");
const detailsOpen = ref(false);
const tabs = [
    { id: "emails", label: "Email History" },
    { id: "work-orders", label: "Work Orders" },
    { id: "jobber-jobs", label: "Jobber Jobs" },
];

const formatDate = (value) =>
    value ? DateTime.fromISO(value).toLocaleString(DateTime.DATETIME_MED) : "—";

const selectedJobberJobId = ref(null);
const fileInput = ref(null);
const composeOpen = ref(false);
const selectedEmail = ref(null);
const emailForm = useForm({
    jobber_job_id: selectedJobberJobId.value,
    from_email: props.senderEmail,
    to: props.tenant.email ?? "",
    subject: "",
    body: "",
    attachments: [],
});

const selectedJobberJob = computed(() =>
    props.jobberJobs.find((job) => job.id === selectedJobberJobId.value),
);

const jobEmailHistory = computed(() => {
    if (!selectedJobberJobId.value) {
        return props.emailHistory;
    }

    return props.emailHistory.filter(
        (email) =>
            email.jobber_job?.id === selectedJobberJobId.value ||
            email.jobber_job_id === selectedJobberJobId.value,
    );
});

const selectJobberJob = () => {
    emailForm.jobber_job_id = selectedJobberJobId.value;
    emailForm.clearErrors();
};

const selectFiles = (event) => {
    emailForm.attachments = Array.from(event.target.files ?? []);
};

const sendTenantEmail = () => {
    emailForm.jobber_job_id = selectedJobberJobId.value;
    emailForm.post(route("tenant.email.send", props.tenant.id), {
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
                description: "The tenant email was added to this Jobber job's thread.",
            });
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Email not sent",
                description: "Check the email details and try again.",
            });
        },
    });
};
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="title" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ tenant.name }}</h1>
                <p class="text-sm text-muted-foreground">{{ tenant.email || "No email address" }}</p>
            </div>
        </div>

        <Card>
            <CardHeader class="p-0">
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 p-6 text-left"
                    :aria-expanded="detailsOpen"
                    @click="detailsOpen = !detailsOpen"
                >
                    <CardTitle>Tenant details</CardTitle>
                    <ChevronDown class="size-5 shrink-0 transition-transform" :class="detailsOpen && 'rotate-180'" />
                </button>
            </CardHeader>
            <CardContent v-if="detailsOpen" class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="item in [
                    ['Email', tenant.email], ['Mobile phone', tenant.mobile_phone], ['Home phone', tenant.home_phone],
                    ['Work phone', tenant.work_phone], ['Address', tenant.address], ['Company', tenant.company],
                    ['Job title', tenant.job_title], ['PropertyWare ID', tenant.propertyware_id],
                    ['Name on lease', tenant.is_name_on_lease ? 'Yes' : 'No']
                ]" :key="item[0]" class="flex flex-col gap-1">
                    <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ item[0] }}</span>
                    <span class="text-sm">{{ item[1] || "—" }}</span>
                </div>
                <div v-if="tenant.comments" class="flex flex-col gap-1 sm:col-span-2 lg:col-span-3">
                    <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Comments</span>
                    <p class="whitespace-pre-line text-sm">{{ tenant.comments }}</p>
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
                    <CardDescription>Messages sent to and received from this tenant.</CardDescription>
                </div>
                <Button type="button" :disabled="!tenant.email" @click="composeOpen = true">Compose Email</Button>
            </CardHeader>

            <CardContent class="flex min-h-[34rem] flex-col gap-4">
                <template>
                    <div class="flex max-h-[34rem] flex-1 flex-col gap-3 overflow-y-auto pr-1">
                        <article
                            v-for="email in jobEmailHistory"
                            :key="email.id"
                            class="rounded-lg border p-4"
                            :class="
                                email.direction === 'inbound'
                                    ? 'mr-4 bg-white text-black shadow-md dark:bg-muted dark:text-foreground sm:mr-10'
                                    : 'ml-4 border-primary bg-primary text-primary-foreground shadow-md sm:ml-10'
                            "
                        >
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="font-medium">{{ email.subject }}</p>
                                    <p class="text-xs" :class="email.direction === 'outbound' ? 'text-primary-foreground/80' : 'text-muted-foreground'">
                                        {{ email.direction === "inbound" ? "Received" : "Sent" }} ·
                                        {{ email.from_email }} → {{ email.to_email }}
                                    </p>
                                </div>
                                <span class="whitespace-nowrap text-xs" :class="email.direction === 'outbound' ? 'text-primary-foreground/80' : 'text-muted-foreground'">
                                    {{ formatDate(email.sent_at) }}
                                </span>
                            </div>

                            <button type="button" class="mt-3 block w-full text-left text-sm hover:underline" @click="selectedEmail = email">
                                <span class="line-clamp-1">{{ email.body_text || "No message preview available." }}</span>
                            </button>

                            <div v-if="email.attachments?.length" class="mt-3 flex flex-wrap gap-2">
                                <a
                                    v-for="attachment in email.attachments"
                                    :key="attachment.id"
                                    :href="route('tenant.email.attachment', attachment.id)"
                                    class="rounded-md border border-input bg-background px-2 py-1 text-xs font-medium text-primary hover:underline"
                                >
                                    {{ attachment.filename }}
                                </a>
                            </div>
                        </article>

                        <p
                            v-if="jobEmailHistory.length === 0"
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            No emails recorded for this Jobber job yet.
                        </p>
                    </div>

                </template>
            </CardContent>
        </Card>

        <EmailViewerDialog v-model="selectedEmail" />

        <Dialog v-model:open="composeOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Compose tenant email</DialogTitle>
                    <DialogDescription>This email will be saved in the tenant's email history.</DialogDescription>
                </DialogHeader>
                <form class="flex flex-col gap-3" @submit.prevent="sendTenantEmail">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex flex-col gap-1 text-sm">
                                <span class="font-medium text-muted-foreground">From</span>
                                <input v-model="emailForm.from_email" type="email" required class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
                            </label>
                            <label class="flex flex-col gap-1 text-sm">
                                <span class="font-medium text-muted-foreground">To</span>
                                <input v-model="emailForm.to" type="email" required class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
                            </label>
                        </div>
                        <div>
                            <input
                                v-model="emailForm.subject"
                                type="text"
                                required
                                maxlength="255"
                                placeholder="Subject"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                            <p v-if="emailForm.errors.subject" class="mt-1 text-xs text-destructive">
                                {{ emailForm.errors.subject }}
                            </p>
                        </div>

                        <div>
                            <RichTextEditor v-model="emailForm.body" />
                            <p v-if="emailForm.errors.body" class="mt-1 text-xs text-destructive">
                                {{ emailForm.errors.body }}
                            </p>
                        </div>

                        <p v-if="emailForm.errors.jobber_job_id" class="text-xs text-destructive">
                            {{ emailForm.errors.jobber_job_id }}
                        </p>

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <input
                                    ref="fileInput"
                                    type="file"
                                    multiple
                                    class="text-xs file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-2 file:text-xs file:font-medium"
                                    @change="selectFiles"
                                />
                                <p v-if="emailForm.errors.attachments" class="mt-1 text-xs text-destructive">
                                    {{ emailForm.errors.attachments }}
                                </p>
                            </div>
                            <button
                                type="submit"
                                :disabled="emailForm.processing || !emailForm.subject.trim() || !emailForm.body.trim()"
                                class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{ emailForm.processing ? "Sending..." : "Send Email" }}
                            </button>
                        </div>
                </form>
            </DialogContent>
        </Dialog>

        <Card v-if="activeTab === 'work-orders'">
            <CardHeader><CardTitle>Tenant work orders</CardTitle></CardHeader>
            <CardContent class="overflow-x-auto">
                <Table>
                    <TableHeader><TableRow><TableHead>Work order</TableHead><TableHead>Category</TableHead><TableHead>Status</TableHead><TableHead>Created</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="workOrder in workOrders" :key="workOrder.id">
                            <TableCell><Link :href="route('work_orders.details', workOrder.id)" class="font-medium text-primary hover:underline">#{{ workOrder.work_order_no }}</Link></TableCell>
                            <TableCell>{{ workOrder.category || "—" }}</TableCell>
                            <TableCell>{{ workOrder.status || "—" }}</TableCell>
                            <TableCell>{{ formatDate(workOrder.created_date) }}</TableCell>
                        </TableRow>
                        <TableRow v-if="workOrders.length === 0"><TableCell colspan="4" class="py-8 text-center text-muted-foreground">No work orders found.</TableCell></TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <Card v-if="activeTab === 'jobber-jobs'">
            <CardHeader><CardTitle>Tagged Jobber jobs</CardTitle></CardHeader>
            <CardContent class="overflow-x-auto">
                <Table>
                    <TableHeader><TableRow><TableHead>Job</TableHead><TableHead>Title</TableHead><TableHead>Status</TableHead><TableHead>Start</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="job in jobberJobs" :key="job.id">
                            <TableCell><Link :href="route('jobber.jobDetails', job.id)" class="font-medium text-primary hover:underline">#{{ job.job_number }}</Link></TableCell>
                            <TableCell>{{ job.title || "—" }}</TableCell>
                            <TableCell>{{ job.status || "—" }}</TableCell>
                            <TableCell>{{ formatDate(job.start_at) }}</TableCell>
                        </TableRow>
                        <TableRow v-if="jobberJobs.length === 0"><TableCell colspan="4" class="py-8 text-center text-muted-foreground">No Jobber jobs tagged yet.</TableCell></TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
