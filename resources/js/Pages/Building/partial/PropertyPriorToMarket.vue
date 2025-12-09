<script setup>
import { CheckCircle, Info, Wrench, AlertCircle } from "lucide-vue-next";
import { ref } from "vue";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";
import { useToast } from "@/Components/ui/toast/use-toast";

const form = defineModel("form");
const emit = defineEmits(["sectionComplete"]);
const props = defineProps({
    completedSections: Array,
});

const { toast } = useToast();
const validationErrors = ref([]);

const validatePropertyPriorToMarket = () => {
    const errors = [];

    // Prior to property photos
    if (!form.value.goingOnTheMarketLawnCare || form.value.goingOnTheMarketLawnCare.trim() === "") {
        errors.push("Lawn Care (Prior to property photos) is required");
    }
    if (!form.value.goingOnTheMarketCleaning || form.value.goingOnTheMarketCleaning.trim() === "") {
        errors.push("Professional Cleaning (Prior to property photos) is required");
    }
    if (!form.value.goingOnTheMarketDebrisRemoval || form.value.goingOnTheMarketDebrisRemoval.trim() === "") {
        errors.push("Debris Removal (Prior to property photos) is required");
    }
    if (!form.value.goingOnTheMarketPaint || form.value.goingOnTheMarketPaint.trim() === "") {
        errors.push("Paint (Prior to property photos) is required");
    }
    if (!form.value.goingOnTheMarketCarpetCleaning || form.value.goingOnTheMarketCarpetCleaning.trim() === "") {
        errors.push("Carpet Cleaning (Prior to property photos) is required");
    }
    if (!form.value.goingOnTheMarketCarpetReplacement || form.value.goingOnTheMarketCarpetReplacement.trim() === "") {
        errors.push("Carpet Replacement (Prior to property photos) is required");
    }
    if (!form.value.goingOnTheMarketUtilities || form.value.goingOnTheMarketUtilities.trim() === "") {
        errors.push("Utilities (Prior to property photos) is required");
    }

    // While the home is on the Market
    if (!form.value.homeOnTheMarketLawnCare || form.value.homeOnTheMarketLawnCare.trim() === "") {
        errors.push("Lawn Care (While home is on the market) is required");
    }
    if (!form.value.homeOnTheMarketCleaning || form.value.homeOnTheMarketCleaning.trim() === "") {
        errors.push("Cleaning (While home is on the market) is required");
    }
    if (!form.value.homeOnTheMarketUtilities || form.value.homeOnTheMarketUtilities.trim() === "") {
        errors.push("Utilities (While home is on the market) is required");
    }

    // Just Before Tenant Move-in
    if (!form.value.beforeTenantMoveInLawnCare || form.value.beforeTenantMoveInLawnCare.trim() === "") {
        errors.push("Final Lawn Care (Before tenant move-in) is required");
    }
    if (!form.value.beforeTenantMoveInPestControl || form.value.beforeTenantMoveInPestControl.trim() === "") {
        errors.push("Pest Control (Before tenant move-in) is required");
    }

    // After tenant move in
    if (!form.value.afterTenantMoveInLawnCare || form.value.afterTenantMoveInLawnCare.trim() === "") {
        errors.push("Ongoing Lawn Care (After tenant move-in) is required");
    }

    validationErrors.value = errors;
    return errors.length === 0;
};

const markSectionCompleted = (value) => {
    if (!validatePropertyPriorToMarket()) {
        toast({
            variant: "destructive",
            title: "Validation Error",
            description: "Please fill in all required fields before completing this section.",
        });
        return;
    }
    validationErrors.value = [];
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
                    <!-- Validation Errors Display -->
                    <div
                        v-if="validationErrors.length > 0"
                        class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6"
                    >
                        <div class="flex items-start gap-2">
                            <AlertCircle class="h-5 w-5 text-red-600 flex-shrink-0 mt-0.5" />
                            <div>
                                <h4 class="text-sm font-semibold text-red-800 mb-2">
                                    Please correct the following errors:
                                </h4>
                                <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                                    <li v-for="error in validationErrors" :key="error">
                                        {{ error }}
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <Alert class="mb-4">
                        <AlertDescription>
                            The following items are only for the initial lease
                            term. After the initial tenant moves out, if
                            TexasRenters.com is still managing the property,
                            will handle utilities, lawn care, carpet clearning
                            and cleaning unless otherwise instructed at that
                            tme, per the property management agreement. <strong>All fields are required.</strong>
                        </AlertDescription>
                    </Alert>

                    <div class="space-y-6">
                        <div>
                            <h4 class="font-medium mb-3">
                                Prior to property photos the following items
                                need to be completed
                            </h4>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Lawn Care
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        >Professional Cleaning
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        >Debris Removal
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        >Paint
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        >Carpet Cleaning
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        >Carpet Replacement
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        >Utilities
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        >Lawn Care
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        >Cleaning
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        >Utilities
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        <p class="font-bold">
                                            <span class="underline"
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
                                        <p class="font-bold">
                                            <a
                                                href="https://www.texasrenters.com/"
                                                class="text-primary font-bold"
                                                >TexasRenters.com, LLC</a
                                            >
                                            will arrange for and pay for, at the
                                            owner's expense, a final cleaning
                                            just prior to tenant move in. You
                                            may have the lawn and pest control
                                            done at your discretion. For
                                            standard treatment of roaches and
                                            bugs (excluding rodents and bee
                                            hives) the charge is $60.00 for pest
                                            control.
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
                                        >Final Lawn Care
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                        >Pest Control
                                        <span class="text-red-600">*</span>
                                        <span class="text-xs text-gray-500">(Required)</span>
                                    </label>
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
                                    >Ongoing Lawn Care
                                    <span class="text-red-600">*</span>
                                    <span class="text-xs text-gray-500">(Required)</span>
                                </label>
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
