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
        <TableHead class="hidden md:table-cell">Twilio Phone Number</TableHead>
        <TableHead>
          <span class="sr-only">Actions</span>
        </TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="woc in data" :key="woc.id">
        <TableCell class="font-medium">
          {{ woc.woc_name }}
          <p class="text-sm font-light md:hidden">{{ woc.twilio_phone_number }}</p>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ woc.twilio_phone_number }}
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
              <DropdownMenuItem @click="openEditDialog(woc)">Edit</DropdownMenuItem>
              <DropdownMenuItem @click="openDeleteDialog(woc)">Delete</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </TableCell>
      </TableRow>
      <TableRow v-if="data.length === 0">
        <TableCell colspan="3">No WOC phone number found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
