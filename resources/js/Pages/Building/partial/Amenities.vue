<script setup>
import { CheckCircle, Sparkles } from "lucide-vue-next";

import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";

const form = defineModel("form");
const emit = defineEmits(["sectionComplete"]);
const props = defineProps({
    completedSections: Array,
});

const markSectionCompleted = (value) => {
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
                <Alert>
                    <AlertDescription>
                        <p>
                            <span
                                >We need to know more about your home so that we
                                can properly market the home and give the new
                                tenant information on how to take care of the
                                home.</span
                            >
                        </p>
                    </AlertDescription>
                </Alert>
                <div>
                    <h3 class="font-semibold mb-4">Swimming Pool</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Swimming Pool Present?</label
                            >
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
                                >Is there an Alarm System?</label
                            >
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
                                >Community Pool?</label
                            >
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
                                >Nearby Park?</label
                            >
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
                                >Playground?</label
                            >
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
                                >Tennis Court?</label
                            >
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
                </div>

                <div>
                    <h3 class="font-semibold mb-4">Garage Access & Mailbox</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Garage Door Opener?</label
                            >
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
                                >Number of Remotes?</label
                            >
                            <Input
                                type="number"
                                v-model="form.garageDoorRemote"
                                placeholder="e.g. 2"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Lockbox Code</label
                            >
                            <Input
                                v-model="form.lockboxCode"
                                placeholder="Enter lockbox code"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Number of Mailbox Keys?</label
                            >
                            <Input
                                type="number"
                                v-model="form.mailboxKeyNo"
                                placeholder="e.g. 2"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Location of Mailbox?</label
                            >
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
                                >Refrigerator Included?</label
                            >
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
                                >Microwave Included?</label
                            >
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
                                >Washing Machine Included?</label
                            >
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
                                >Washing Machine Hookups?</label
                            >
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
                                >Dryer Included?</label
                            >
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
                                >Dryer Hookups?</label
                            >
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
                                >Water Softener?</label
                            >
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
                                >Water Heater Model Year?</label
                            >
                            <Input
                                v-model="form.waterHeaterModelYear"
                                placeholder="e.g. 2018"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Dishwasher Model Year?</label
                            >
                            <Input
                                v-model="form.dishWasherModelYear"
                                placeholder="e.g. 2019"
                            />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >HVAC Model Year?</label
                            >
                            <Input
                                v-model="form.hvacModelYear"
                                placeholder="e.g. 2020"
                            />
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-semibold">Utilities & Services</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Water Provider</label
                            >
                            <Input
                                v-model="form.waterProvider"
                                placeholder="Water company name"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Gas Provider</label
                            >
                            <Input
                                v-model="form.gasProvider"
                                placeholder="Gas company name"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Trash Provider</label
                            >
                            <Input
                                v-model="form.trashProvider"
                                placeholder="Trash service company"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Trash Pickup Days</label
                            >
                            <Input
                                v-model="form.trashPickupDays"
                                placeholder="e.g. Monday, Thursday"
                            />
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
