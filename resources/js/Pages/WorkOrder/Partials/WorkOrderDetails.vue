<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import { useFilter } from "reka-ui";
import { DateTime } from "luxon";
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
import {
    TagsInput,
    TagsInputInput,
    TagsInputItem,
    TagsInputItemDelete,
    TagsInputItemText,
} from "@/Components/ui/tags-input";
import { Avatar, AvatarImage, AvatarFallback } from "@/Components/ui/avatar";
import axios from "axios";
const { toast } = useToast();

const props = defineProps({
    workOrder: Object,
    categories: Array,
    vendors: Array,
    isLoading: Boolean,
    closeWorkOrderForm: Object,
});

const emit = defineEmits(["save", "close", "delete", "update-workOrder"]);

const open = ref(false);

const searchTerm = ref("");

const { contains } = useFilter({ sensitivity: "base" }); // this is use for vendors dropdown
const filteredVendors = computed(() => {
    const options = props.vendors.filter(
        (i) => !props.workOrder.vendors.includes(i.name)
    );

    console.log(options);
    return searchTerm.value
        ? options.filter((option) => contains(option.name, searchTerm.value))
        : options;
});

const formatDate = (date) => {
    if (!date) return "------";

    let parsedDate;

    if (typeof date === "string") {
        if (date.includes("T")) {
            parsedDate = DateTime.fromISO(date, { zone: "utc" });
        } else if (date.includes(":")) {
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", {
                zone: "utc",
            });
        } else {
            parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd", {
                zone: "utc",
            });
        }
    } else if (date instanceof Date) {
        parsedDate = DateTime.fromJSDate(date);
    } else if (typeof date === "number") {
        parsedDate = DateTime.fromMillis(date > 1e12 ? date : date * 1000);
    } else {
        return "Invalid Date";
    }

    return parsedDate.isValid
        ? parsedDate.toFormat("MM/dd/yyyy")
        : "Invalid Date";
};

const handleUpdateSubmit = () => {
    emit("save");
};

const handleCloseOrderSubmit = () => {
    emit("close");
};

const loading = ref(false);

const handleEmergencySubmit = () => {
    router.put(
        route("work_orders.emergency.change", {
            workOrder: props.workOrder.id, // Ensure workOrder ID is included
            is_emergency: props.workOrder.is_emergency,
        }),
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description:
                        "Work order emergency has been changed successfully!",
                });

                emit("update-workOrder"); // Emit event to parent
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description:
                        "There was a problem with your request. Please try again!",
                });
            },
        }
    );
};

const loadingVendor = ref(false);
const handleVendorSubmit = () => {
    loadingVendor.value = true;
    router.put(
        route("work_orders.vendor.change", props.workOrder.id),
        {
            vendors: props.workOrder.vendors,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                toast({
                    title: "Success",
                    description: "Vendor has been set successfully!",
                });
                emit("update-workOrder"); // Emit event to parent
                loadingVendor.value = false;
            },
            onError: () => {
                toast({
                    variant: "destructive",
                    title: "Uh oh! Something went wrong.",
                    description:
                        "There was a problem with your request. Please try again!",
                });
                loadingVendor.value = false;
            },
        }
    );
};

const totalCostEstimate = computed(() => {
    const vendors = Array.isArray(props.workOrder?.vendors)
        ? props.workOrder.vendors
        : [];
    return vendors.reduce((total, vendor) => {
        return total + parseFloat(vendor.pivot?.cost_estimate || 0);
    }, 0);
});

const totalTimeEstimate = computed(() => {
    const vendors = Array.isArray(props.workOrder?.vendors)
        ? props.workOrder.vendors
        : [];
    return vendors.reduce((total, vendor) => {
        return total + parseInt(vendor.pivot?.time_estimate || 0);
    }, 0);
});

