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
        <TableHead class="hidden md:table-cell"> Description </TableHead>
        <TableHead class="hidden md:table-cell"> Service Status </TableHead>
        <TableHead class="hidden md:table-cell"> Is Emergency </TableHead>
        <TableHead>
          <span class="sr-only">Actions</span>
        </TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="template in data" :key="template.id">
        <TableCell class="font-medium">
          {{ template.name }}
          <p class="text-xs font-normal mt-1">{{ tenant.current_service_status }}</p>
          <p class="text-xs font-normal mt-1">{{ tenant.is_emergency }}</p>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ tenant.description }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ tenant.current_service_status }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ tenant.is_emergency }}
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
              <DropdownMenuItem @click="openEditDialog(vendor)">Edit</DropdownMenuItem>
              <DropdownMenuItem @click="openDeleteDialog(vendor)"
                >Delete</DropdownMenuItem
              >
            </DropdownMenuContent>
          </DropdownMenu>
        </TableCell>
      </TableRow>
      <TableRow v-if="data.length === 0">
        <TableCell colspan="4">No templates found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
