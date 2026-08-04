<script setup>
import { ref, computed } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { router } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import {
    RefreshCw,
    PlugZap,
    Stethoscope,
    RotateCcw,
    Trash2,
    Loader2,
    CheckCircle2,
    XCircle,
    ExternalLink,
} from "lucide-vue-next";
import { Button } from "@/Components/ui/button";
import { Badge } from "@/Components/ui/badge";
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    CardDescription,
} from "@/Components/ui/card";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/Components/ui/alert-dialog";

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    status: Object,
    failedJobs: Array,
});

const { toast } = useToast();

const connectionState = computed(() => {
    if (props.status?.needs_reconnect) {
        return { label: "Disconnected — reconnect needed", tone: "destructive" };
    }
    if (props.status?.connected) {
        return { label: "Connected", tone: "success" };
    }
    return { label: "Not connected", tone: "destructive" };
});

const reconnect = () => {
    window.location.href = route("jobber.connect");
};

// Diagnostics
const diagnostics = ref(null);
const diagnosticsLoading = ref(false);

const runDiagnostics = async () => {
    diagnosticsLoading.value = true;
    diagnostics.value = null;
    try {
        const response = await fetch(route("jobber.diagnose"), {
            headers: { Accept: "application/json" },
        });
        if (!response.ok) {
            throw new Error(`Diagnostics request failed (${response.status})`);
        }
        diagnostics.value = await response.json();
    } catch (error) {
        toast({
            title: "Diagnostics failed",
            description: error.message,
            variant: "destructive",
        });
    } finally {
        diagnosticsLoading.value = false;
    }
};

const diagnosticChecks = computed(() => {
    if (!diagnostics.value) return [];
    const env = diagnostics.value.environment ?? {};
    const token = diagnostics.value.token ?? {};
    const api = diagnostics.value.api_test ?? {};
    return [
        { label: "Client ID configured", ok: !!env.client_id_set },
        { label: "Client secret configured", ok: !!env.client_secret_set },
        { label: "Callback URL configured", ok: !!env.callback_url_set },
        { label: "API version configured", ok: !!env.api_version_set },
        { label: "Token stored", ok: !!token.exists },
        { label: "Access token present", ok: !!token.has_access_token },
        { label: "Refresh token present", ok: !!token.has_refresh_token },
        { label: "Token not expired", ok: token.is_expired === false },
        { label: "No reconnect flag", ok: token.needs_reconnect === false },
        { label: "Live API call", ok: api.status === "success" },
    ];
});

// Failed job actions
const busyUuid = ref(null);
const confirmingDismiss = ref(null);

const retryJob = (job) => {
    busyUuid.value = job.uuid;
    router.post(
        route("it-tools.jobber.retry", job.uuid),
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                toast({
                    title: "Retry queued",
                    description: `Work order #${job.work_order_no ?? job.work_order_id ?? "?"} will sync on the next queue cycle.`,
                }),
            onFinish: () => (busyUuid.value = null),
        },
    );
};

const dismissJob = () => {
    const job = confirmingDismiss.value;
    if (!job) return;
    busyUuid.value = job.uuid;
    router.delete(route("it-tools.jobber.forget", job.uuid), {
        preserveScroll: true,
        onSuccess: () => toast({ title: "Failed job dismissed" }),
        onFinish: () => {
            busyUuid.value = null;
            confirmingDismiss.value = null;
        },
    });
};

const refreshPage = () => {
    router.reload({ preserveScroll: true });
};
</script>

