<script setup>
import { computed } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import { DateTime } from "luxon";
import { FileDownIcon } from "lucide-vue-next";

const emit = defineEmits(["openEditDialog", "openDeleteDialog"]);

defineProps({
    data: Object,
});

const page = usePage();

// A vendor only ever sees their own invoices, so the Vendor column is redundant.
const isVendor = computed(() =>
    (page.props.auth.user?.roles || []).includes("vendor")
);

const openEditDialog = (user) => {
    emit("openEditDialog", true, user);
};

const openDeleteDialog = (user) => {
    emit("openDeleteDialog", true, user);
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
</script>
<template>
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead>Name</TableHead>
                <TableHead class="hidden md:table-cell"> File </TableHead>
                <TableHead class="hidden md:table-cell"> Amount </TableHead>
                <TableHead class="hidden md:table-cell"> Status </TableHead>
                <TableHead class="md:table-cell"> Work Order </TableHead>
                <TableHead v-if="!isVendor" class="md:table-cell">
                    Vendor
                </TableHead>
                <TableHead class="hidden md:table-cell"> Uploaded </TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="invoice in data" :key="invoice.id">
                <TableCell class="font-medium">
                    <a
                        :href="invoice.file"
                        download
                        class="text-primary hover:text-primary/80"
                    >
                        {{ invoice.title }}
                    </a>
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
                    <a
                        :href="invoice.file"
                        download
                        class="text-destructive hover:text-destructive/80"
                    >
                        <FileDownIcon class="h-5 w-5" />
                    </a>
                </TableCell>
                <TableCell class="hidden md:table-cell">
                    {{ invoice.amount }}
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
                <TableCell class="md:table-cell">
                    <Link
                        v-if="invoice.work_order_id"
                        :href="
                            route('work_orders.details', invoice.work_order_id)
                        "
                        class="font-medium text-primary hover:underline"
                    >
                        {{ invoice.work_order_no }}
                    </Link>
                    <template v-else>{{ invoice.work_order_no }}</template>
                </TableCell>
                <TableCell v-if="!isVendor" class="md:table-cell">
                    {{ invoice.vendor }}
                </TableCell>
                <TableCell class="hidden md:table-cell whitespace-nowrap">
                    {{ formatUploadedAt(invoice.created_at) }}
                </TableCell>
                <!-- <TableCell>
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <Button aria-haspopup="true" size="icon" variant="ghost">
                <MoreHorizontal class="h-4 w-4" />
                <span class="sr-only">Toggle menu</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuLabel>Actions</DropdownMenuLabel>
              <DropdownMenuItem @click="openEditDialog(vendor)"
                >Assign a Twilio Number</DropdownMenuItem
              >
            </DropdownMenuContent>
          </DropdownMenu>
        </TableCell> -->
            </TableRow>
            <TableRow v-if="data.length === 0">
                <TableCell :colspan="isVendor ? 6 : 7"
                    >No invoices found!</TableCell
                >
            </TableRow>
        </TableBody>
    </Table>
</template>
