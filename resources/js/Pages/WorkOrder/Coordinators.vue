<script setup>
import { ref } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import { UserRoundPen } from "lucide-vue-next";
const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
    title: String,
    work_orders: Object,
    coordinators: Object,
    filter: Object,
});

const url = ref(route("work_orders.coordinators"));
const search = ref(props.filter.search);

const openModal = ref(false);
const changeCoordinatorForm = useForm({
    id: 0,
    coordinator_id: 0,
    work_order_no: 0,
});

const setCoordinator = (workOrder) => {
    changeCoordinatorForm.id = workOrder.id;
    changeCoordinatorForm.coordinator_id = workOrder.coordinator_id;
    changeCoordinatorForm.work_order_no = workOrder.work_order_no;
    openModal.value = true;
};

const updateCoordinator = () => {
    changeCoordinatorForm.patch(
        route("work_orders.coordinators.change", changeCoordinatorForm.id),
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Work order coordinator has been changed!",
                });
                openModal.value = false;
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description:
                        "There was a problem with your request. Please try again!",
                });
            },
            only: ["work_orders"],
        }
    );
};
</script>
<template>
    <Head :title="title" />
    <Card>
        <CardHeader>
            <SearchBar :url="url" v-model="search" />
        </CardHeader>
        <CardContent>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Work Order No</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Location</TableHead
                        >
                        <TableHead class="hidden md:table-cell">
                            Created Date
                        </TableHead>
                        <TableHead class="hidden md:table-cell">
                            Coordinator
                        </TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="work_order in work_orders.data"
                        :key="work_order.id"
                    >
                        <TableCell class="font-medium">
                            #{{ work_order.work_order_no }}
                            <p class="text-xs font-normal md:hidden">
                                {{ work_order.location }}
                            </p>
                            <p class="flex text-xs mt-1 md:hidden">
                                {{ work_order.created_date }}
                            </p>
                            <p class="text-xs font-normal md:hidden">
                                {{ work_order.coordinator }}
                            </p>
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{ work_order.location }}
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{ work_order.created_date }}
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{ work_order.coordinator }}
                        </TableCell>
                        <TableCell>
                            <Button
                                variant="link"
                                @click.prevent="setCoordinator(work_order)"
                                v-if="
                                    $page.props.auth.user.roles.includes(
                                        'admin'
                                    ) ||
                                    $page.props.auth.user.roles.includes('woc')
                                "
                                ><UserRoundPen
                            /></Button>
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="work_orders.length === 0">
                        <TableCell colspan="6">No work order found!</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
        <CardFooter
            class="border-t px-6 py-4 flex flex-col sm:flex-row justify-between items-center sm:items-start gap-3"
        >
            <PaginationResultRange :data="work_orders" />
            <Pagination :pagination="work_orders.links" />
        </CardFooter>
    </Card>

    <Dialog v-model:open="openModal">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Change Coordinator</DialogTitle>
                <DialogDescription>
                    Select coordintor name and assign to a work order.
                </DialogDescription>
            </DialogHeader>

            <div class="my-2">
                <div>
                    <Label for="name">Work Order No:</Label>
                    <p
                        class="font-bold text-xl"
                        v-text="changeCoordinatorForm.work_order_no"
                    />
                </div>
                <div class="mt-4">
                    <Label for="name">Coordinator Name</Label>
                    <Select
                        class="mt-2"
                        v-model="changeCoordinatorForm.coordinator_id"
                    >
                        <SelectTrigger>
                            <SelectValue
                                placeholder="Select a work order coordinator"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                <SelectLabel
                                    >Work order coordinators</SelectLabel
                                >
                                <SelectItem
                                    v-for="user in coordinators"
                                    :value="user.id"
                                    :key="user.id"
                                >
                                    {{ user.name }}
                                </SelectItem>
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                    <span class="text-xs text-destructive">{{
                        changeCoordinatorForm.errors.coordinator_id
                    }}</span>
                </div>
            </div>

            <DialogFooter>
                <Button
                    type="submit"
                    :disabled="changeCoordinatorForm.processing"
                    @click.prevent="updateCoordinator"
                >
                    <Loader2
                        v-if="changeCoordinatorForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    <div>
                        <span v-if="changeCoordinatorForm.processing">
                            Updating...
                        </span>
                        <span v-else>Update</span>
                    </div>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
