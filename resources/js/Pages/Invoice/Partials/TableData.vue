<script setup>
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { DateTime } from "luxon";
import { FileDownIcon } from "lucide-vue-next";
import { useToast } from "@/Components/ui/toast/use-toast";
import { useWorkOrderModal } from "@/composables/useWorkOrderModal";
import SortableHead from "./SortableHead.vue";

const props = defineProps({
    data: Object,
    sort: String,
    direction: String,
    canPost: Boolean,
});

const { toast } = useToast();

// Ids being written, so a row's checkbox stays disabled until its request lands
// and a double click cannot fire twice.
const savingIds = ref([]);

const togglePosted = (invoice, checked) => {
    if (!props.canPost || savingIds.value.includes(invoice.id)) return;

    savingIds.value.push(invoice.id);

    const done = () => {
        savingIds.value = savingIds.value.filter((id) => id !== invoice.id);
    };

    const options = {
        preserveState: true,
        preserveScroll: true,
        onError: () =>
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description: "Could not save that. Please try again!",
            }),
        onFinish: done,
    };

    if (checked) {
        router.post(route("invoices.posted.store", invoice.id), {}, options);
    } else {
        router.delete(route("invoices.posted.destroy", invoice.id), options);
    }
};

const emit = defineEmits(["sort"]);

const page = usePage();

// AppLayout already mounts the shared WorkOrderModal, so opening one here only
// needs its id.
const { open: openWorkOrder } = useWorkOrderModal();

// A vendor only ever sees their own invoices, so the Vendor column is redundant.
const isVendor = computed(() =>
    (page.props.auth.user?.roles || []).includes("vendor")
);

const columnCount = computed(() => (isVendor.value ? 8 : 9));

// PropertyWare's real lease statuses: Active, "Active - Notice Given",
// "Going MTM", Eviction and Draft. A tenancy that is ending stands out even
// though its status still starts with "Active", so the notice is matched
// anywhere in the string rather than as a whole value. Anything unrecognised
// still renders, just without emphasis.
const leaseVariant = (status) => {
    const normalized = String(status).trim().toLowerCase();
    if (normalized.includes("eviction") || normalized.includes("notice")) {
        return "destructive";
    }
    if (normalized.startsWith("active")) return "default";
    return "secondary";
};

// Timestamps are stored in UTC, so convert before showing the upload time —
// accounting reads these against a Central-time payment cutoff.
const formatUploadedAt = (date) => {
    if (!date) return "—";
    const parsed =
        typeof date === "string" && date.includes("T")
            ? DateTime.fromISO(date, { zone: "utc" })
            : DateTime.fromFormat(String(date), "yyyy-MM-dd HH:mm:ss", {
                  zone: "utc",
              });
    return parsed.isValid
        ? parsed.setZone("America/Chicago").toFormat("MM/dd/yyyy h:mm a")
        : "—";
};

