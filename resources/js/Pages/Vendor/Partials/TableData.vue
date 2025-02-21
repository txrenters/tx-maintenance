<script setup>
import { MoreHorizontal } from "lucide-vue-next";
import { ref } from "vue";

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
        <TableHead class="hidden md:table-cell">Type</TableHead>
        <TableHead class="hidden md:table-cell"> Twilio Number </TableHead>
        <TableHead class="hidden md:table-cell"> Status </TableHead>
        <TableHead class="hidden md:table-cell"> Address </TableHead>
        <TableHead>
          <span class="sr-only">Actions</span>
        </TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="vendor in data" :key="vendor.id">
        <TableCell class="font-medium">
          {{ vendor.name }}
          <p class="text-xs font-normal">{{ vendor.email }}</p>
          <p class="text-xs font-normal">{{ vendor.phone }}</p>
          <Badge variant="outline" class="mt-2 md:hidden">
            {{ vendor.vendor_type }}
          </Badge>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          <Badge variant="outline"> {{ vendor.vendor_type }} </Badge>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ vendor.twilio_number }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          <Switch :checked="vendor.status" @update:checked="vendor.status = $event" />
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ vendor.address }}
        </TableCell>
        <TableCell>
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
        </TableCell>
      </TableRow>
      <TableRow v-if="data.length === 0">
        <TableCell colspan="3">No vendors found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
