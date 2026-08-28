<script setup>
import { CheckCircle, Sparkles, AlertCircle } from "lucide-vue-next";
import { ref } from "vue";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";
import { useToast } from "@/Components/ui/toast/use-toast";
import { FIREPLACE_OPTIONS } from "../fireplaceOptions";

const form = defineModel("form");
const emit = defineEmits(["sectionComplete"]);
const props = defineProps({
    completedSections: Array,
});

const { toast } = useToast();
const validationErrors = ref([]);

const validateAmenities = () => {
    const errors = [];

    // Swimming Pool
    if (!form.value.swimmingPool || form.value.swimmingPool.trim() === "") {
        errors.push("Swimming Pool selection is required");
    }

    // Alarm System
    if (!form.value.alarmSystem || form.value.alarmSystem.trim() === "") {
        errors.push("Alarm System selection is required");
    }

    // Neighborhood Amenities
    if (!form.value.communityPool || form.value.communityPool.trim() === "") {
        errors.push("Community Pool selection is required");
    }
    if (!form.value.park || form.value.park.trim() === "") {
        errors.push("Nearby Park selection is required");
    }
    if (!form.value.playGround || form.value.playGround.trim() === "") {
        errors.push("Playground selection is required");
    }
    if (!form.value.tennisCourt || form.value.tennisCourt.trim() === "") {
        errors.push("Tennis Court selection is required");
    }
    if (
        !form.value.tenantToContactNeighborhoodAmenities ||
        form.value.tenantToContactNeighborhoodAmenities.trim() === ""
    ) {
        errors.push("Contact for Neighborhood Amenities is required");
    }

    // Gate Access
    if (
        !form.value.gatedCommunity ||
        form.value.gatedCommunity.trim() === ""
    ) {
        errors.push("Gated property selection is required");
    }
    if (
        form.value.gatedCommunity === "Yes" &&
        (!form.value.gateCode || form.value.gateCode.trim() === "")
    ) {
        errors.push("Gate Code is required when the property is gated");
    }

    // Garage Access & Mailbox
    if (
        !form.value.garageDoorOpener ||
        form.value.garageDoorOpener.trim() === ""
    ) {
        errors.push("Garage Door Opener selection is required");
    }
    if (
        !form.value.garageDoorRemote ||
        form.value.garageDoorRemote.toString().trim() === ""
    ) {
        errors.push("Number of Garage Remotes is required");
    }
    if (!form.value.lockboxCode || form.value.lockboxCode.trim() === "") {
        errors.push("Lockbox Code is required");
    }
    if (
        !form.value.mailboxKeyNo ||
        form.value.mailboxKeyNo.toString().trim() === ""
    ) {
        errors.push("Number of Mailbox Keys is required");
    }
    if (
        !form.value.mailboxLocation ||
        form.value.mailboxLocation.trim() === ""
    ) {
        errors.push("Mailbox Location is required");
    }

    // Appliances
    if (!form.value.refrigerator || form.value.refrigerator.trim() === "") {
        errors.push("Refrigerator Included selection is required");
    }
    if (!form.value.microwave || form.value.microwave.trim() === "") {
        errors.push("Microwave Included selection is required");
    }
    if (!form.value.washingMachine || form.value.washingMachine.trim() === "") {
        errors.push("Washing Machine Included selection is required");
    }
    if (
        !form.value.washingMachineHookups ||
        form.value.washingMachineHookups.trim() === ""
    ) {
        errors.push("Washing Machine Hookups selection is required");
    }
    if (!form.value.dryer || form.value.dryer.trim() === "") {
        errors.push("Dryer Included selection is required");
    }
    if (!form.value.dryerHookups || form.value.dryerHookups.trim() === "") {
        errors.push("Dryer Hookups selection is required");
    }
    if (!form.value.waterSoftener || form.value.waterSoftener.trim() === "") {
        errors.push("Water Softener selection is required");
    }
    if (
        !form.value.waterHeaterModelYear ||
        form.value.waterHeaterModelYear.trim() === ""
    ) {
        errors.push("Water Heater Model Year is required");
    }
    if (
        !form.value.dishWasherModelYear ||
        form.value.dishWasherModelYear.trim() === ""
    ) {
        errors.push("Dishwasher Model Year is required");
    }
    if (!form.value.hvacModelYear || form.value.hvacModelYear.trim() === "") {
        errors.push("HVAC Model Year is required");
    }

    // Utilities & Services
    if (!form.value.waterProvider || form.value.waterProvider.trim() === "") {
        errors.push("Water Provider is required");
    }
    if (!form.value.gasProvider || form.value.gasProvider.trim() === "") {
        errors.push("Gas Provider is required");
    }
    if (!form.value.trashProvider || form.value.trashProvider.trim() === "") {
        errors.push("Trash Provider is required");
    }
    if (
        !form.value.trashPickupDays ||
        form.value.trashPickupDays.trim() === ""
    ) {
        errors.push("Trash Pickup Days is required");
    }

    // Sprinkler / Irrigation System
    if (
        !form.value.sprinklerSystem ||
        form.value.sprinklerSystem.trim() === ""
    ) {
        errors.push("Sprinkler / Irrigation System selection is required");
    }
    if (
        form.value.sprinklerSystem === "Yes" &&
        (!form.value.sprinklerControllerLocation ||
            form.value.sprinklerControllerLocation.trim() === "")
    ) {
        errors.push(
            "Sprinkler Controller Location is required when there is a sprinkler system",
        );
    }

    // Fireplace
    if (!form.value.fireplace || form.value.fireplace.trim() === "") {
        errors.push("Fireplace selection is required");
    }

    validationErrors.value = errors;
    return errors.length === 0;
};

