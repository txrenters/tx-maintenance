<script setup>
import { CheckCircle, Key, User2, Users2 } from "lucide-vue-next";

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
    <section id="tenant_service_request">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <Users2 class="h-6 w-6 text-blue-600" />
                    </div>
                    <div>
                        <CardTitle>Tenant Service Request Policy</CardTitle>
                        <CardDescription class="mt-1">
                            Tenant Service Request Handling
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <div>
                    <Alert class="mb-4">
                        <AlertDescription>
                            <div class="space-y-2">
                                <p>
                                    <span class="font-bold underline"
                                        >Initial Repairs after move in:</span
                                    >
                                    If there are non-cosmetics items that are
                                    found that need to be repaired upon move in,
                                    such as a garbage disposal that does not
                                    work, or leaking toilet, we will normally
                                    repair these items, and waive the $75.00
                                    repair deductible for the tenant, as the
                                    item was not working when the tenant moved
                                    in. We will only do this for 1 beginning,
                                    and helps to reduce your turnover expense.
                                </p>
                            </div>
                        </AlertDescription>
                    </Alert>
                    <Alert class="mb-4">
                        <AlertDescription>
                            <div class="space-y-2">
                                <p>
                                    <span class="font-bold underline"
                                        >Repairs during the Lease terms:</span
                                    >
                                    Our leases require a $75.00 tenant
                                    co-payment for all repairs that are not
                                    related to the HVAC system, water heater or
                                    roof during the term of the lease. We
                                    jokingly call it the door stop and toilet
                                    flapper clause, because it keeps you from
                                    having a repair bill for nuisance items that
                                    a tenant can easily take case of themselves.
                                    However, we do like to use discretion on
                                    some items that it is obvious that the
                                    tenant did not cause, and waive the $75.00
                                    co-payment. We find that this little bit of
                                    good helps to keep your tenant happy, and
                                    they are much more likely to renew when they
                                    are treated fairly.
                                </p>
                            </div>
                        </AlertDescription>
                    </Alert>
                    <div>
                        <h3 class="font-semibold mb-4">
                            How Would You Like Us to Handle Tenant Service
                            Request?
                        </h3>
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Tenant Service Request Handling</label
                            >
                            <Select v-model="form.tenantServiceRequest">
                                <SelectTrigger>
                                    <SelectValue
                                        placeholder="Select service policy"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Lease Only"
                                        >$75 Co-Pay from Tenant</SelectItem
                                    >
                                    <SelectItem value="Property Management"
                                        >Full Invoice Amount to
                                        Owner</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </div>

                <Button
                    @click="markSectionCompleted('tenant_service_request')"
                    :variant="
                        completedSections.includes('tenant_service_request')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('tenant_service_request')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="
                        completedSections.includes('tenant_service_request')
                    "
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("tenant_service_request")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
