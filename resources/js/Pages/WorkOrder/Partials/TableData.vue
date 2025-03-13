<script setup>
const emit = defineEmits(["isDialogOpen", "statusChanged"]);

defineProps({
  data: Object,
});

const openEditDialog = (user) => {
  emit("isDialogOpen", true, user);
};
const updateStatus = (checked, work_order) => {
  emit("statusChanged", checked, work_order); // Emit event to parent
};
</script>
<template>
  <Table>
    <TableHeader>
      <TableRow>
        <TableHead>Work Order No</TableHead>
        <TableHead class="hidden md:table-cell">Location</TableHead>
        <TableHead class="hidden md:table-cell"> Requested By </TableHead>
        <TableHead class="hidden md:table-cell"> Completed Date </TableHead>
        <TableHead class="hidden md:table-cell"> Status </TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="work_order in data" :key="work_order.id">
        <TableCell class="font-medium">
          {{ work_order.work_order_no }}
          <p class="text-xs font-normal md:hidden">{{ work_order.location }}</p>
          <p class="text-xs font-normal md:hidden">{{ work_order.requested_by }}</p>
          <Badge variant="outline" class="mt-2 md:hidden">
            {{ work_order.status }}
          </Badge>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ work_order.location }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ work_order.requested_by }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ work_order.completed_at }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          <div class="flex gap-2 items-center">
            Open
            <Switch
              class="bg-red-500"
              :checked="work_order.status"
              @update:checked="updateStatus($event, work_order.id)"
            />
            Close
          </div>
        </TableCell>
      </TableRow>
      <TableRow v-if="data.length === 0">
        <TableCell colspan="6">No work order found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
