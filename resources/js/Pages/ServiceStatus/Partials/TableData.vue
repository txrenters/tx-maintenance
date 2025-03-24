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
          <div class="flex gap-3">
            <Button
              variant="link"
              class="hover:text-primary p-0"
              @click="openEditDialog(status)"
              ><SquarePen
            /></Button>
            <Button
              variant="link"
              class="hover:text-red-500 p-0"
              @click="openDeleteDialog(status)"
              ><Trash2
            /></Button>
          </div>
        </TableCell>
      </TableRow>
      <TableRow v-if="data.length === 0">
        <TableCell colspan="3">No service status found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
