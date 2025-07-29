<script setup>
import { CheckCircle, Info, Wrench } from "lucide-vue-next";
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
    <section id="services">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-yellow-100 rounded-lg">
                        <Wrench class="h-6 w-6 text-yellow-600" />
                    </div>
                    <div>
                        <CardTitle
                            >Who will do what prior to the home going on the
                            market?</CardTitle
                        >
                        <CardDescription>
                            Utility providers and maintenance preferences
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <div>
                    <Alert class="mb-4">
                        <AlertDescription>
                            The following items are only for the initial lease
                            term. After the initial tenant moves out, if
                            TexasRenters.com is still managing the property,
                            will handle utilities, lawn care, carpet clearning
                            and cleaning unless otherwise instructed at that
                            tme, per the property management agreement.
                        </AlertDescription>
                    </Alert>

                    <div class="space-y-6">
                        <div>
                            <h4 class="font-medium mb-3">Prior to Market</h4>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Lawn Care</label
                                    >
                                    <Select
                                        v-model="form.goingOnTheMarketLawnCare"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Professional Cleaning</label
                                    >
                                    <Select
                                        v-model="form.goingOnTheMarketCleaning"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Debris Removal</label
                                    >
                                    <Select
                                        v-model="
                                            form.goingOnTheMarketDebrisRemoval
                                        "
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Paint</label
                                    >
                                    <Select
                                        v-model="form.goingOnTheMarketPaint"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Carpet Cleaning</label
                                    >
                                    <Select
                                        v-model="
                                            form.goingOnTheMarketCarpetCleaning
                                        "
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Carpet Replacement</label
                                    >
                                    <Select
                                        v-model="
                                            form.goingOnTheMarketCarpetReplacement
                                        "
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Utilities</label
                                    >
                                    <Select
                                        v-model="form.goingOnTheMarketUtilities"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </div>

                        <div>
                            <Alert class="mb-4">
                                <AlertDescription>
                                    <span class="font-bold underline"
                                        >During the time the property is on the
                                        market:</span
                                    >
                                    It is important that the lawn and home stay
                                    in good shape while the home is on the
                                    market. Please indicate below if you would
                                    like to be responsible for the below items,
                                    or if you would like us to handle it for
                                    you.
                                </AlertDescription>
                            </Alert>
                            <h4 class="font-medium mb-3">
                                Who will do what while the home is on the
                                Market?
                            </h4>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Lawn Care</label
                                    >
                                    <Select
                                        v-model="form.homeOnTheMarketLawnCare"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Cleaning</label
                                    >
                                    <Select
                                        v-model="form.homeOnTheMarketCleaning"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Utilities</label
                                    >
                                    <Select
                                        v-model="form.homeOnTheMarketUtilities"
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </div>

                        <div>
                            <Alert class="mb-4">
                                <AlertDescription>
                                    <div class="space-y-2">
                                        <p>
                                            <span class="font-bold underline"
                                                >Final Touches before Tenant
                                                Move in:</span
                                            >
                                            We require that all homes be
                                            profesionally cleaned, and carpets
                                            thermostat are not new be
                                            professionally cleaned, a final lawn
                                            cut be done and pest control prior
                                            to move in. We expect that when a
                                            tenant moves out of the home, they
                                            will also have the home
                                            professionally cleaned and carpets
                                            steam cleaned. By having the home
                                            professionally cleaned, and being
                                            able to provide receipts, we can
                                            help assure that as the home owner,
                                            you will not be paying to clean up
                                            after tenants in the future. In most
                                            cases, this will be the only time
                                            that you need to pay for cleaning.
                                            If the carpets were not
                                            professionally cleaned prior to
                                            putting the home on the market, this
                                            is the time to do it. We do however,
                                            allow you to choose between us
                                            providing the service, and you
                                            arranging the service on your own.
                                            If you arrange the service, please
                                            provide receipts for our records.
                                        </p>
                                    </div>
                                </AlertDescription>
                            </Alert>
                            <h4 class="font-medium mb-3">
                                Who will do what Just Before Tenant Move-in?
                            </h4>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Final Lawn Care</label
                                    >
                                    <Select
                                        v-model="
                                            form.beforeTenantMoveInLawnCare
                                        "
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Pest Control</label
                                    >
                                    <Select
                                        v-model="
                                            form.beforeTenantMoveInPestControl
                                        "
                                    >
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Who handles?"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >Owner</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h4 class="font-medium mb-3">
                                Who will do lawn care after tenant move in?
                            </h4>
                            <div class="space-y-2">
                                <label class="text-sm font-medium"
                                    >Ongoing Lawn Care</label
                                >
                                <Select
                                    v-model="form.afterTenantMoveInLawnCare"
                                >
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder="Who handles?"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="Tenant"
                                            >Tenant</SelectItem
                                        >
                                        <SelectItem value="Owner"
                                            >Owner</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </div>
                </div>

                <Button
                    @click="markSectionCompleted('services')"
                    :variant="
                        completedSections.includes('services')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('services')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('services')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("services")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