<template>
    <div class="p-4 md:p-6 space-y-6 max-w-5xl">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">IT Tools — Jobber</h1>
                <p class="text-sm text-muted-foreground">
                    Connection health, reconnect, diagnostics and stuck THMP job syncs.
                </p>
            </div>
            <Button variant="outline" size="sm" @click="refreshPage">
                <RefreshCw class="w-4 h-4 mr-2" /> Refresh
            </Button>
        </div>

        <!-- Connection status -->
        <Card>
            <CardHeader>
                <div class="flex items-center justify-between">
                    <div>
                        <CardTitle>Jobber connection</CardTitle>
                        <CardDescription>
                            THMP work orders sync to Jobber only while this connection is healthy.
                        </CardDescription>
                    </div>
                    <Badge
                        :class="
                            connectionState.tone === 'success'
                                ? 'bg-green-100 text-green-800 hover:bg-green-100'
                                : 'bg-red-100 text-red-800 hover:bg-red-100'
                        "
                    >
                        {{ connectionState.label }}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <div class="text-muted-foreground">Token expires</div>
                        <div class="font-medium">{{ status?.expires_at ?? "—" }}</div>
                    </div>
                    <div>
                        <div class="text-muted-foreground">Last refreshed</div>
                        <div class="font-medium">{{ status?.updated_at ?? "—" }}</div>
                    </div>
                    <div v-if="status?.needs_reconnect">
                        <div class="text-muted-foreground">Disconnected since</div>
                        <div class="font-medium text-red-700">
                            {{ status?.disconnected_since ?? "unknown" }}
                        </div>
                    </div>
                </div>

                <div
                    v-if="status?.needs_reconnect"
                    class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
                >
                    The Jobber refresh token was rejected, so job sync and webhooks are
                    paused. Click <strong>Reconnect with Jobber</strong>, sign in, and
                    approve access to restore the connection — then retry the failed
                    syncs below.
                </div>

                <div class="flex gap-2">
                    <Button @click="reconnect">
                        <PlugZap class="w-4 h-4 mr-2" /> Reconnect with Jobber
                    </Button>
                    <Button variant="outline" :disabled="diagnosticsLoading" @click="runDiagnostics">
                        <Loader2 v-if="diagnosticsLoading" class="w-4 h-4 mr-2 animate-spin" />
                        <Stethoscope v-else class="w-4 h-4 mr-2" />
                        Run diagnostics
                    </Button>
                </div>

                <div v-if="diagnostics" class="rounded-md border p-3">
                    <div class="text-sm font-medium mb-2">Diagnostics</div>
                    <ul class="grid grid-cols-1 md:grid-cols-2 gap-1 text-sm">
                        <li
                            v-for="check in diagnosticChecks"
                            :key="check.label"
                            class="flex items-center gap-2"
                        >
                            <CheckCircle2 v-if="check.ok" class="w-4 h-4 text-green-600 shrink-0" />
                            <XCircle v-else class="w-4 h-4 text-red-600 shrink-0" />
                            {{ check.label }}
                        </li>
                    </ul>
                </div>
            </CardContent>
        </Card>

        <!-- Failed Jobber job creations -->
        <Card>
            <CardHeader>
                <CardTitle>Failed THMP job syncs</CardTitle>
                <CardDescription>
                    Work orders whose Jobber job could not be created. Before retrying,
                    check Jobber for a job someone already created by hand — a retry
                    cannot detect those and would create a duplicate. Dismiss rows that
                    already have a job.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p v-if="!failedJobs?.length" class="text-sm text-muted-foreground">
                    No failed Jobber job creations. 🎉
                </p>
                <Table v-else>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Work order</TableHead>
                            <TableHead>Failed at</TableHead>
                            <TableHead>Error</TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="job in failedJobs" :key="job.uuid">
                            <TableCell>
                                <a
                                    v-if="job.work_order_id"
                                    :href="route('work_orders.show', job.work_order_id)"
                                    class="text-blue-600 hover:underline inline-flex items-center gap-1"
                                    target="_blank"
                                >
                                    #{{ job.work_order_no ?? job.work_order_id }}
                                    <ExternalLink class="w-3 h-3" />
                                </a>
                                <span v-else class="text-muted-foreground">unknown</span>
                                <Badge
                                    v-if="job.already_linked"
                                    class="ml-2 bg-green-100 text-green-800 hover:bg-green-100"
                                >
                                    already linked
                                </Badge>
                            </TableCell>
                            <TableCell class="whitespace-nowrap">{{ job.failed_at }}</TableCell>
                            <TableCell class="max-w-md truncate" :title="job.error">
                                {{ job.error }}
                            </TableCell>
                            <TableCell class="text-right whitespace-nowrap">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="mr-2"
                                    :disabled="busyUuid === job.uuid || job.already_linked"
                                    @click="retryJob(job)"
                                >
                                    <Loader2
                                        v-if="busyUuid === job.uuid"
                                        class="w-4 h-4 mr-1 animate-spin"
                                    />
                                    <RotateCcw v-else class="w-4 h-4 mr-1" />
                                    Retry
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="busyUuid === job.uuid"
                                    @click="confirmingDismiss = job"
                                >
                                    <Trash2 class="w-4 h-4 mr-1" /> Dismiss
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <AlertDialog
            :open="!!confirmingDismiss"
            @update:open="(open) => !open && (confirmingDismiss = null)"
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Dismiss this failed sync?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Work order
                        #{{ confirmingDismiss?.work_order_no ?? confirmingDismiss?.work_order_id ?? "?" }}
                        will never sync to Jobber automatically. Only dismiss it if the
                        Jobber job already exists (created by hand) or is not needed.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel @click="confirmingDismiss = null">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="dismissJob">Dismiss</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </div>
</template>