// Who put it through and when, so a coordinator does not have to ask.
const postedTitle = (invoice) => {
    if (!invoice.posted_at) return "Not posted yet";
    const when = formatUploadedAt(invoice.posted_at);
    return invoice.posted_by
        ? `Posted by ${invoice.posted_by} on ${when}`
        : `Posted on ${when}`;
};
</script>
<template>
    <Table>
        <TableHeader>
            <TableRow>
                <SortableHead
                    v-if="!isVendor"
                    column="vendor"
                    class="md:table-cell"
                    :sort="sort"
                    :direction="direction"
                    asc-label="A to Z"
                    desc-label="Z to A"
                    @sort="emit('sort', $event)"
                >
                    Vendor
                </SortableHead>
                <SortableHead
                    column="address"
                    class="hidden md:table-cell"
                    :sort="sort"
                    :direction="direction"
                    asc-label="A to Z"
                    desc-label="Z to A"
                    @sort="emit('sort', $event)"
                >
                    Address
                </SortableHead>
                <TableHead class="hidden md:table-cell"> Lease </TableHead>
                <TableHead class="md:table-cell"> Work Order </TableHead>
                <SortableHead
                    column="amount"
                    class="hidden md:table-cell"
                    :sort="sort"
                    :direction="direction"
                    asc-label="lowest first"
                    desc-label="highest first"
                    @sort="emit('sort', $event)"
                >
                    Amount
                </SortableHead>
                <TableHead>File</TableHead>
                <SortableHead
                    column="uploaded"
                    class="hidden md:table-cell"
                    :sort="sort"
                    :direction="direction"
                    asc-label="oldest first"
                    desc-label="most recent first"
                    @sort="emit('sort', $event)"
                >
                    Uploaded
                </SortableHead>
                <TableHead class="hidden md:table-cell"> Status </TableHead>
                <SortableHead
                    column="posted"
                    :sort="sort"
                    :direction="direction"
                    asc-label="not posted first"
                    desc-label="posted first"
                    @sort="emit('sort', $event)"
                >
                    Posted
                </SortableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="invoice in data" :key="invoice.id">
                <TableCell v-if="!isVendor" class="font-medium md:table-cell">
                    {{ invoice.vendor }}
                    <!-- The columns that drop away on a narrow screen. -->
                    <p class="text-xs font-normal mt-1 md:hidden">
                        {{ invoice.address }}
                    </p>
                    <p class="text-xs font-normal mt-1 md:hidden">
                        {{ invoice.amount }}
                    </p>
                    <Badge
                        class="mt-1 md:hidden"
                        :variant="
                            invoice.status === 'decline' ? 'destructive' : ''
                        "
                    >
                        {{ invoice.status }}</Badge
                    >
                    <p class="text-xs font-normal mt-1 md:hidden">
                        {{ formatUploadedAt(invoice.created_at) }}
                    </p>
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ invoice.address || "—" }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    <Badge
                        v-if="invoice.lease_status"
                        :variant="leaseVariant(invoice.lease_status)"
                    >
                        {{ invoice.lease_status }}
                    </Badge>
                    <template v-else>—</template>
                </TableCell>
                <TableCell class="md:table-cell">
                    <button
                        v-if="invoice.work_order_id"
                        type="button"
                        class="font-medium text-primary hover:underline focus-visible:ring-ring rounded focus-visible:outline-none focus-visible:ring-1"
                        title="Open this work order"
                        @click="openWorkOrder(invoice.work_order_id)"
                    >
                        {{ invoice.work_order_no }}
                    </button>
                    <template v-else>{{ invoice.work_order_no }}</template>
                    <!-- A vendor has no Vendor column to carry the narrow-screen
                         detail, so it hangs off the work order instead. -->
                    <template v-if="isVendor">
                        <p class="text-xs font-normal mt-1 md:hidden">
                            {{ invoice.address }}
                        </p>
                        <p class="text-xs font-normal mt-1 md:hidden">
                            {{ invoice.amount }}
                        </p>
                        <Badge
                            class="mt-1 md:hidden"
                            :variant="
                                invoice.status === 'decline'
                                    ? 'destructive'
                                    : ''
                            "
                        >
                            {{ invoice.status }}</Badge
                        >
                        <p class="text-xs font-normal mt-1 md:hidden">
                            {{ formatUploadedAt(invoice.created_at) }}
                        </p>
                    </template>
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ invoice.amount }}
                </TableCell>
                <TableCell>
                    <a
                        :href="invoice.file"
                        download
                        class="text-destructive hover:text-destructive/80"
                        :title="invoice.title"
                        :aria-label="`Download ${invoice.title}`"
                    >
                        <FileDownIcon class="h-5 w-5" />
                    </a>
                </TableCell>
                <TableCell class="hidden md:table-cell whitespace-nowrap">
                    {{ formatUploadedAt(invoice.created_at) }}
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    <Badge
                        :variant="
                            invoice.status === 'decline' ? 'destructive' : ''
                        "
                    >
                        {{ invoice.status }}</Badge
                    >
                </TableCell>
                <TableCell>
                    <div class="flex items-center gap-2">
                        <Checkbox
                            :checked="!!invoice.posted_at"
                            :disabled="
                                !canPost || savingIds.includes(invoice.id)
                            "
                            :aria-label="
                                invoice.posted_at
                                    ? 'Posted. Uncheck if this was not posted.'
                                    : 'Mark this invoice as posted'
                            "
                            :title="postedTitle(invoice)"
                            @update:checked="
                                (checked) => togglePosted(invoice, checked)
                            "
                        />
                        <span
                            v-if="invoice.posted_at"
                            class="hidden text-xs text-muted-foreground lg:inline"
                        >
                            {{ formatUploadedAt(invoice.posted_at) }}
                        </span>
                    </div>
                </TableCell>
            </TableRow>
            <TableRow v-if="data.length === 0">
                <TableCell :colspan="columnCount">No invoices found!</TableCell>
            </TableRow>
        </TableBody>
    </Table>
</template>