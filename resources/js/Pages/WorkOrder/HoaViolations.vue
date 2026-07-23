<script setup>
import { ref, computed } from "vue";
import AppLayout from "@/Layouts/AppLayout.vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "@/Components/ui/toast/use-toast";
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from "@/Components/ui/combobox";
import { Search, Upload, FileUp } from "lucide-vue-next";

const { toast } = useToast();

defineOptions({ layout: AppLayout });

const props = defineProps({
    workOrders: Array,
    buildings: Array,
});

const openModal = ref(false);
const searchQuery = ref("");
const selectedBuilding = ref(null);
const fileInput = ref(null);

const uploadForm = useForm({
    building_id: null,
    file: null,
    hoa_notice_date: "",
});

const filteredBuildings = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    if (!query) {
        return props.buildings.slice(0, 50);
    }

    return props.buildings
        .filter((building) => building.name?.toLowerCase().includes(query))
        .slice(0, 50);
});

const onFileChange = (event) => {
    uploadForm.file = event.target.files[0] ?? null;
};

const submit = () => {
    uploadForm.building_id = selectedBuilding.value?.id ?? null;

    uploadForm.post(route("work_orders.hoa.store"), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            toast({
                title: "HOA notice processed",
                description:
                    "The work order is set to Tenant Easy Fix and the tenant has been sent their link.",
            });
            openModal.value = false;
            uploadForm.reset();
            selectedBuilding.value = null;
            searchQuery.value = "";
            if (fileInput.value) fileInput.value.value = "";
        },
        onError: () => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "The HOA notice could not be processed. Please try again!",
            });
        },
    });
};

const stateBadge = (workOrder) => {
    if (workOrder.confirmation_sent) return { label: "Confirmed", class: "bg-green-100 text-green-800" };
    if (workOrder.completed) return { label: "Completed", class: "bg-green-100 text-green-800" };
    if (workOrder.overdue) return { label: "Overdue — needs vendor", class: "bg-red-100 text-red-800" };
    if (workOrder.deadline) return { label: "Waiting on tenant", class: "bg-yellow-100 text-yellow-800" };
    return { label: "No tenant link", class: "bg-gray-100 text-gray-800" };
};
</script>
<template>
    <Head title="HOA Violations" />
    <Card>
        <CardHeader
            class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
        >
            <div>
                <CardTitle>HOA Violations</CardTitle>
                <CardDescription class="mt-1">
                    Upload an HOA violation notice — the work order, tenant
                    link, and reminders are handled automatically.
                </CardDescription>
            </div>
            <Button @click="openModal = true">
                <Upload class="w-4 h-4 mr-2" />
                Upload HOA Notice
            </Button>
        </CardHeader>
        <CardContent>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Work Order</TableHead>
                        <TableHead class="hidden md:table-cell">Property</TableHead>
                        <TableHead class="hidden lg:table-cell">Violation</TableHead>
                        <TableHead class="hidden md:table-cell">Notice Date</TableHead>
                        <TableHead>Deadline</TableHead>
                        <TableHead>State</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="workOrder in workOrders"
                        :key="workOrder.id"
                    >
                        <TableCell class="font-medium">
                            <Link
                                v-if="workOrder.work_order_no"
                                :href="route('work_orders.details', workOrder.id)"
                                class="text-blue-600 hover:text-blue-800 font-medium"
                            >
                                #{{ workOrder.work_order_no }}
                            </Link>
                            <span v-else class="text-muted-foreground">
                                Local #{{ workOrder.id }}
                            </span>
                            <p class="text-xs font-normal md:hidden mt-1">
                                {{ workOrder.property }}
                            </p>
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{ workOrder.property }}
                        </TableCell>
                        <TableCell class="hidden lg:table-cell max-w-md">
                            <p class="truncate" :title="workOrder.description">
                                {{ workOrder.description }}
                            </p>
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{ workOrder.notice_date ?? "—" }}
                        </TableCell>
                        <TableCell>
                            {{ workOrder.deadline ?? "—" }}
                        </TableCell>
                        <TableCell>
                            <span
                                class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium whitespace-nowrap"
                                :class="stateBadge(workOrder).class"
                            >
                                {{ stateBadge(workOrder).label }}
                            </span>
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="workOrders.length === 0">
                        <TableCell colspan="6">
                            No HOA violations yet — upload a notice to get
                            started.
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
    </Card>

    <Dialog v-model:open="openModal">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Upload HOA Violation Notice</DialogTitle>
                <DialogDescription>
                    Select the property and upload the notice PDF. The work
                    order is created automatically with the violation details.
                </DialogDescription>
            </DialogHeader>

            <div class="my-2 space-y-4">
                <div>
                    <Label>Property</Label>
                    <Combobox v-model="selectedBuilding" by="id" class="mt-2">
                        <ComboboxAnchor class="w-full">
                            <div
                                class="relative flex w-full items-center border rounded-md"
                            >
                                <Search
                                    class="absolute left-2 h-4 w-4 text-muted-foreground"
                                />
                                <ComboboxInput
                                    class="w-full pl-8 pr-2 py-1 text-sm"
                                    :display-value="(val) => val?.name ?? ''"
                                    :model-value="searchQuery"
                                    @update:model-value="searchQuery = $event"
                                    placeholder="Search properties..."
                                />
                            </div>
                        </ComboboxAnchor>
                        <ComboboxList class="w-full max-h-60 overflow-y-auto">
                            <ComboboxEmpty>No property found.</ComboboxEmpty>
                            <ComboboxGroup>
                                <ComboboxItem
                                    v-for="building in filteredBuildings"
                                    :key="building.id"
                                    :value="building"
                                >
                                    {{ building.name }}
                                </ComboboxItem>
                            </ComboboxGroup>
                        </ComboboxList>
                    </Combobox>
                    <span class="text-xs text-destructive">{{
                        uploadForm.errors.building_id
                    }}</span>
                </div>

                <div>
                    <Label for="hoa-file">HOA Notice (PDF)</Label>
                    <div class="mt-2">
                        <input
                            id="hoa-file"
                            ref="fileInput"
                            type="file"
                            accept="application/pdf"
                            class="block w-full text-sm text-muted-foreground file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                            @change="onFileChange"
                        />
                    </div>
                    <span class="text-xs text-destructive">{{
                        uploadForm.errors.file
                    }}</span>
                </div>

                <div>
                    <Label for="hoa-notice-date">
                        Notice date
                        <span class="text-muted-foreground font-normal">
                            (optional — read from the notice when blank)
                        </span>
                    </Label>
                    <Input
                        id="hoa-notice-date"
                        type="date"
                        class="mt-2"
                        v-model="uploadForm.hoa_notice_date"
                    />
                    <span class="text-xs text-destructive">{{
                        uploadForm.errors.hoa_notice_date
                    }}</span>
                </div>
            </div>

            <DialogFooter>
                <Button
                    type="submit"
                    :disabled="
                        uploadForm.processing ||
                        !selectedBuilding ||
                        !uploadForm.file
                    "
                    @click.prevent="submit"
                >
                    <Loader2
                        v-if="uploadForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    <FileUp v-else class="w-4 h-4 mr-2" />
                    <span v-if="uploadForm.processing">Processing…</span>
                    <span v-else>Create Work Order</span>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
