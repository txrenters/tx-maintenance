<script setup>
import { CheckCircle, Dog, Key, User2, Users2, AlertCircle } from "lucide-vue-next";
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

const validatePetPolicy = () => {
    const errors = [];

    // Dogs Allowed is required
    if (!form.value.dogsAllowed || form.value.dogsAllowed.trim() === "") {
        errors.push("Dogs Allowed selection is required");
    }

    // If dogs allowed, max weight is required
    if (form.value.dogsAllowed === "Yes") {
        if (!form.value.dogsMaxWeight || form.value.dogsMaxWeight.toString().trim() === "") {
            errors.push("Maximum Dog Weight is required when dogs are allowed");
        }
    }

    // Cats Allowed is required
    if (!form.value.catsAllowed || form.value.catsAllowed.trim() === "") {
        errors.push("Cats Allowed selection is required");
    }

    // If cats allowed, restrictions is required
    if (form.value.catsAllowed === "Yes") {
        if (!form.value.catRestrictions || form.value.catRestrictions.trim() === "") {
            errors.push("Cat Restrictions is required when cats are allowed");
        }
    }

    // Other Pet Restrictions is required
    if (!form.value.otherPetsRestriction || form.value.otherPetsRestriction.trim() === "") {
        errors.push("Other Pet Restrictions is required");
    }

    validationErrors.value = errors;
    return errors.length === 0;
};

const markSectionCompleted = (value) => {
    if (!validatePetPolicy()) {
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
    <section id="pet_policies">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-red-100 rounded-lg">
                        <Dog class="h-6 w-6 text-red-600" />
                    </div>
                    <div>
                        <CardTitle>Pet Policies & Rules</CardTitle>
                        <CardDescription>
                            Set your preferences for tenant policies
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

                    <Alert variant="secondary" class="mb-4">
                        <AlertDescription>
                            <p>
                                <span class="font-bold underline">Pets:</span>
                                Many renters are choosing to live in a home
                                versus an apartment because they have pets. This
                                reality means that if you do not accept pets, it
                                will take about twice as long to rent your home
                                if you do not allow pets. However, this is of
                                course your choice. Please let us know if you
                                would accept pets, and what type below.
                            </p>
                        </AlertDescription>
                    </Alert>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Dogs Allowed?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500">(Required)</span>
                            </label>
                            <Select v-model="form.dogsAllowed">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes"
                                        >Yes - Dogs allowed</SelectItem
                                    >
                                    <SelectItem value="No">No dogs</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Cats Allowed?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500">(Required)</span>
                            </label>
                            <Select v-model="form.catsAllowed">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes"
                                        >Yes - Cats allowed</SelectItem
                                    >
                                    <SelectItem value="No">No cats</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.dogsAllowed === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Maximum Dog Weight (lbs)
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500">(Required)</span>
                            </label>
                            <Input
                                type="number"
                                v-model="form.dogsMaxWeight"
                                placeholder="e.g. 50"
                            />
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.catsAllowed === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Cat Restrictions
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500">(Required)</span>
                            </label>
                            <Input
                                v-model="form.catRestrictions"
                                placeholder="Any specific restrictions (e.g., 'No declawed cats', 'Indoor only', or 'None')"
                            />
                        </div>
                    </div>

                    <div class="space-y-2 mt-4">
                        <label class="text-sm font-medium"
                            >Other Pet Restrictions
                            <span class="text-red-600">*</span>
                            <span class="text-xs text-gray-500">(Required)</span>
                        </label>
                        <Textarea
                            v-model="form.otherPetsRestriction"
                            placeholder="Any other pet-related restrictions or policies (e.g., 'No exotic pets', 'Max 2 pets', or 'None')"
                            rows="2"
                        />
                    </div>
                </div>

                <Button
                    @click="markSectionCompleted('pet_policies')"
                    :variant="
                        completedSections.includes('pet_policies')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('pet_policies')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('pet_policies')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("pet_policies")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
