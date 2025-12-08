<script setup>
import {
    Building,
    CheckCircle,
    Home,
    Info,
    Loader,
    MapIcon,
    SearchIcon,
    AlertCircle,
} from "lucide-vue-next";
import { ref } from "vue";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";
import { useToast } from "@/Components/ui/toast/use-toast";

const fullName = defineModel("fullName");
const contactNumber = defineModel("contactNumber");
const propertyName = defineModel("propertyName");
const selectedBuildingId = defineModel("selectedBuildingId");
const loadBuildingData = defineModel("loadBuildingData");
const loading = defineModel("loading");

const props = defineProps({
    buildings: Object,
    buildingInfo: Object,
});

const emit = defineEmits(["search"]);
const { toast } = useToast();

const errors = ref({
    fullName: "",
    contactNumber: "",
    propertyName: "",
});

const validateSearchForm = () => {
    errors.value = {
        fullName: "",
        contactNumber: "",
        propertyName: "",
    };

    let isValid = true;

    // Validate Full Name
    if (!fullName.value || fullName.value.trim() === "") {
        errors.value.fullName = "Full name is required";
        isValid = false;
    }

    // Validate Contact Number
    if (!contactNumber.value || contactNumber.value.trim() === "") {
        errors.value.contactNumber = "Contact number is required";
        isValid = false;
    } else {
        // Remove all non-digit characters for validation
        const digits = contactNumber.value.replace(/\D/g, "");
        if (digits.length !== 10) {
            errors.value.contactNumber = "Contact number must be exactly 10 digits";
            isValid = false;
        }
    }

    // Validate Property Name
    if (!propertyName.value || propertyName.value.trim() === "") {
        errors.value.propertyName = "Building address is required";
        isValid = false;
    }

    return isValid;
};

const searchProperty = () => {
    if (!validateSearchForm()) {
        toast({
            variant: "destructive",
            title: "Validation Error",
            description: "Please fill in all required fields correctly.",
        });
        return;
    }
    emit("search", loading.value);
};
</script>

