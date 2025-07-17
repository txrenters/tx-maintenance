<script setup>
import { Check } from "lucide-vue-next";
const emit = defineEmits(["isDialogOpen", "statusChanged"]);

defineProps({
    data: Object,
});

const updateStatus = (work_order) => {
    emit("statusChanged", work_order); // Emit event to parent
};
</script>
<template>
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead>Work Order No</TableHead>
                <TableHead class="hidden md:table-cell">Location</TableHead>
                <TableHead class="hidden md:table-cell">
                    Requested By
                </TableHead>
                <TableHead class="hidden md:table-cell">
                    Completed Date
                </TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="work_order in data" :key="work_order.id">
                <TableCell class="font-medium">
                    <Link 
                        :href="route('work_orders.details', work_order.id)"
                        class="text-blue-600 hover:text-blue-800 font-medium"
                    >
                        #{{ work_order.work_order_no }}
                    </Link>
                    <p class="text-xs font-normal md:hidden">
                        {{ work_order.location }}
                    </p>
                    <p class="text-xs font-normal md:hidden">
                        {{ work_order.requested_by }}
                    </p>
                    <p class="flex text-xs mt-1 md:hidden">
                        <Check class="w-4 h-4 text-green-600" />{{
                            work_order.completed_at
                        }}
                    </p>
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
                <TableCell>
                    <TableCell>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    aria-haspopup="true"
                                    size="icon"
                                    variant="ghost"
                                >
                                    <MoreHorizontal class="h-4 w-4" />
                                    <span class="sr-only">Toggle menu</span>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                <DropdownMenuItem
                                    v-if="
                                        $page.props.auth.user.roles.includes(
                                            'admin'
                                        ) ||
                                        $page.props.auth.user.roles.includes(
                                            'woc'
                                        )
                                    "
                                    @click="updateStatus(work_order.id)"
                                    class="cursor-pointer"
                                    >Re-Open</DropdownMenuItem
                                >
                                <DropdownMenuItem class="cursor-pointer">
                                    <Link
                                        :href="
                                            route(
                                                'work_orders.report',
                                                work_order.id
                                            )
                                        "
                                    >
                                        Report
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </TableCell>
                </TableCell>
            </TableRow>
            <TableRow v-if="data.length === 0">
                <TableCell colspan="6">No work order found!</TableCell>
            </TableRow>
        </TableBody>
    </Table>
</template>