const latestScheduledEndDate = computed(() => {
    const vendors = Array.isArray(props.workOrder?.vendors)
        ? props.workOrder.vendors
        : [];

    return vendors.reduce((latestDate, vendor) => {
        const vendorDate = vendor.pivot?.scheduled_end_date
            ? new Date(vendor.pivot.scheduled_end_date)
            : null;

        if (vendorDate instanceof Date && !isNaN(vendorDate)) {
            return !latestDate || vendorDate > latestDate
                ? vendorDate
                : latestDate;
        }

        return latestDate;
    }, null);
});

const handleDeleteSubmit = () => {
    loading.value = true;

    axios
        .delete(route("work_orders.destroy", props.workOrder.id))
        .then((response) => {
            toast({
                title: "Success",
                description: response.data.message,
            });
            emit("delete"); // Emit event to parent
            loading.value = false;
        })
        .catch((error) => {
            toast({
                variant: "destructive",
                title: "Uh oh! Something went wrong.",
                description:
                    "There was a problem with your request. Please try again!",
            });
            loading.value = false;
        });
};
</script>

<template>
    <div class="flex justify-center" v-if="isLoading">
        <Loader2 class="w-12 h-12 animate-spin text-primary" />
    </div>
    <div class="grid gap-3 overflow-y-auto px-6" v-else>
        <div class="grid grid-cols-2 gap-3">
            <div
                v-if="
                    $page.props.auth.user.roles.includes('admin') ||
                    $page.props.auth.user.roles.includes('woc')
                "
            >
                <Label for="message">Vendors:</Label>
                <Button
                    size="small"
                    class="text-xs p-1 ml-2 mb-1"
                    title="Assign vendor"
                    :disabled="loading"
                    v-if="
                        (workOrder.local_status === 'Created' &&
                            $page.props.auth.user.roles.includes('admin')) ||
                        $page.props.auth.user.roles.includes('woc')
                    "
                    @click.prevent="handleVendorSubmit"
                >
                    <Loader2
                        v-if="loadingVendor"
                        class="w-4 h-4 animate-spin"
                    />

                    Assign vendor
                </Button>
                <template v-if="workOrder.local_status === 'Updated'">
                    <p v-for="vendor in workOrder.vendors" :key="vendor">
                        <span v-if="vendor.id"> {{ vendor.name }}</span>
                        <span v-else> {{ vendor }}</span>
                    </p>
                    <br />
                </template>

                <Combobox
                    v-model="workOrder.vendors"
                    v-model:open="open"
                    :ignore-filter="true"
                    v-else
                >
                    <ComboboxAnchor
                        as-child
                        class="py-2"
                        v-if="
                            $page.props.auth.user.roles.includes('admin') ||
                            $page.props.auth.user.roles.includes('woc')
                        "
                    >
                        <TagsInput
                            v-model="workOrder.vendors"
                            class="gap-2 w-full"
                            v-if="
                                !$page.props.auth.user.roles.includes('vendor')
                            "
                        >
                            <div class="flex gap-2 flex-wrap items-center">
                                <TagsInputItem
                                    v-for="vendor in workOrder.vendors"
                                    :key="vendor"
                                    :value="vendor"
                                >
                                    <TagsInputItemText />
                                    <TagsInputItemDelete />
                                </TagsInputItem>
                            </div>

                            <ComboboxInput v-model="searchTerm" as-child>
                                <TagsInputInput
                                    placeholder="Vendors..."
                                    class="min-w-[200px] w-full p-0 border-none focus-visible:ring-0 h-auto"
                                    @keydown.enter.prevent
                                />
                            </ComboboxInput>
                        </TagsInput>

                        <ComboboxList
                            class="w-[--reka-popper-anchor-width] h-44"
                        >
                            <ComboboxEmpty />
                            <ComboboxGroup>
                                <ComboboxItem
                                    v-for="vendor in filteredVendors"
                                    :key="vendor.id"
                                    :value="vendor.name"
                                    @select.prevent="
                                        (ev) => {
                                            if (
                                                typeof ev.detail.value ===
                                                'string'
                                            ) {
                                                searchTerm = '';
                                                workOrder.vendors.push(
                                                    ev.detail.value
                                                );
                                            }

                                            if (filteredVendors.length === 0) {
                                                open = false;
                                            }
                                        }
                                    "
                                >
                                    {{ vendor.name }}
                                </ComboboxItem>
                            </ComboboxGroup>
                        </ComboboxList>
                    </ComboboxAnchor>
                </Combobox>
            </div>
            <div v-else>
                <Label for="message">Vendors:</Label>
                <div
                    v-for="(vendor_name, index) in workOrder.vendors"
                    :key="index"
                >
                    <p>{{ vendor_name }}</p>
                </div>
            </div>
            <div
                v-if="
                    (workOrder.is_emergency === null &&
                        $page.props.auth.user.roles.includes('admin')) ||
                    $page.props.auth.user.roles.includes('woc')
                "
            >
                <Label for="message">Emergency:</Label>
                <Select
                    v-model="workOrder.is_emergency"
                    @update:modelValue="handleEmergencySubmit"
                    v-if="!$page.props.auth.user.roles.includes('vendor')"
                >
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="Select an emergency" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem value="Emergency">
                                Emergency
                            </SelectItem>
                            <SelectItem value="Non-emergency">
                                Non-emergency
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <p>{{ workOrder.is_emergency ?? "" }}</p>
            </div>
            <div>
                <Label for="message">Category:</Label>
                <p>{{ workOrder.category }}</p>
            </div>
            <div
                v-if="
                    $page.props.auth.user.roles.includes('admin') ||
                    $page.props.auth.user.roles.includes('woc')
                "
            >
                <Label for="message">Category:</Label>
                <Select
                    v-model="workOrder.category"
                    v-if="!$page.props.auth.user.roles.includes('vendor')"
                >
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="Select a category" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectItem
                                :value="category.name"
                                v-for="category in categories"
                                :key="category.name"
                            >
                                {{ category.name }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <p v-else>{{ workOrder.category }}</p>
            </div>

            <div>
                <Label for="message">Manage by:</Label>
                <div class="flex gap-2 items-center">
                    <Avatar class="w-5 h-5" v-if="workOrder?.managed_by">
                        <AvatarImage
                            :src="
                                workOrder?.managed_by?.user
                                    ?.profile_photo_url || 'default.jpg'
                            "
                        />
                        <AvatarFallback>
                            {{ workOrder.managed_by?.first_name?.charAt(0)
                            }}{{ workOrder.managed_by?.last_name?.charAt(0) }}
                        </AvatarFallback>
                    </Avatar>
                    <p>
                        {{ workOrder.managed_by?.first_name }}
                        {{ workOrder.managed_by?.last_name }}
                    </p>
                </div>
            </div>

            <div>
                <Label for="message">Location:</Label>
                <p>{{ workOrder.location }}</p>
            </div>

            <div>
                <Label for="message">Requested by:</Label>
                <div class="flex gap-2 items-center">
                    <Avatar class="w-5 h-5" v-if="workOrder?.requested">
                        <AvatarImage
                            :src="
                                workOrder?.requested?.user?.profile_photo_url ||
                                'default.jpg'
                            "
                        />
                        <AvatarFallback>
                            {{ workOrder.requested?.first_name?.charAt(0)
                            }}{{ workOrder.requested?.last_name?.charAt(0) }}
                        </AvatarFallback>
                    </Avatar>
                    <p>
                        {{ workOrder.requested?.first_name }}
                        {{ workOrder.requested?.last_name }}
                    </p>
                </div>
            </div>
            <div>
                <Label for="message">Type:</Label>
                <p>{{ workOrder.type }}</p>
            </div>
            <div>
                <Label for="message">Service Status:</Label>
                <p>{{ workOrder.service_status }}</p>
            </div>
            <div>
                <Label for="message">Authorized to enter:</Label>
                <p>{{ workOrder.authorized_to_enter }}</p>
            </div>
            <div>
                <Label for="message">Source:</Label>
                <p>{{ workOrder.source }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <Label for="message">Total Cost:</Label>
                <p>${{ workOrder.total_cost }}</p>
            </div>
            <div>
                <Label for="message">Total Hour Worked:</Label>
                <p>{{ workOrder.total_hour_work }}</p>
            </div>

            <div>
                <Label for="message">Estimated cost: </Label>
                <p>${{ totalCostEstimate }}</p>
            </div>

            <div>
                <Label for="message">Estimated Time (Hrs) :</Label>
                <p>{{ totalTimeEstimate }}</p>
            </div>
        </div>

        <div class="work_order_details">
            <div class="grid grid-cols-2 gap-4 items-center">
                <div>
                    <Label for="message">Created Date:</Label>
                    <p>{{ formatDate(workOrder.created_date) }}</p>
                </div>
                <div>
                    <Label for="message">Scheduled End Date:</Label>
                    <p>{{ formatDate(latestScheduledEndDate) }}</p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 items-center mt-5">
                <div>
                    <Label for="message">Approval Date:</Label>
                    <p>{{ formatDate(workOrder.approved_date) }}</p>
                </div>
                <div>
                    <Label for="message">Approval Comments:</Label>
                    <p>{{ workOrder.approval_comments ?? "-------" }}</p>
                </div>
            </div>
            <div class="grid gap-1.5 mt-5">
                <Label for="message">Zone:</Label>
                <Input
                    class="mt-1"
                    v-model="workOrder.zone"
                    :disabled="$page.props.auth.user.roles.includes('vendor')"
                />
            </div>

            <div class="grid gap-1.5 mt-5">
                <Label for="message">Management Plan</Label>
                <Textarea
                    placeholder="Type your message here."
                    v-model="workOrder.management_plan"
                    :disabled="$page.props.auth.user.roles.includes('vendor')"
                />
            </div>
            <div class="grid gap-1.5 mt-5">
                <Label for="message">Additional Work Needed </Label>
                <Textarea
                    placeholder="Type your message here."
                    rows="1"
                    :disabled="$page.props.auth.user.roles.includes('vendor')"
                    v-model="workOrder.additional_work_needed_reschedule"
                />
            </div>
            <div class="grid gap-1.5 mt-5">
                <Label for="message">Closing Comments</Label>
                <Textarea
                    placeholder="Type your message here."
                    v-model="workOrder.closing_comments"
                    rows="1"
                    :disabled="$page.props.auth.user.roles.includes('vendor')"
                />
            </div>
            <div class="grid gap-1.5 mt-5">
                <Label for="message">Latest Update Comments</Label>
                <Textarea
                    placeholder="Type your message here."
                    v-model="workOrder.latest_update_comments"
                    rows="1"
                />
            </div>
            <div class="grid gap-1.5 mt-5 pb-12">
                <Label>Description:</Label>
                <Textarea
                    placeholder="Type your message here."
                    v-model="workOrder.description"
                    rows="1"
                    :disabled="$page.props.auth.user.roles.includes('vendor')"
                />
            </div>
        </div>
    </div>
    <DialogFooter
        v-if="
            $page.props.auth.user.roles.includes('admin') ||
            $page.props.auth.user.roles.includes('woc')
        "
    >
        <div class="flex gap-2 justify-between w-full p-6">
            <div class="flex gap-2">
                <Button
                    type="submit"
                    variant="destructive"
                    :disabled="loading"
                    @click.prevent="handleDeleteSubmit"
                >
                    <Loader2 v-if="loading" class="w-4 h-4 animate-spin" />
                    Delete
                </Button>
                <Button
                    type="submit"
                    :disabled="closeWorkOrderForm.processing"
                    @click.prevent="handleCloseOrderSubmit"
                >
                    <Loader2
                        v-if="closeWorkOrderForm.processing"
                        class="w-4 h-4 animate-spin"
                    />
                    Completed
                </Button>
            </div>
            <Button
                type="submit"
                :disabled="workOrder.processing"
                @click.prevent="handleUpdateSubmit"
                class="text-white bg-green-400 flex justify-end"
            >
                <Loader2
                    v-if="workOrder.processing"
                    class="w-4 h-4 animate-spin"
                />
                Save Changes
            </Button>
        </div>
    </DialogFooter>
</template>
