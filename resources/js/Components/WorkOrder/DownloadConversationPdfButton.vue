<script setup>
import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";
import { FileDown, Loader2 } from "lucide-vue-next";
import { Button } from "@/Components/ui/button";
import { useToast } from "@/Components/ui/toast/use-toast";
import { downloadPdf } from "@/utils/downloadPdf";

// Download the thread on screen as a PDF transcript. The tab decides which
// messages are in it (the vendor tab filters by the vendor picked), so their
// ids go to the server, which renders only rows on this work order.
const props = defineProps({
    /** The messages the tab is showing. */
    messages: { type: Array, default: () => [] },
    /** "Tenant ↔ Coordinator" and the like; heads the PDF and names the file. */
    title: { type: String, required: true },
    workOrder: { type: Object, required: true },
    /** Our side of the thread, used to tell sent from received. */
    ourNumber: { type: String, default: "" },
    /** The phone-key -> {name, role} map MessageCard labels bubbles with. */
    participants: { type: Object, default: () => ({}) },
});

const { toast } = useToast();
const page = usePage();
const downloading = ref(false);

const isStaff = computed(() =>
    ["admin", "woc", "accounting"].some((role) =>
        (page.props.auth?.user?.roles ?? []).includes(role),
    ),
);

const download = async () => {
    if (downloading.value) return;
    downloading.value = true;

    try {
        await downloadPdf(
            route("work_order.conversation.pdf", props.workOrder.id),
            {
                title: props.title,
                our_number: props.ourNumber || null,
                message_ids: props.messages
                    .map((message) => message?.id)
                    .filter((id) => Number.isInteger(id)),
                participants: Object.entries(props.participants ?? {}).map(
                    ([phone, person]) => ({
                        phone,
                        name: person?.name ?? "",
                        role: person?.role ?? "",
                    }),
                ),
            },
        );
    } catch (error) {
        toast({
            variant: "destructive",
            title: "PDF download failed",
            description: "The conversation could not be saved as a PDF. Please try again.",
        });
    } finally {
        downloading.value = false;
    }
};
</script>

<template>
    <Button
        v-if="isStaff && workOrder?.id"
        type="button"
        size="sm"
        variant="outline"
        class="h-7 gap-1.5 text-xs"
        :disabled="downloading"
        title="Download this conversation as a PDF"
        @click="download"
    >
        <Loader2 v-if="downloading" class="h-3.5 w-3.5 animate-spin" />
        <FileDown v-else class="h-3.5 w-3.5" />
        {{ downloading ? "Preparing…" : "PDF" }}
    </Button>
</template>
