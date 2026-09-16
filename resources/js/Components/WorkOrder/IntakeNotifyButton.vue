<script setup>
import { ref } from "vue";
import axios from "axios";
import { Send } from "lucide-vue-next";
import { Button } from "@/Components/ui/button";
import { useToast } from "@/Components/ui/toast/use-toast";
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

// Re-send the intake notification a work order never got. The intake messages
// fire once, at import, so a work order muted then - most often a PropertyWare
// website request imported before its lease was attached - stays silent unless
// the lease later arrives. This lets a coordinator send what was owed.
const props = defineProps({
    workOrderId: { type: [Number, String], required: true },
    audience: {
        type: String,
        required: true,
        validator: (value) => ["tenant", "owner"].includes(value),
    },
});

const { toast } = useToast();

const sending = ref(false);
const confirmOpen = ref(false);
const confirmMessage = ref("");

const label = props.audience === "tenant" ? "tenant" : "owner";

const send = async (confirm = false) => {
    if (sending.value) return;

    sending.value = true;
    try {
        const { data } = await axios.post(
            `/work_orders/${props.workOrderId}/notify-intake/${props.audience}`,
            confirm ? { confirm: true } : {},
        );

        // Muted for a reason a human can overrule: say why, then let them.
        if (data.needs_confirmation) {
            confirmMessage.value = data.message;
            confirmOpen.value = true;
            return;
        }

        toast({
            title: "Intake notification queued",
            description: `The ${label} will receive the request confirmation shortly.`,
        });
    } catch (error) {
        toast({
            variant: "destructive",
            title: "Could not send",
            description:
                error?.response?.data?.error ||
                error?.response?.data?.message ||
                "Please try again.",
        });
    } finally {
        sending.value = false;
    }
};

const confirmSend = async () => {
    confirmOpen.value = false;
    await send(true);
};
</script>

<template>
    <div>
        <Button
            type="button"
            size="sm"
            variant="outline"
            class="h-7 gap-1.5 text-xs"
            :disabled="sending"
            :title="`Send the ${label} the intake confirmation for this work order`"
            @click="send(false)"
        >
            <Send class="h-3.5 w-3.5" />
            {{ sending ? "Sending..." : "Send intake notice" }}
        </Button>

        <AlertDialog v-model:open="confirmOpen">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Send anyway?</AlertDialogTitle>
                    <AlertDialogDescription>
                        {{ confirmMessage }}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="confirmSend">
                        Send anyway
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </div>
</template>
