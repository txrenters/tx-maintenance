<script setup>
/**
 * Close / reopen control for a Jobber job.
 *
 * The close is ours, not Jobber's: it takes the job off the active board and
 * locks the vendor portal, but changes nothing in Jobber, where payment and the
 * job's own lifecycle still live. The banner says so, so nobody assumes closing
 * here has closed it there.
 *
 * Emits `saved` after a successful write so the board modal — which keeps job
 * data in local state rather than Inertia props — can refetch.
 */
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { DateTime } from "luxon";
import { CheckCircle2, Loader2, RotateCcw } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Separator } from "@/Components/ui/separator";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";

const props = defineProps({
    jobId: [Number, String],
    isClosed: { type: Boolean, default: false },
    closedAt: { type: String, default: null },
    closedBy: { type: String, default: null },
    closeReason: { type: String, default: null },
    canClose: { type: Boolean, default: false },
});

const emit = defineEmits(["saved"]);

const { toast } = useToast();

const openCloseModal = ref(false);
const isSaving = ref(false);
const reason = ref("");

const formatDate = (date) => {
    if (!date) return "";
    const parsed = DateTime.fromISO(String(date), { zone: "utc" });
    return parsed.isValid ? parsed.toFormat("MM/dd/yyyy") : "";
};

const closeJob = () => {
    isSaving.value = true;
    router.post(
        route("jobber.close", props.jobId),
        { close_reason: reason.value },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({ title: "Closed", description: "Job closed." });
                openCloseModal.value = false;
                reason.value = "";
                emit("saved");
            },
            onError: () =>
                toast({
                    variant: "destructive",
                    title: "Error",
                    description: "Could not close the job.",
                }),
            onFinish: () => (isSaving.value = false),
        }
    );
};

const reopenJob = () => {
    isSaving.value = true;
    router.delete(route("jobber.reopen", props.jobId), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({ title: "Reopened", description: "Job reopened." });
            emit("saved");
        },
        onFinish: () => (isSaving.value = false),
    });
};
</script>

<template>
    <div>
        <div
            v-if="isClosed"
            class="flex flex-wrap items-center gap-3 border border-emerald-200 bg-emerald-50 text-emerald-900 rounded-md px-3 py-2 mb-3"
        >
            <CheckCircle2 class="w-4 h-4 shrink-0" />
            <div class="text-sm flex-grow">
                <p class="font-semibold">
                    Closed<span v-if="closedBy"> by {{ closedBy }}</span>
                    <span v-if="closedAt"> on {{ formatDate(closedAt) }}</span>
                </p>
                <p v-if="closeReason" class="text-xs">
                    Reason: {{ closeReason }}
                </p>
                <p class="text-xs opacity-80">
                    Closed here only — the job is untouched in Jobber.
                </p>
            </div>
            <Button
                v-if="canClose"
                size="sm"
                variant="outline"
                :disabled="isSaving"
                @click.prevent="reopenJob"
            >
                <Loader2 v-if="isSaving" class="w-4 h-4 animate-spin" />
                <RotateCcw v-else class="w-4 h-4" />
                Reopen
            </Button>
        </div>

        <div v-else-if="canClose" class="flex justify-end mb-3">
            <Button
                size="sm"
                variant="outline"
                :disabled="isSaving"
                @click.prevent="openCloseModal = true"
            >
                <CheckCircle2 class="w-4 h-4" />
                Close Job
            </Button>
        </div>

        <Dialog v-model:open="openCloseModal">
            <DialogContent class="sm:max-w-[500px] p-0">
                <DialogHeader class="p-6 pb-0 text-left">
                    <DialogTitle>Close this job?</DialogTitle>
                    <DialogDescription>
                        It comes off the active board and the assigned vendors
                        can no longer upload to it. Nothing is sent to Jobber —
                        payment and closing there are still done in Jobber. You
                        can reopen it at any time.
                    </DialogDescription>
                </DialogHeader>
                <Separator />
                <div class="px-6">
                    <Label>Reason (optional)</Label>
                    <Input
                        type="text"
                        placeholder="e.g. Work finished and invoiced"
                        v-model="reason"
                    />
                </div>
                <DialogFooter class="p-6 pt-0">
                    <Button
                        variant="destructive"
                        @click="openCloseModal = false"
                    >
                        Cancel
                    </Button>
                    <Button :disabled="isSaving" @click.prevent="closeJob">
                        <Loader2
                            v-if="isSaving"
                            class="w-4 h-4 animate-spin"
                        />
                        Close Job
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
