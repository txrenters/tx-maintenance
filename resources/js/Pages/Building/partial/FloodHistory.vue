<script setup>
import { CheckCircle, Home, AlertCircle } from "lucide-vue-next";
import { ref } from "vue";
import { useToast } from "@/Components/ui/toast/use-toast";

const form = defineModel("form");
const emit = defineEmits(["sectionComplete"]);
const props = defineProps({
    completedSections: Array,
});

const { toast } = useToast();
const validationErrors = ref([]);

const validatePropertyHistory = () => {
    const errors = [];

    // Ever Flooded is required
    if (!form.value.floodedProperty || form.value.floodedProperty.trim() === "") {
        errors.push("Ever Flooded selection is required");
    }

    // If flooded, date is required
    if (form.value.floodedProperty === "Yes") {
        if (!form.value.floodedPropertyDate || form.value.floodedPropertyDate.trim() === "") {
            errors.push("Last Flood Date is required when property has flooded");
        }
    }

    validationErrors.value = errors;
    return errors.length === 0;
};

const markSectionCompleted = (value) => {
    if (!validatePropertyHistory()) {
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
    <section id="history">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-orange-100 rounded-lg">
                        <Home class="h-6 w-6 text-orange-600" />
                    </div>
                    <div>
                        <CardTitle>Property History</CardTitle>
                        <CardDescription class="mt-1">
                            Set your preferences for tenant policies
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

                <div>
                    <h3 class="font-semibold">Property History</h3>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="text-sm font-medium"
                                >Ever Flooded?
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500">(Required)</span>
                            </label>
                            <Select v-model="form.floodedProperty">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select option" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Yes"
                                        >Yes - Has flooded</SelectItem
                                    >
                                    <SelectItem value="No"
                                        >No flooding history</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            class="space-y-2"
                            v-if="form.floodedProperty === 'Yes'"
                        >
                            <label class="text-sm font-medium"
                                >Last Flood Date
                                <span class="text-red-600">*</span>
                                <span class="text-xs text-gray-500">(Required)</span>
                            </label>
                            <Input
                                type="date"
                                v-model="form.floodedPropertyDate"
                            />
                        </div>
                    </div>
                </div>

                <Button
                    @click="markSectionCompleted('history')"
                    :variant="
                        completedSections.includes('history')
                            ? 'default'
                            : 'outline'
                    "
                    :class="[
                        'w-full sm:w-auto',
                        completedSections.includes('history')
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : '',
                    ]"
                    :disabled="completedSections.includes('history')"
                >
                    <CheckCircle class="w-4 h-4 mr-2" />
                    {{
                        completedSections.includes("history")
                            ? "Section Completed"
                            : "Mark Section Complete"
                    }}
                </Button>
            </CardContent>
        </Card>
    </section>
</template>
