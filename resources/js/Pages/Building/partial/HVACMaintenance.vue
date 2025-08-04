<script setup>
import { AppWindow, CheckCircle } from "lucide-vue-next";

import { Alert, AlertDescription } from "@/Components/ui/alert";

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
    <section id="hvac_maintenance">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <AppWindow class="h-6 w-6 text-blue-600" />
                    </div>
                    <div>
                        <CardTitle>HVAC & Home Warranty</CardTitle>
                        <CardDescription class="mt-1">
                            Set your participation in HVAC maintenance plan and
                            home warranty
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <div class="space-y-4">
                    <Alert class="mb-4">
                        <Info class="h-4 w-4" />
                        <AlertDescription>
                            <div class="space-y-3">
                                <p>
                                    <span class="font-bold underline"
                                        >HVAC Maintenance:</span
                                    >
                                    The most common cause for damage to homes
                                    that we see is from the emergency drain line
                                    overflowing from the air-conditioning drain
                                    pan. This is caused from mold or other
                                    debris clogging up the primary and secondary
                                    drain lines. This can be prevented by doing
                                    2 things. First, the HVAC system can be
                                    serviced every 6 months, which includes
                                    blowing out the drain lines, cleaning
                                    exterior coils, checking for carbon monoxide
                                    leaks, and changing your air filter (all of
                                    this also extends the life of and increases
                                    the efficiency of your HVAC system). Second,
                                    an emergency float switch will be installed
                                    in the pan, so that if water builds up in
                                    the pan, the HVAC systems will shut off and
                                    stop producing water. 2 HVAC inspection are
                                    $11.00 per month for the first HVAC and a
                                    additional $6.00 per month for each
                                    additional unit. The float switch will
                                    automatically be installed if not present at
                                    a cost of $89.00. Please note your HVAC
                                    warranty does not cover this type of
                                    maintenance.
                                </p>
                            </div>
                        </AlertDescription>
                    </Alert>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Participate in HVAC Maintenance Plan?
                            </label>
                            <Select v-model="form.hvacMaintenancePlan">
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
                                >Install Float Switch? <br />
                                (Automatically Completed)</label
                            >
                            <Select v-model="form.installFloatSwitch">
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

                    <!-- New Location Information Fields -->
                    <div class="space-y-4">
                        <h3 class="font-semibold">
                            Critical Location Information
                        </h3>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >Gas Shut Off Valve Location</label
                                >
                                <Input
                                    v-model="form.gasShutoffValveLocation"
                                    placeholder="e.g. Left side of house near meter"
                                />
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >Breaker Box Location</label
                                >
                                <Input
                                    v-model="form.breakerBoxLocation"
                                    placeholder="e.g. Garage wall near entrance"
                                />
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >HVAC Filter Location Information 1</label
                                >
                                <Input
                                    v-model="form.hvacFilterLocation1"
                                    placeholder="e.g. Return air grille in hallway ceiling"
                                />
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >HVAC Filter Size 1</label
                                >
                                <Input
                                    v-model="form.hvacFilterSize1"
                                    placeholder="e.g. 20x25x1"
                                />
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >HVAC Filter Location Information 2</label
                                >
                                <Input
                                    v-model="form.hvacFilterLocation2"
                                    placeholder="e.g. Return air grille in hallway ceiling"
                                />
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >HVAC Filter Size 2</label
                                >
                                <Input
                                    v-model="form.hvacFilterSize2"
                                    placeholder="e.g. 20x25x1"
                                />
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >HVAC Filter Location Information 3</label
                                >
                                <Input
                                    v-model="form.hvacFilterLocation3"
                                    placeholder="e.g. Return air grille in hallway ceiling"
                                />
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >HVAC Filter Size 3</label
                                >
                                <Input
                                    v-model="form.hvacFilterSize3"
                                    placeholder="e.g. 20x25x1"
                                />
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >HVAC Filter Location Information 4</label
                                >
                                <Input
                                    v-model="form.hvacFilterLocation4"
                                    placeholder="e.g. Return air grille in hallway ceiling"
                                />
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >HVAC Filter Size 4</label
                                >
                                <Input
                                    v-model="form.hvacFilterSize4"
                                    placeholder="e.g. 20x25x1"
                                />
                            </div>
                        </div>
                    </div>

                    <Alert class="mb-4">
                        <Info class="h-4 w-4" />
                        <AlertDescription>
                            <div class="space-y-3">
                                <p>
                                    <span class="font-bold underline"
                                        >Home Warranty: We generally do not
                                        recommend them, because the
                                        responsiveness, and quality of the
                                        service are not often up to par.</span
                                    >
                                    They usually have 2 business days to respond
                                    to an air-conditioning service call (respond
                                    not complete), which means that if your
                                    tenant calls on Friday, they may not get a
                                    response until Tuesday. Also, if you have a
                                    plan or decide to get a plan, please read
                                    the exclusions and limitations very closely.
                                    Our experience is that many of the plans cap
                                    the most expensive such as the air
                                    air-conditioning at a set amount, and stick
                                    you with their vendor to install the new
                                    system, who usually charges 2x what we can
                                    get it done for. Also, a repair may be
                                    covered, but damage caused by the repair or
                                    malfunction, along with bringing older items
                                    up to code may not be covered. That being
                                    said, some would prefer the comfort of
                                    knowing that many cost can be covered. If
                                    you do not have a home warranty, get our
                                    advice on who to pick first. If you already
                                    have one, please include the information
                                    below, so that we can use the home warranty
                                    if your home needs service.
                                </p>
                            </div>
                        </AlertDescription>
                    </Alert>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Home Warranty?</label
                            >
                            <Select v-model="form.homeWarranty">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes"
                                        >Yes - Have warranty</SelectItem
                                    >
                                    <SelectItem value="No"
                                        >No warranty</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.homeWarranty === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Warranty Company</label
                            >
                            <Input
                                v-model="form.homeWarrantyCompanyName"
                                placeholder="Company name"
                            />
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.homeWarranty === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Service Number</label
                            >
                            <Input
                                v-model="form.homeWarrantyServiceNumber"
                                placeholder="Service request number"
                            />
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.homeWarranty === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Contact Number</label
                            >
                            <Input
                                v-model="form.homeWarrantyContactNumber"
                                placeholder="Warranty contact number"
                            />
                        </div>
                    </div>
                </div>

                <Button
                    @click="markSectionCompleted('hvac_maintenance')"
                    :variant="
                        completedSections.includes('hvac_maintenance')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('hvac_maintenance')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('hvac_maintenance')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("hvac_maintenance")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
