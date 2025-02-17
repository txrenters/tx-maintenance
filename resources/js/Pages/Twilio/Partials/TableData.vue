<script setup>
import { MoreHorizontal } from "lucide-vue-next";
import { ref } from "vue";

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
        <TableHead class="hidden md:table-cell">Features</TableHead>
        <TableHead class="hidden md:table-cell"> Status </TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="phone in data" :key="phone.id">
        <TableCell class="font-medium">
          {{ phone.name }}
          <p class="text-xs font-normal">{{ phone.phone_number }}</p>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          <div v-for="(value, key) in phone.capabilities" :key="key">
            <p>{{ key }} : {{ value }}</p>
          </div>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          <Badge variant="outline">
            {{ phone.twilio_status }}
          </Badge>
        </TableCell>
      </TableRow>
      <TableRow v-if="data.length === 0">
        <TableCell colspan="3">No twilio phone numbers found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
