<script setup>
import { FileDownIcon } from "lucide-vue-next";

const emit = defineEmits(["openEditDialog", "openDeleteDialog"]);

defineProps({
    data: Object,
});

const openEditDialog = (user) => {
    emit("openEditDialog", true, user);
};

const openDeleteDialog = (user) => {
    emit("openDeleteDialog", true, user);
};
</script>
<template>
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>File</TableHead>
                <TableHead class="hidden md:table-cell"> Amount </TableHead>
                <TableHead class="hidden md:table-cell"> Status </TableHead>
                <TableHead class="md:table-cell"> Work Order </TableHead>
                <TableHead class="md:table-cell"> Vendor </TableHead>
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
                    {{ invoice.work_order_no }}
                </TableCell>
                <TableCell class="md:table-cell">
                    {{ invoice.vendor }}
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
                <TableCell colspan="5">No invoices found!</TableCell>
            </TableRow>
        </TableBody>
    </Table>
</template>
