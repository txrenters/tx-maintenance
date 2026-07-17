<script setup>
import AppLayout from "@/Layouts/AppLayout.vue";
import RichTextEditor from "@/Components/RichTextEditor.vue";
import { router } from "@inertiajs/vue3";
import { ref } from "vue";
import { useToast } from "@/Components/ui/toast/use-toast";
import { ChevronDown } from "lucide-vue-next";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    vendor: Object,
    workOrders: { type: Array, default: () => [] },
    emailHistory: { type: Array, default: () => [] },
    senderEmail: String,
});

const activeTab = ref("emails");
const detailsOpen = ref(false);
const composeOpen = ref(false);
const fromEmail = ref(props.senderEmail);
const toEmail = ref(props.vendor.email ?? "");
const subject = ref("");
const body = ref("");
const files = ref([]);
const sending = ref(false);
const selectedEmail = ref(null);
const { toast } = useToast();

const sendEmail = () => {
    const data = new FormData();
    data.append("vendor_id", props.vendor.id);
    data.append("from_email", fromEmail.value);
    data.append("to", toEmail.value);
    data.append("subject", subject.value);
    data.append("body", body.value);
    files.value.forEach((file) => data.append("attachments[]", file));
    sending.value = true;
    router.post(route("vendor.email.send", props.vendor.id), data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            activeTab.value = "emails";
            composeOpen.value = false;
            subject.value = "";
            body.value = "";
            files.value = [];
            toast({ title: "Email sent", description: "The vendor email was added to the thread." });
        },
        onError: () => toast({ variant: "destructive", title: "Email not sent", description: "Check the email details and try again." }),
        onFinish: () => { sending.value = false; },
    });
};

const priorityClass = (priority) => {
    const p = (priority || "").toLowerCase();
    if (p.includes("high") || p.includes("emergency"))
        return "bg-red-100 text-red-700";
    if (p.includes("medium")) return "bg-amber-100 text-amber-700";
    return "bg-slate-100 text-slate-600";
};

const fmtDate = (d) => (d ? new Date(d).toLocaleDateString() : "—");
const fmtDateTime = (d) => (d ? new Date(d).toLocaleString() : "—");
const fmtMoney = (v) =>
    v !== null && v !== undefined && v !== ""
        ? `$${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}`
        : "—";
</script>