const markSectionCompleted = (value) => {
    if (!validateAmenities()) {
        toast({
            variant: "destructive",
            title: "Validation Error",
            description:
                "Please fill in all required fields before completing this section.",
        });
        return;
    }
    validationErrors.value = [];
    emit("sectionComplete", value);
};
</script>
<template>
    <section id="amenities">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-indigo-100 rounded-lg">
                        <Sparkles class="h-6 w-6 text-indigo-600" />
                    </div>
                    <div>
                        <CardTitle>Amenities & Features</CardTitle>
                        <CardDescription>
                            Property features and neighborhood amenities
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <!-- Validation Errors Display -->
                <div
                    v-if="validationErrors.length > 0"
                    class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6"
                >
                    <div class="flex items-start gap-2">
                        <AlertCircle
                            class="h-5 w-5 text-red-600 flex-shrink-0 mt-0.5"
                        />
                        <div>
                            <h4 class="text-sm font-semibold text-red-800 mb-2">
                                Please correct the following errors:
                            </h4>
                            <ul
                                class="text-sm text-red-700 space-y-1 list-disc list-inside max-h-60 overflow-y-auto"
                            >
                                <li
                                    v-for="error in validationErrors"
                                    :key="error"
                                >
                                    {{ error }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <Alert>
                    <AlertDescription>
                        <p>
                            <span
                                >We need to know more about your home so that we
                                can properly market the home and give the new
                                tenant information on how to take care of the
                                home.
                                <strong class="text-red-600"
                                    >All fields in this section are
                                    required.</strong
                                ></span
                            >
                        </p>
                    </AlertDescription>
                </Alert>
                <div>
                    <h3 class="font-semibold mb-4">Swimming Pool</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Swimming Pool Present?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.swimmingPool">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes"
                                        >Yes - Has pool</SelectItem
                                    >
                                    <SelectItem value="No">No pool</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.swimmingPool === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Pool Service Included?</label
                            >
                            <Select v-model="form.poolService">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes"
                                        >Yes - Service included</SelectItem
                                    >
                                    <SelectItem value="No"
                                        >No - Tenant responsible</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.poolService === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Pool Service Company</label
                            >
                            <Input
                                v-model="form.poolServiceName"
                                placeholder="Company name"
                            />
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.poolService === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Pool Service Phone</label
                            >
                            <Input
                                v-model="form.poolServiceNumber"
                                placeholder="Phone number"
                            />
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="font-semibold mb-4">Alarm System</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Is there an Alarm System?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.alarmSystem">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.alarmSystem === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Included in Price?</label
                            >
                            <Select v-model="form.alarmSystemIncludedInPrice">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.alarmSystem === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Under a contract?</label
                            >
                            <Select v-model="form.alarmSystemUnderContract">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.alarmSystem === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Alarm Code</label
                            >
                            <Input
                                v-model="form.alarmSystemCode"
                                placeholder="Enter alarm code"
                            />
                        </div>

                        <div
                            class="space-y-2 md:col-span-2"
                            v-if="form.alarmSystem === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Will the system be armed during
                                Marketing?</label
                            >
                            <Select
                                v-model="form.alarmSystemBeArmDuringMarketing"
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </div>
                <div>
                    <h3 class="font-semibold mb-4">Neighborhood Amenities</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Community Pool?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.communityPool">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Nearby Park?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.park">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Playground?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.playGround">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Tennis Court?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.tennisCourt">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div class="space-y-2 mt-2">
                        <label class="text-sm font-medium"
                            >Who the tenant should contact to access
                            Neighborhood Amenities
                            <span class="text-red-600">*</span>
                            <span class="text-xs text-gray-500"
                                >(Required)</span
                            >
                        </label>
                        <Input
                            type="text"
                            v-model="form.tenantToContactNeighborhoodAmenities"
                            placeholder="HOA contact, management company, etc."
                        />
                    </div>
                </div>

                <div>
                    <h3 class="font-semibold mb-4">
                        Gate, Garage Access & Mailbox
                    </h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Is this property gated?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.gatedCommunity">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes"
                                        >Yes - Gated community</SelectItem
                                    >
                                    <SelectItem value="No"
                                        >No - Not gated</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.gatedCommunity === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Gate Code
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                v-model="form.gateCode"
                                placeholder="e.g. #1234 - add any visitor instructions"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Garage Door Opener?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.garageDoorOpener">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Number of Remotes?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                type="number"
                                v-model="form.garageDoorRemote"
                                placeholder="e.g. 2"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Lockbox Code
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                v-model="form.lockboxCode"
                                placeholder="Enter lockbox code"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Number of Mailbox Keys?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                type="number"
                                v-model="form.mailboxKeyNo"
                                placeholder="e.g. 2"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Location of Mailbox?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                v-model="form.mailboxLocation"
                                placeholder="e.g. Front door, End of driveway"
                            />
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="font-semibold mb-4">Appliances</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Refrigerator Included?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.refrigerator">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Microwave Included?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.microwave">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Washing Machine Included?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.washingMachine">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Washing Machine Hookups?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.washingMachineHookups">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Gas">Gas</SelectItem>
                                    <SelectItem value="Electric"
                                        >Electric</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Dryer Included?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.dryer">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Dryer Hookups?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.dryerHookups">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Gas">Gas</SelectItem>
                                    <SelectItem value="Electric"
                                        >Electric</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Water Softener?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.waterSoftener">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes">Yes</SelectItem>
                                    <SelectItem value="No">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Water Heater Model Year?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                v-model="form.waterHeaterModelYear"
                                placeholder="e.g. 2018"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Dishwasher Model Year?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                v-model="form.dishWasherModelYear"
                                placeholder="e.g. 2019"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >HVAC Model Year?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                v-model="form.hvacModelYear"
                                placeholder="e.g. 2020"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Fireplace
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Select v-model="form.fireplace">
                                <SelectTrigger>
                                    <SelectValue
                                        placeholder="Select fireplace type"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in FIREPLACE_OPTIONS"
                                        :key="option"
                                        :value="option"
                                        >{{ option }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-semibold">Utilities & Services</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Water Provider
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                v-model="form.waterProvider"
                                placeholder="Water company name"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Gas Provider
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                v-model="form.gasProvider"
                                placeholder="Gas company name"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Trash Provider
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                v-model="form.trashProvider"
                                placeholder="Trash service company"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Trash Pickup Days
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500"
                                    >(Required)</span
                                >
                            </label>
                            <Input
                                v-model="form.trashPickupDays"
                                placeholder="e.g. Monday, Thursday"
                            />
                        </div>
                    </div>

                    <div class="space-y-4">
                        <h4 class="text-sm font-semibold">
                            Sprinkler / Irrigation System
                        </h4>
                        <p class="text-sm text-gray-500">
                            The utility companies and your tenant both need to
                            know whether the home has one and how it is run.
                        </p>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >Sprinkler / Irrigation System?
                                    <span class="text-red-600">*</span>
                                    <span class="text-xs text-gray-500"
                                        >(Required)</span
                                    >
                                </label>
                                <Select v-model="form.sprinklerSystem">
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder="Select option"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="Yes"
                                            >Yes - Has a sprinkler
                                            system</SelectItem
                                        >
                                        <SelectItem value="No"
                                            >No sprinkler system</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </div>

                            <div
                                class="space-y-2"
                                v-if="form.sprinklerSystem === 'Yes'"
                            >
                                <label class="text-sm font-medium"
                                    >Sprinkler Controller Location
                                    <span class="text-red-600">*</span>
                                    <span class="text-xs text-gray-500"
                                        >(Required)</span
                                    >
                                </label>
                                <Input
                                    v-model="form.sprinklerControllerLocation"
                                    placeholder="e.g. Garage wall, Utility closet"
                                />
                            </div>

                            <div
                                class="space-y-2 md:col-span-2"
                                v-if="form.sprinklerSystem === 'Yes'"
                            >
                                <label class="text-sm font-medium"
                                    >Sprinkler Notes
                                    <span class="text-xs text-gray-500"
                                        >(Optional)</span
                                    >
                                </label>
                                <Input
                                    v-model="form.sprinklerNotes"
                                    placeholder="Watering schedule, separate irrigation meter, who maintains it"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <Button
                    @click="markSectionCompleted('amenities')"
                    :variant="
                        completedSections.includes('amenities')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('amenities')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('amenities')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("amenities")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
