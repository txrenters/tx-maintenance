<script setup>
import { ref, computed, watch } from "vue";
import axios from "axios";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import HoaNoticeRow from "./HoaNoticeRow.vue";
import { Upload, FileUp, ScanSearch, Loader2, ArrowLeft } from "lucide-vue-next";

const props = defineProps({
    buildings: { type: Array, default: () => [] },
});

const { toast } = useToast();

const open = ref(false);
const file = ref(null);
const fileInput = ref(null);
const scanning = ref(false);
const scanError = ref("");

// Live preview of the uploaded file alongside the review, so staff can read the
// original notice while confirming what the AI pulled out.
const previewUrl = ref(null);
const activePage = ref(1);
const isImagePreview = computed(() => file.value?.type?.startsWith("image/"));

watch(file, (newFile) => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
    if (newFile) previewUrl.value = URL.createObjectURL(newFile);
    activePage.value = 1;
});

// Detected notices from the scan; each gets a `building_id` the staff confirm.
const notices = ref([]);
const hasScanned = ref(false);

const createForm = useForm({
    file: null,
    notices: [],
});

const unmatchedCount = computed(
    () => notices.value.filter((n) => n.building_id == null).length,
);

// Notices staff marked as a follow-up to the violation already open on the
// property: those file under that work order instead of creating one.
const attachCount = computed(
    () =>
        notices.value.filter((n) => n.attach_to_work_order_id != null).length,
);

const submitLabel = computed(() => {
    const total = notices.value.length;
    const creating = total - attachCount.value;
    const plural = (count, noun) => `${count} ${noun}${count === 1 ? "" : "s"}`;

    if (attachCount.value === 0) return `Create ${plural(total, "work order")}`;
    if (creating === 0) return `Attach ${plural(total, "notice")}`;
    return `Create ${creating}, attach ${attachCount.value}`;
});

// Collapse notices that resolve to the same property into a single row: one
// property = one work order, so its pages and violation items merge. Matched
// notices group by building; unmatched ones group by their read address (and
// stay separate when even that is blank, so unrelated notices never merge).
const groupNotices = (raw) => {
    const groups = new Map();
    let orphan = 0;

    for (const n of raw) {
        const buildingId = n.matched?.id ?? null;
        const address = (n.property_address ?? "").trim().toLowerCase();
        let key;
        if (buildingId != null) key = `b:${buildingId}`;
        else if (address) key = `a:${address}`;
        else key = `u:${orphan++}`;

        if (!groups.has(key)) {
            groups.set(key, {
                ...n,
                building_id: buildingId,
                pages: [n.page],
                descriptions: n.description ? [n.description] : [],
                violation_items: [...(n.violation_items ?? [])],
            });
            continue;
        }

        const group = groups.get(key);
        group.pages.push(n.page);
        if (n.description && !group.descriptions.includes(n.description)) {
            group.descriptions.push(n.description);
        }
        for (const item of n.violation_items ?? []) {
            if (!group.violation_items.includes(item)) {
                group.violation_items.push(item);
            }
        }
    }

    return [...groups.values()].map((group) => {
        const pages = [...new Set(group.pages)].sort((a, b) => a - b);
        return {
            ...group,
            pages,
            page: pages[0],
            description: group.descriptions.join("\n\n"),
        };
    });
};

const removeNotice = (index) => {
    notices.value.splice(index, 1);
    if (notices.value.length === 0) {
        // Nothing left to confirm — drop back to the file/scan step.
        hasScanned.value = false;
    }
};

const onFileChange = (event) => {
    file.value = event.target.files[0] ?? null;
    // Changing the file invalidates a previous scan.
    notices.value = [];
    hasScanned.value = false;
    scanError.value = "";
};

const resetAll = () => {
    file.value = null;
    notices.value = [];
    hasScanned.value = false;
    scanError.value = "";
    createForm.reset();
    createForm.clearErrors();
    if (fileInput.value) fileInput.value.value = "";
};

