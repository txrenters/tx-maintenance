<script setup>
import { ref, computed } from "vue";
import { useFilter } from "reka-ui";
import { DateTime } from "luxon";
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

const props = defineProps({
  workOrder: Object,
  categories: Array,
  vendors: Array,
  isLoading: Boolean,
  closeWorkOrderForm: Object,
});

const emit = defineEmits(["save", "close", "emergencyChanged", "vendorChanged"]);

const open = ref(false);

const searchTerm = ref("");

const { contains } = useFilter({ sensitivity: "base" }); // this is use for vendors dropdown
const filteredVendors = computed(() => {
  const options = props.vendors.filter((i) => !props.workOrder.vendors.includes(i.name));
  return searchTerm.value
    ? options.filter((option) => contains(option.name, searchTerm.value))
    : options;
});
// In case of a range picker, you'll receive [Date, Date]
const format = (date) => {
  const day = date.getDate();
  const month = date.getMonth() + 1;
  const year = date.getFullYear();
  return `${month}/${day}/${year}`;
};
const formatDate = (date) => {
  if (!date) return "------";

  let parsedDate;

  if (typeof date === "string") {
    if (date.includes("T")) {
      // Handle ISO format (e.g., 2025-03-06T17:41:20.000000Z)
      parsedDate = DateTime.fromISO(date, { zone: "utc" });
    } else if (date.includes(":")) {
      // Handle non-ISO format with time (e.g., 2025-03-06 23:10:06)
      parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd HH:mm:ss", { zone: "utc" });
    } else {
      // Handle plain date format (e.g., 2025-03-24)
      parsedDate = DateTime.fromFormat(date, "yyyy-MM-dd", { zone: "utc" });
    }
  } else if (date instanceof Date) {
    // Handle JavaScript Date object
    parsedDate = DateTime.fromJSDate(date);
  } else if (typeof date === "number") {
    // Handle UNIX timestamp (e.g., 1672531200 or 1672531200000)
    parsedDate = DateTime.fromMillis(date > 1e12 ? date : date * 1000);
  } else {
    return "Invalid Date";
  }

  return parsedDate.isValid ? parsedDate.toFormat("MM/dd/yyyy") : "Invalid Date";
};

const handleUpdateSubmit = () => {
  emit("save");
};

const handleCloseOrderSubmit = () => {
  emit("close");
};

const updateEmergency = () => {
  emit("emergencyChanged"); // Emit event to parent
};

const vendorChange = () => {
  emit("vendorChanged"); // Emit event to parent
};

const totalCostEstimate = computed(() => {
  return props.workOrder?.vendors.reduce((total, vendor) => {
    return total + parseFloat(vendor.pivot?.cost_estimate || 0);
  }, 0);
});

const totalTimeEstimate = computed(() => {
  return props.workOrder?.vendors.reduce((total, vendor) => {
    return total + parseInt(vendor.pivot?.time_estimate || 0);
  }, 0);
});

const latestScheduledEndDate = computed(() => {
  return props.workOrder?.vendors.reduce((latestDate, vendor) => {
    const vendorDate = vendor.pivot?.scheduled_end_date
      ? new Date(vendor.pivot?.scheduled_end_date)
      : null;

    if (vendorDate && (!latestDate || vendorDate > latestDate)) {
      return vendorDate;
    }

    return latestDate;
  }, null);
});
</script>

<template>
  <div class="flex justify-center" v-if="isLoading">
    <Loader2 class="w-12 h-12 animate-spin text-primary" />
  </div>
  <div class="grid gap-3 overflow-y-auto px-6" v-else>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <Label for="message">Vendors:</Label>
        <Button
          size="small"
          class="text-xs p-1 ml-2 mb-1"
          title="Assign vendor"
          :disabled="isLoading"
          v-if="
            workOrder.local_status !== 'Updated' &&
            !$page.props.auth.user.roles.includes('vendor')
          "
          @click.prevent="vendorChange(workOrder.id)"
        >
          <Loader2 v-if="isLoading" class="w-4 h-4 animate-spin" />
          Assign vendor</Button
        >
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
          <ComboboxAnchor as-child>
            <TagsInput
              v-model="workOrder.vendors"
              class="px-2 py-2 gap-2 w-full"
              v-if="!$page.props.auth.user.roles.includes('vendor')"
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

            <ComboboxList class="w-[--reka-popper-anchor-width] h-32">
              <ComboboxEmpty />
              <ComboboxGroup>
                <ComboboxItem
                  v-for="vendor in vendors"
                  :key="vendor.id"
                  :value="vendor.name"
                  @select.prevent="
                    (ev) => {
                      if (typeof ev.detail.value === 'string') {
                        searchTerm = '';
                        workOrder.vendors.push(ev.detail.value);
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
      <div v-if="workOrder.is_emergency === null">
        <Label for="message">Emergency:</Label>
        <Select
          v-model="workOrder.is_emergency"
          @update:modelValue="updateEmergency"
          v-if="!$page.props.auth.user.roles.includes('vendor')"
        >
          <SelectTrigger class="w-full">
            <SelectValue placeholder="Select an emergency" />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <SelectItem value="Emergency"> Emergency </SelectItem>
              <SelectItem value="Non-emergency"> Non-emergency </SelectItem>
            </SelectGroup>
          </SelectContent>
        </Select>
        <p>{{ workOrder.is_emergency ?? "" }}</p>
      </div>
      <div>
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
              :src="workOrder?.managed_by?.user?.profile_photo_url || 'default.jpg'"
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
              :src="workOrder?.requested?.user?.profile_photo_url || 'default.jpg'"
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
  <DialogFooter class="p-6 pt-0" v-if="!$page.props.auth.user.roles.includes('vendor')">
    <Button
      type="submit"
      variant="destructive"
      :disabled="closeWorkOrderForm.processing"
      @click.prevent="handleCloseOrderSubmit"
    >
      <Loader2 v-if="closeWorkOrderForm.processing" class="w-4 h-4 animate-spin" />
      Close Work Order
    </Button>
    <Button
      type="submit"
      :disabled="workOrder.processing"
      @click.prevent="handleUpdateSubmit"
    >
      <Loader2 v-if="workOrder.processing" class="w-4 h-4 animate-spin" />
      Save changes
    </Button>
  </DialogFooter>
</template>
