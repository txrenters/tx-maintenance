<script setup>
const emit = defineEmits(["openEditDialog", "openDeleteDialog"]);

defineProps({
  data: Object,
});

const openEditDialog = (data) => {
  emit("openEditDialog", true, data);
};

const openDeleteDialog = (data) => {
  emit("openDeleteDialog", true, data);
};
</script>
<template>
  <Table>
    <TableHeader>
      <TableRow>
        <TableHead>Name</TableHead>
        <TableHead class="hidden md:table-cell">Description</TableHead>
        <TableHead>
          <span class="sr-only">Actions</span>
        </TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="status in data" :key="status.id">
        <TableCell class="font-medium">
          {{ status.name }}
        </TableCell>
        <TableCell class="hidden md:table-cell"> {{ status.description }} </TableCell>
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
              <DropdownMenuItem @click="openEditDialog(status)">Edit</DropdownMenuItem>
              <DropdownMenuItem @click="openDeleteDialog(status)"
                >Delete</DropdownMenuItem
              >
            </DropdownMenuContent>
          </DropdownMenu>
        </TableCell>
      </TableRow>
      <TableRow v-if="data.length === 0">
        <TableCell colspan="3">No service status found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