// PDF viewers jump to a page via the #page= fragment; keying the iframe forces
// it to reload at the chosen page when a notice is selected.
const pdfPreviewSrc = computed(() =>
    previewUrl.value ? `${previewUrl.value}#page=${activePage.value}` : null,
);

const scan = async () => {
    if (!file.value) return;

    scanning.value = true;
    scanError.value = "";

    const data = new FormData();
    data.append("file", file.value);

    try {
        const response = await axios.post(route("work_orders.hoa.detect"), data, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        notices.value = groupNotices(response.data.notices ?? []);
        hasScanned.value = true;
        activePage.value = notices.value[0]?.page ?? 1;

        if (notices.value.length === 0) {
            scanError.value =
                "No violation notices could be read from this PDF.";
        }
    } catch (error) {
        scanError.value =
            error.response?.data?.message ??
            "Could not read the notice. Please try again.";
    } finally {
        scanning.value = false;
    }
};

const create = () => {
    createForm.file = file.value;
    createForm.notices = notices.value.map((n) => ({
        building_id: n.building_id,
        pages: n.pages ?? [n.page],
        description: n.description,
        violation_items: n.violation_items ?? [],
        hoa_name: n.hoa_name,
        notice_date: n.notice_date,
        deadline_date: n.deadline_date,
        deadline_days: n.deadline_days,
        attach_to_work_order_id: n.attach_to_work_order_id ?? null,
    }));

    createForm.post(route("work_orders.hoa.store"), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
            // The server's flash message is the truth: it says how many work
            // orders were created AND whether any failed to reach PropertyWare
            // (a local-only work order has no number and never syncs). A
            // hard-coded success toast is how those failures went unnoticed
            // until 2026-08-06.
            const serverMessage = page.props.flash?.success;
            const hasLocalOnlyWarning =
                typeof serverMessage === "string" &&
                serverMessage.includes("no work order number");

            toast({
                variant: hasLocalOnlyWarning ? "destructive" : undefined,
                title: hasLocalOnlyWarning
                    ? "Created, but not in PropertyWare"
                    : "HOA notices processed",
                description:
                    serverMessage ??
                    `${notices.value.length} work order${
                        notices.value.length === 1 ? "" : "s"
                    } created — the tenants have been sent their links.`,
            });
            open.value = false;
            resetAll();
        },
        onError: (errors) => {
            // The server says why when it can (e.g. the work order a notice
            // was to be attached to has since closed — scan again).
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    errors?.error ??
                    "The HOA notices could not be processed. Please try again!",
            });
        },
    });
};

