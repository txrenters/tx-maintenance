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
        <TableHead class="hidden md:table-cell"> Address </TableHead>
        <!-- <TableHead>
          <span class="sr-only">Actions</span>
        </TableHead> -->
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="tenant in data" :key="tenant.id">
        <TableCell class="font-medium">
          {{ tenant.name }}
          <p class="text-xs font-normal">{{ tenant.email }}</p>
          <p class="text-xs font-normal md:hidden">{{ tenant.mobile_phone }}</p>
          <p class="text-xs font-normal md:hidden">{{ tenant.home_phone }}</p>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ tenant.mobile_phone }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ tenant.home_phone }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ tenant.address }}
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
        <TableCell colspan="4">No tenants found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
