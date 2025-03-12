<script setup>
const emit = defineEmits(["openDeleteDialog"]);

defineProps({
  data: Object,
});

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
          <p class="text-xs font-normal my-1 md:hidden">
            {{ template.current_service_status }}
          </p>
          <Badge
            class="md:hidden"
            :variant="template.is_emergency === 'Emergency' ? 'destructive' : 'default'"
          >
            {{ template.is_emergency }}</Badge
          >
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ template.description }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ template.current_service_status }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          <Badge
            :variant="template.is_emergency === 'Emergency' ? 'destructive' : 'default'"
          >
            {{ template.is_emergency }}</Badge
          >
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
              <DropdownMenuItem>
                <Link class="w-full" :href="route('task_templates.show', template.id)"
                  >View</Link
                >
              </DropdownMenuItem>
              <DropdownMenuItem>
                <Link class="w-full" :href="route('task_templates.edit', template.id)"
                  >Edit</Link
                >
              </DropdownMenuItem>
              <DropdownMenuItem @click="openDeleteDialog(template)"
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