// Reset everything whenever the modal closes.
watch(open, (isOpen) => {
    if (!isOpen) resetAll();
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                class="bg-primary px-3 py-3 rounded text-white hover:bg-primary/80 whitespace-nowrap"
                title="Upload HOA Notice"
            >
                <Upload class="w-4 h-4 mr-2" />
                Upload HOA Notice
            </Button>
        </DialogTrigger>

        <DialogContent
            :class="[
                hasScanned ? 'max-w-5xl' : 'max-w-2xl',
                'max-h-[90vh] overflow-y-auto',
            ]"
        >
            <DialogHeader>
                <DialogTitle>Upload HOA Violation Notice</DialogTitle>
                <DialogDescription>
                    Upload the notice — a PDF or a photo (JPG/PNG). One file can
                    hold several notices for different properties. We read each
                    one and match it to a property for you to confirm.
                </DialogDescription>
            </DialogHeader>

            <!-- Step 1: choose a file and scan -->
            <div v-if="!hasScanned" class="my-2 space-y-4">
                <div>
                    <Label for="hoa-file">HOA Notice (PDF or photo)</Label>
                    <div class="mt-2">
                        <input
                            id="hoa-file"
                            ref="fileInput"
                            type="file"
                            accept="application/pdf,image/jpeg,image/png,image/webp"
                            class="block w-full text-sm text-muted-foreground file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                            @change="onFileChange"
                        />
                    </div>
                    <p
                        v-if="scanError"
                        class="mt-2 text-xs text-destructive"
                    >
                        {{ scanError }}
                    </p>
                    <p
                        v-else-if="scanning"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Reading the notice… scanned documents can take up to a
                        minute.
                    </p>
                </div>
            </div>

            <!-- Step 2: review detected notices, confirm each property -->
            <div v-else class="my-2 space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium">
                        Found {{ notices.length }}
                        {{ notices.length === 1 ? "notice" : "notices" }} —
                        confirm the property for each
                    </p>
                    <span
                        v-if="unmatchedCount > 0"
                        class="text-xs font-medium text-destructive"
                    >
                        {{ unmatchedCount }} need a property
                    </span>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <!-- Left: the original notice -->
                    <div class="order-2 md:order-1">
                        <div class="md:sticky md:top-0">
                            <p
                                class="mb-1 text-xs font-medium text-muted-foreground"
                            >
                                Uploaded notice
                            </p>
                            <img
                                v-if="isImagePreview"
                                :src="previewUrl"
                                alt="Uploaded notice"
                                class="max-h-[60vh] w-full rounded-md border object-contain bg-white"
                            />
                            <iframe
                                v-else-if="previewUrl"
                                :key="activePage"
                                :src="pdfPreviewSrc"
                                title="Uploaded notice"
                                class="h-[60vh] w-full rounded-md border bg-white"
                            />
                            <div
                                v-else
                                class="flex h-[60vh] w-full items-center justify-center rounded-md border border-dashed bg-muted/30 p-4 text-center text-sm text-muted-foreground"
                            >
                                Preview unavailable — the uploaded file will
                                still be attached to the work order.
                            </div>
                        </div>
                    </div>

                    <!-- Right: the extracted notices to confirm -->
                    <div
                        class="order-1 max-h-[60vh] space-y-2 overflow-y-auto pr-1 md:order-2"
                    >
                        <!--
                            Keyed by the notice's first page (unique per grouped
                            notice) rather than its position, so removing a
                            notice never leaves a row showing another notice's
                            open-violation choice.
                        -->
                        <div
                            v-for="(notice, i) in notices"
                            :key="notice.page"
                            @click="activePage = notice.page"
                        >
                            <HoaNoticeRow
                                :notice="notice"
                                :index="i"
                                :buildings="buildings"
                                v-model:building-id="notice.building_id"
                                v-model:attach-to-work-order-id="
                                    notice.attach_to_work_order_id
                                "
                                @remove="removeNotice(i)"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <DialogFooter class="gap-2 sm:justify-between">
                <Button
                    v-if="hasScanned"
                    variant="outline"
                    type="button"
                    :disabled="createForm.processing"
                    @click="
                        hasScanned = false;
                        notices = [];
                    "
                >
                    <ArrowLeft class="w-4 h-4 mr-2" />
                    Back
                </Button>
                <span v-else />

                <Button
                    v-if="!hasScanned"
                    type="button"
                    :disabled="!file || scanning"
                    @click="scan"
                >
                    <Loader2 v-if="scanning" class="w-4 h-4 mr-2 animate-spin" />
                    <ScanSearch v-else class="w-4 h-4 mr-2" />
                    <span>{{ scanning ? "Scanning…" : "Scan notices" }}</span>
                </Button>

                <Button
                    v-else
                    type="button"
                    :disabled="
                        createForm.processing ||
                        notices.length === 0 ||
                        unmatchedCount > 0
                    "
                    @click="create"
                >
                    <Loader2
                        v-if="createForm.processing"
                        class="w-4 h-4 mr-2 animate-spin"
                    />
                    <FileUp v-else class="w-4 h-4 mr-2" />
                    <span>
                        {{ createForm.processing ? "Saving…" : submitLabel }}
                    </span>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
