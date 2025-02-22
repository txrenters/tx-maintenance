<script setup>
const emit = defineEmits(["openEditDialog", "openDeleteDialog"]);

defineProps({
  userData: Object,
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
        <TableHead class="hidden w-[100px] sm:table-cell">
          <span class="sr-only">img</span>
        </TableHead>
        <TableHead>Name</TableHead>
        <TableHead class="hidden md:table-cell">Role</TableHead>
        <TableHead class="hidden md:table-cell"> Company </TableHead>
        <TableHead class="hidden md:table-cell"> Website </TableHead>
        <TableHead class="hidden md:table-cell"> Address </TableHead>
        <TableHead>
          <span class="sr-only">Actions</span>
        </TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      <TableRow v-for="user in userData" :key="user.id">
        <TableCell class="hidden sm:table-cell">
          <img
            class="aspect-square rounded-md object-cover"
            height="54"
            :src="user.profile_photo_url"
            width="54"
          />
        </TableCell>
        <TableCell class="font-medium">
          {{ user.name }}
          <p class="text-xs font-normal">{{ user.email }}</p>
          <p class="text-xs font-normal">{{ user.phone }}</p>
          <Badge variant="outline" class="mt-2 md:hidden"> {{ user.role }} </Badge>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          <Badge variant="outline"> {{ user.role }} </Badge>
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ user.company }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ user.website }}
        </TableCell>
        <TableCell class="hidden md:table-cell">
          {{ user.address }}
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
              <DropdownMenuItem @click="openEditDialog(user)">Edit</DropdownMenuItem>
              <DropdownMenuItem @click="openDeleteDialog(user)">Delete</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </TableCell>
      </TableRow>
      <TableRow v-if="userData.length === 0">
        <TableCell colspan="3">No user found!</TableCell>
      </TableRow>
    </TableBody>
  </Table>
</template>