<template>
    <section id="search">
        <Card>
            <CardHeader>
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <Home class="h-6 w-6 text-blue-600" />
                    </div>
                    <div>
                        <CardTitle>Find Your Building</CardTitle>
                        <CardDescription>
                            Let's start by locating your building in our system
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-6">
                <Alert>
                    <Info class="h-4 w-4" />
                    <AlertTitle>Important</AlertTitle>
                    <AlertDescription>
                        Please ensure the information matches exactly with your
                        Propertyware records. All fields are required.
                    </AlertDescription>
                </Alert>

                <div class="grid gap-6 md:grid-cols-2">
                    <div class="space-y-2">
                        <label class="text-sm font-medium">
                            Full Name
                            <span class="text-red-600">*</span>
                            <span class="text-xs text-gray-500">(Required)</span>
                        </label>
                        <Input
                            v-model="fullName"
                            placeholder="John Doe"
                            :class="[
                                'w-full',
                                errors.fullName ? 'border-red-500' : '',
                            ]"
                        />
                        <p
                            v-if="errors.fullName"
                            class="text-xs text-red-600 flex items-center gap-1"
                        >
                            <AlertCircle class="h-3 w-3" />
                            {{ errors.fullName }}
                        </p>
                        <p v-else class="text-xs text-gray-500">
                            As registered in Propertyware
                        </p>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">
                            Contact Number
                            <span class="text-red-600">*</span>
                            <span class="text-xs text-gray-500">(Required)</span>
                        </label>
                        <Input
                            v-model="contactNumber"
                            placeholder="1234567890"
                            :class="[
                                'w-full',
                                errors.contactNumber ? 'border-red-500' : '',
                            ]"
                        />
                        <p
                            v-if="errors.contactNumber"
                            class="text-xs text-red-600 flex items-center gap-1"
                        >
                            <AlertCircle class="h-3 w-3" />
                            {{ errors.contactNumber }}
                        </p>
                        <p v-else class="text-xs text-gray-500">
                            10-digit phone number
                        </p>
                    </div>

                    <div class="space-y-2 md:col-span-2">
                        <label class="text-sm font-medium">
                            Building Address
                            <span class="text-red-600">*</span>
                            <span class="text-xs text-gray-500">(Required)</span>
                        </label>
                        <Input
                            v-model="propertyName"
                            placeholder="123 Main Street"
                            :class="[
                                'w-full',
                                errors.propertyName ? 'border-red-500' : '',
                            ]"
                        />
                        <p
                            v-if="errors.propertyName"
                            class="text-xs text-red-600 flex items-center gap-1"
                        >
                            <AlertCircle class="h-3 w-3" />
                            {{ errors.propertyName }}
                        </p>
                        <p v-else class="text-xs text-gray-500">
                            Exact building name or address
                        </p>
                    </div>
                </div>

                <Button
                    @click="searchProperty"
                    :disabled="loading"
                    class="w-full sm:w-auto"
                >
                    <Loader class="w-4 h-4 mr-2 animate-spin" v-if="loading" />
                    <SearchIcon class="w-4 h-4 mr-2" v-else />
                    {{ loading ? "Searching..." : "Search Property" }}
                </Button>

                <!-- Search Results -->
                <div v-if="buildings.length > 0" class="mt-6">
                    <!-- Multiple buildings found -->
                    <div
                        v-if="buildings.length > 1 && !selectedBuildingId"
                        class="space-y-4"
                    >
                        <Alert class="border-blue-200 bg-blue-50">
                            <Info class="h-4 w-4 text-blue-600" />
                            <AlertTitle class="text-blue-800"
                                >Multiple Properties Found</AlertTitle
                            >
                            <AlertDescription class="text-blue-700">
                                Found
                                {{ buildings.length }}
                                properties. Please select one to continue.
                            </AlertDescription>
                        </Alert>

                        <RadioGroup
                            v-model="selectedBuildingId"
                            @update:modelValue="loadBuildingData"
                        >
                            <div class="space-y-3">
                                <div
                                    v-for="b in buildings"
                                    :key="b.id"
                                    class="flex items-start space-x-2 p-3 border rounded-lg hover:bg-gray-50 transition-colors"
                                >
                                    <RadioGroupItem
                                        :value="b.id"
                                        :id="`building-${b.id}`"
                                    />
                                    <Label
                                        :for="`building-${b.id}`"
                                        class="flex-1 cursor-pointer"
                                    >
                                        <div class="space-y-1">
                                            <p
                                                class="font-semibold flex gap-2 items-center"
                                            >
                                                <Building class="w-4 h-4" />
                                                {{ b.name }}
                                            </p>
                                            <p
                                                class="text-sm text-gray-600 flex gap-2 items-center"
                                            >
                                                <MapIcon class="w-3 h-3" />
                                                {{ b.address?.address }}
                                                {{ b.address?.city }},
                                                {{ b.address?.stateRegion }}
                                                {{ b.address?.postalCode }}
                                            </p>
                                        </div>
                                    </Label>
                                </div>
                            </div>
                        </RadioGroup>
                    </div>

                    <!-- Single building or building selected -->
                    <Alert class="border-green-200 bg-green-50">
                        <CheckCircle class="h-4 w-4 text-green-600" />
                        <AlertTitle class="text-green-800"
                            >Building
                            {{
                                buildings.length > 1 ? "Selected" : "Found"
                            }}!</AlertTitle
                        >
                        <AlertDescription class="text-green-700">
                            <div class="mt-2 space-y-1">
                                <p
                                    class="font-semibold flex gap-2 items-center"
                                >
                                    <Building class="w-4 h-4" />
                                    {{ buildingInfo.name }}
                                </p>
                                <p class="text-sm flex gap-2 items-center">
                                    <MapIcon class="w-4 h-4" />
                                    {{ buildingInfo.address.address }}
                                    {{ buildingInfo.address.city }},
                                    {{ buildingInfo.address.stateRegion }}
                                    {{ buildingInfo.address.postalCode }}
                                </p>
                            </div>
                        </AlertDescription>
                    </Alert>
                </div>
            </CardContent>
        </Card>
    </section>
</template>
