<script setup>
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
        <TableHead class="hidden md:table-cell"> Mobile </TableHead>
        <TableHead class="hidden md:table-cell"> Phone </TableHead>
        <TableHead class="hidden md:table-cell"> Status </TableHead>
        <TableHead class="hidden md:table-cell"> Address </TableHead>
        <!-- <TableHead>
          <span class="sr-only">Actions</span>
        </TableHead> -->
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="owner in data" :key="owner.id">
        <TableCell class="font-medium">
          {{ owner.name }}
          <p class="text-xs font-normal">{{ owner.email }}</p>
          <p class="text-xs font-normal">{{ owner.mobile }}</p>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ owner.mobile }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ owner.phone }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ owner.status }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ owner.address }}
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
        <TableCell colspan="5">No owners found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