<template>
    <Head :title="title" />

    <div class="space-y-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-semibold tracking-tight">{{ vendor.name }}</h1>
                <Badge :variant="vendor.is_active ? 'default' : 'secondary'">{{ vendor.is_active ? "Active" : "Inactive" }}</Badge>
            </div>
            <p class="text-sm text-muted-foreground">{{ vendor.email || "No email address" }}</p>
        </div>

        <Card>
            <CardHeader class="p-0">
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 p-6 text-left"
                    :aria-expanded="detailsOpen"
                    @click="detailsOpen = !detailsOpen"
                >
                    <CardTitle>Vendor details</CardTitle>
                    <ChevronDown class="size-5 shrink-0 transition-transform" :class="detailsOpen && 'rotate-180'" />
                </button>
            </CardHeader>
            <CardContent v-if="detailsOpen" class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="item in [
                    ['Email', vendor.email], ['Phone', vendor.phone], ['Company', vendor.company],
                    ['Address', vendor.address], ['Type', vendor.vendor_type], ['Name on check', vendor.name_on_check],
                    ['Twilio', vendor.twilio_number], ['PropertyWare ID', vendor.propertyware_id],
                ]" :key="item[0]" class="flex flex-col gap-1">
                    <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ item[0] }}</span>
                    <span class="text-sm">{{ item[1] || "—" }}</span>
                </div>
            </CardContent>
        </Card>

        <div class="flex gap-1 overflow-x-auto border-b">
            <button
                v-for="tab in [
                    { id: 'emails', label: `Email History (${emailHistory.length})` },
                    { id: 'work-orders', label: `Work Orders (${workOrders.length})` },
                ]"
                :key="tab.id"
                type="button"
                class="whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors"
                :class="activeTab === tab.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
                @click="activeTab = tab.id"
            >
                {{ tab.label }}
            </button>
        </div>

        <!-- Assigned work orders -->
        <Card v-if="activeTab === 'work-orders'">
            <CardHeader>
                <CardTitle class="text-base">
                    Assigned Work Orders ({{ workOrders.length }})
                </CardTitle>
            </CardHeader>
            <CardContent>
                <Table v-if="workOrders.length">
                    <TableHeader>
                        <TableRow>
                            <TableHead>WO #</TableHead>
                            <TableHead class="hidden md:table-cell">Description</TableHead>
                            <TableHead class="hidden lg:table-cell">Location</TableHead>
                            <TableHead>Priority</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="hidden md:table-cell">Created</TableHead>
                            <TableHead class="text-right">Your estimate</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="wo in workOrders" :key="wo.id">
                            <TableCell>
                                <Link
                                    :href="route('work_orders.details', wo.id)"
                                    class="font-medium text-primary hover:underline"
                                >
                                    #{{ wo.work_order_no }}
                                </Link>
                            </TableCell>
                            <TableCell class="hidden md:table-cell max-w-xs">
                                <span class="line-clamp-1">{{ wo.description || "—" }}</span>
                            </TableCell>
                            <TableCell class="hidden lg:table-cell">
                                {{ wo.location || "—" }}
                            </TableCell>
                            <TableCell>
                                <span
                                    class="text-xs font-medium rounded-full px-2.5 py-0.5"
                                    :class="priorityClass(wo.priority)"
                                >
                                    {{ wo.priority || "—" }}
                                </span>
                            </TableCell>
                            <TableCell>
                                <span class="text-sm">{{ wo.service_status || wo.status || "—" }}</span>
                            </TableCell>
                            <TableCell class="hidden md:table-cell text-sm text-muted-foreground">
                                {{ fmtDate(wo.created_date) }}
                            </TableCell>
                            <TableCell class="text-right text-sm">
                                {{ fmtMoney(wo.cost_estimate) }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <p v-else class="text-sm text-muted-foreground py-6 text-center">
                    No work orders assigned to this vendor.
                </p>
            </CardContent>
        </Card>

        <Card v-if="activeTab === 'emails'">
            <CardHeader class="gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-1">
                    <CardTitle>Email History</CardTitle>
                    <CardDescription>Messages sent to and received from this vendor.</CardDescription>
                </div>
                    <Button :disabled="!vendor.email" @click="composeOpen = true">Compose Email</Button>
            </CardHeader>
            <CardContent class="flex min-h-[34rem] flex-col gap-4">
                <div class="flex max-h-[34rem] flex-1 flex-col gap-3 overflow-y-auto pr-1">
                <article v-for="email in emailHistory" :key="email.id" class="rounded-lg border p-4" :class="email.direction === 'inbound' ? 'mr-4 bg-white text-black shadow-md dark:bg-muted dark:text-foreground sm:mr-10' : 'ml-4 border-primary bg-primary text-primary-foreground shadow-md sm:ml-10'">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="font-medium">{{ email.subject || "No subject" }}</p>
                            <p class="mt-1 text-xs" :class="email.direction === 'outbound' ? 'text-primary-foreground/80' : 'text-muted-foreground'">
                                {{ email.from_email }} → {{ email.to_email }}
                            </p>
                            <p v-if="email.cc.length" class="text-xs" :class="email.direction === 'outbound' ? 'text-primary-foreground/80' : 'text-muted-foreground'">
                                CC: {{ email.cc.join(", ") }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-col items-start gap-1 text-xs sm:items-end" :class="email.direction === 'outbound' ? 'text-primary-foreground/80' : 'text-muted-foreground'">
                            <span>{{ fmtDateTime(email.emailed_at) }}</span>
                            <Link
                                v-if="email.work_order"
                                :href="route('work_orders.details', email.work_order.id)"
                                class="text-primary hover:underline"
                            >
                                Work Order #{{ email.work_order.work_order_no }}
                            </Link>
                        </div>
                    </div>
                    <button type="button" class="mt-3 block w-full text-left text-sm hover:underline" @click="selectedEmail = email">
                        <span class="line-clamp-1">{{ email.body_text || "No message preview available." }}</span>
                    </button>
                </article>

                <p v-if="emailHistory.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                    No emails recorded for this vendor yet.
                </p>
                </div>
            </CardContent>
        </Card>

        <Dialog :open="selectedEmail !== null" @update:open="!$event && (selectedEmail = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader><DialogTitle>{{ selectedEmail?.subject || "Email message" }}</DialogTitle><DialogDescription>{{ selectedEmail?.from_email }} → {{ selectedEmail?.to_email }} · {{ fmtDateTime(selectedEmail?.emailed_at) }}</DialogDescription></DialogHeader>
                <p class="whitespace-pre-wrap text-sm">{{ selectedEmail?.body_text || "No message content." }}</p>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="composeOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Compose vendor email</DialogTitle>
                    <DialogDescription>This email will be saved in the vendor's email history.</DialogDescription>
                </DialogHeader>
                <form class="flex flex-col gap-3" @submit.prevent="sendEmail">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex flex-col gap-1 text-sm"><span class="font-medium text-muted-foreground">From</span><input v-model="fromEmail" type="email" required class="rounded-md border border-input bg-background px-3 py-2 text-sm" /></label>
                        <label class="flex flex-col gap-1 text-sm"><span class="font-medium text-muted-foreground">To</span><input v-model="toEmail" type="email" required class="rounded-md border border-input bg-background px-3 py-2 text-sm" /></label>
                    </div>
                    <input v-model="subject" required maxlength="255" placeholder="Subject" class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
                    <RichTextEditor v-model="body" />
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <input type="file" multiple class="text-xs" @change="files = Array.from($event.target.files ?? [])" />
                        <Button type="submit" :disabled="sending || !subject.trim() || !body.trim()">{{ sending ? "Sending..." : "Send Email" }}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
