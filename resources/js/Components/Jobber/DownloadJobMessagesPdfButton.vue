<script setup>
import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";
import { FileDown, Loader2 } from "lucide-vue-next";
import { Button } from "@/Components/ui/button";
import { useToast } from "@/Components/ui/toast/use-toast";
import { downloadPdf } from "@/utils/downloadPdf";

// Download a Jobber job's text messages as a PDF transcript. The job modal
// only shows the first 50, so the server renders the whole thread.
const props = defineProps({
    jobId: { type: [Number, String], default: null },
    /** Our side of the thread, used to tell sent from received. */
    ourNumber: { type: String, default: "" },
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
            route("jobber.messages.pdf", { job: props.jobId }),
            { our_number: props.ourNumber || null },
            "Job messages.pdf",
        );
    } catch (error) {
        toast({
            variant: "destructive",
            title: "PDF download failed",
            description: "The job messages could not be saved as a PDF. Please try again.",
        });
    } finally {
        downloading.value = false;
    }
};
</script>

<template>
    <Button
        v-if="isStaff && jobId"
        type="button"
        size="sm"
        variant="outline"
        class="h-7 gap-1.5 text-xs"
        :disabled="downloading"
        title="Download this job's messages as a PDF"
        @click="download"
    >
        <Loader2 v-if="downloading" class="h-3.5 w-3.5 animate-spin" />
        <FileDown v-else class="h-3.5 w-3.5" />
        {{ downloading ? "Preparing…" : "PDF" }}
    </Button>
</template>
