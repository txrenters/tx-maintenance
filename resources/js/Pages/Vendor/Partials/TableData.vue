<script setup>
import { Phone, SquarePen } from "lucide-vue-next";
const emit = defineEmits(["isDialogOpen", "statusChanged"]);

defineProps({
  data: Object,
});

const openEditDialog = (user) => {
  emit("isDialogOpen", true, user);
};
const updateStatus = (checked, vendor) => {
  emit("statusChanged", checked, vendor); // Emit event to parent
};
</script>
<template>
  <Table>
    <TableHeader>
      <TableRow>
        <TableHead>Name</TableHead>
        <TableHead class="hidden md:table-cell">Contact Name</TableHead>
        <TableHead class="hidden md:table-cell">Phone Number </TableHead>
        <TableHead class="hidden md:table-cell">Twilio Number </TableHead>
        <TableHead class="hidden md:table-cell">Status </TableHead>
        <TableHead class="hidden md:table-cell">Address </TableHead>
        <TableHead>
          <span class="sr-only">Actions</span>
        </TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="vendor in data" :key="vendor.id">
        <TableCell class="font-medium">
          {{ vendor.name }}
          <p class="text-xs font-normal table-cell md:hidden">{{ vendor.email }}</p>
          <p class="text-xs font-normal table-cell md:hidden">{{ vendor.phone }}</p>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ vendor.contact_name }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ vendor.phone }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ vendor.twilio_number }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          <Switch
            :checked="vendor.status"
            @update:checked="updateStatus($event, vendor.id)"
          />
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ vendor.address }}
        </TableCell>
        <TableCell>
          <div class="flex gap-3">
            <Button
              variant="link"
              class="hover:text-primary p-0"
              @click="openEditDialog(vendor)"
              ><SquarePen
            /></Button>
          </div>
        </TableCell>
      </TableRow>
      <TableRow v-if="data.length === 0">
        <TableCell colspan="6">No vendors found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
