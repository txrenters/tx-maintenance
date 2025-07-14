<script setup>
import { Button } from "@/Components/ui/button";
import { Checkbox } from "@/Components/ui/checkbox";
import Input from "@/Components/ui/input/Input.vue";
import { Textarea } from "@/Components/ui/textarea";
import { Head, useForm } from "@inertiajs/vue3";
import { reactive, ref, computed } from "vue";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { useToast } from "@/Components/ui/toast/use-toast";
import {
    Home,
    PaintBucket,
    Wrench,
    Shield,
    Users,
    Clipboard,
    Sparkles,
    CheckCircle,
    Info,
    AlertTriangle,
    Calendar,
    Phone,
    Building,
    Truck,
    Clock,
    MapIcon,
} from "lucide-vue-next";
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import { Progress } from "@/Components/ui/progress";
import { Badge } from "@/Components/ui/badge";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";

const { toast } = useToast();

const fullName = ref("");
const contactNumber = ref("");
const propertyName = ref("");

const form = useForm({
    paint: "",
    paintColor: "",
    //Who will do what prior to the home going on the market
    goingOnTheMarketLawnCare: "",
    goingOnTheMarketCleaning: "",
    goingOnTheMarketDebrisRemoval: "",
    goingOnTheMarketPaint: "",
    goingOnTheMarketCarpetCleaning: "",
    goingOnTheMarketCarpetReplacement: "",
    goingOnTheMarketUtilities: "",
    //Who will do what While the Home is on the Market?
    homeOnTheMarketLawnCare: "",
    homeOnTheMarketCleaning: "",
    homeOnTheMarketUtilities: "",
    //Who will do what Just Before Tenant Move In?
    beforeTenantMoveInLawnCare: "",
    beforeTenantMoveInPestControl: "",
    //Who will do lawn care After Tenant Move In?
    afterTenantMoveInLawnCare: "",
    //Re-Key and Code Work?
    reKey: "",
    //How would you like Us to Handle Tenant Service Request?
    tenantServiceRequest: "",
    //Pets
    dogsAllowed: "",
    dogsMaxWeight: "",
    catsAllowed: "",
    catRestrictions: "",
    otherPetsRestriction: "",
    //Swimming Pool
    swimmingPool: "",
    poolService: "",
    poolServiceName: "",
    poolServiceNumber: "",
    //Alarm System
    alarmSystem: "",
    alarmSystemIncludedInPrice: "",
    alarmSystemUnderContract: "",
    alarmSystemCode: "",
    alarmSystemBeArmDuringMarketing: "",
    //Neighborhood Amenities
    communityPool: "",
    park: "",
    playGround: "",
    tennisCourt: "",
    //Garage Access & Mailbox
    garageDoorOpener: "",
    garageDoorRemote: "",
    mailboxKeyNo: "",
    mailboxLocation: "",
    //Appliances
    refrigerator: "",
    microwave: "",
    washingMachine: "",
    washingMachineHookups: "",
    dryer: "",
    dryerHookups: "",
    waterSoftener: "",
    waterHeaterModelYear: "",
    dishWasherModelYear: "",
    hvacModelYear: "",
    //Utilities & Trash Service
    waterProvider: "",
    gasProvider: "",
    trashProvider: "",
    trashPickupDays: "",
    recyclePickupDays: "",
    //HVAC Issue Prevention
    hvacMaintenancePlan: "",
    installFloatSwitch: "",
    //Home Warrany
    homeWarranty: "",
    homeWarrantyCompanyName: "",
    homeWarrantyServiceNumber: "",
    //Preferred Vendors
    hvacVendorName: "",
    hvacVendorNumber: "",
    electricVendorName: "",
    electricVendorNumber: "",
    plumbingVendorName: "",
    plumbingVendorNumber: "",
    pestControlVendorName: "",
    pestControlVenodrNumber: "",
    lawnCareVendorName: "",
    lawnCareVendorNumber: "",
    otherVendorName: "",
    otherVendorNumber: "",
    //Has the property ever flooded?
    floodedProperty: "",
    floodedPropertyDate: "",

    otherComments: "",
});

const buildingInfo = reactive({
    id: "",
    abbreviation: "",
    name: "",
    address: {
        address: "",
        addressCont: "",
        city: "",
        country: "",
        postalCode: "",
        stateRegion: "",
    },
});

const state = reactive({
    count: 0,
    option: {
        penColor: "rgb(0, 0, 0)",
        backgroundColor: "rgb(255,255,255)",
    },
    disabled: false,
});

const signature1 = ref(null);

const save = (t) => {
    console.log(signature1.value?.save(t));
};

const clear = () => {
    signature1.value?.clear();
};

const undo = () => {
    signature1.value?.undo();
};

const loading = ref(false);
const building = ref(null);

// Define sections for navigation
const sections = [
    { id: "search", title: "Property Search", icon: Home },
    { id: "preparation", title: "Property Preparation", icon: PaintBucket },
    { id: "services", title: "Service Responsibilities", icon: Wrench },
    { id: "security", title: "Security & Safety", icon: Shield },
    { id: "policies", title: "Policies & Rules", icon: Clipboard },
    { id: "amenities", title: "Amenities & Features", icon: Sparkles },
    { id: "utilities", title: "Utilities & Maintenance", icon: Wrench },
    { id: "vendors", title: "Preferred Vendors", icon: Users },
    { id: "signature", title: "Signature & Submit", icon: CheckCircle },
];

const currentSection = ref("search");
const completedSections = ref([]);

// Compute progress
const progress = computed(() => {
    return (completedSections.value.length / sections.length) * 100;
});

// Navigate to section
const navigateToSection = (sectionId) => {
    currentSection.value = sectionId;
    const element = document.getElementById(sectionId);
    if (element) {
        element.scrollIntoView({ behavior: "smooth", block: "start" });
    }
};

// Mark section as completed
const markSectionCompleted = (sectionId) => {
    if (!completedSections.value.includes(sectionId)) {
        completedSections.value.push(sectionId);
    }
};

// Define the mapping between form fields and Propertyware custom field names
const FORM_FIELD_TO_CUSTOM_FIELD_MAPPING = {
    // Paint fields
    paintColor: "Paint Color",
    paint: "Painting Required",

    // Going on the market services
    goingOnTheMarketLawnCare: "Lawn Care During Marketing",
    goingOnTheMarketCleaning: "Cleaning Service",
    goingOnTheMarketDebrisRemoval: "Debris Removal",
    goingOnTheMarketPaint: "Painting Required",
    goingOnTheMarketCarpetCleaning: "Carpet Cleaning",
    goingOnTheMarketCarpetReplacement: "Carpet Care",
    goingOnTheMarketUtilities: "Utilities",

    // While home is on market
    homeOnTheMarketLawnCare: "Lawn Care During Marketing",
    homeOnTheMarketCleaning: "Final Clean",
    homeOnTheMarketUtilities: "Utilities",

    // After tenant move in
    afterTenantMoveInLawnCare: "Yard Care During Lease",

    // Re-Key
    reKey: "Property Re-Key",

    // Tenant Service Request
    tenantServiceRequest: "Service Provided",

    // Pets
    otherPetsRestriction: "Pet Restrictions",

    // Pool
    poolService: "Pool Service",

    // Alarm
    alarmSystemCode: "Alarm System Code",

    // Utilities
    waterProvider: "Water Provider",
    gasProvider: "Gas Provider",
    trashProvider: "Trash Provider",

    // HVAC
    hvacMaintenancePlan: "HVAC Plan",

    // Home Warranty
    homeWarranty: "Home Warranty",

    // Other
    otherComments: "Make Ready Notes",
};

// Store for custom field IDs (add this after your reactive declarations)
const customFieldsMap = ref({});
const customFieldsData = ref([]);

// Update your searchProperty function to store the custom field IDs
const searchProperty = async () => {
    loading.value = true;
    building.value = null;
    customFieldsMap.value = {}; // Reset the map
    customFieldsData.value = []; // Reset the data

    if (
        !fullName.value.trim() ||
        !contactNumber.value.trim() ||
        !propertyName.value.trim()
    ) {
        toast({
            variant: "destructive",
            title: "Missing fields",
            description: "Please fill out all required fields.",
        });
        loading.value = false;
        return;
    }

    try {
        const res = await axios.post("/api/search-building", {
            fullName: fullName.value,
            contactNumber: contactNumber.value,
            propertyName: propertyName.value,
        });

        if (!res.data || res.data.error) {
            toast({
                variant: "destructive",
                title: "Search failed",
                description: res.data.error,
            });
            building.value = null;
            return;
        }

        building.value = res.data;

        // Set building info
        buildingInfo.id = building.value.id;
        buildingInfo.name = building.value.name;
        buildingInfo.abbreviation = building.value.abbreviation;
        buildingInfo.address.address = building.value.address?.address ?? "";
        buildingInfo.address.addressCont =
            building.value.address?.addressCont ?? "";
        buildingInfo.address.city = building.value.address?.city ?? "";
        buildingInfo.address.country = building.value.address?.country ?? "";
        buildingInfo.address.postalCode =
            building.value.address?.postalCode ?? "";
        buildingInfo.address.stateRegion =
            building.value.address?.stateRegion ?? "";

        // Store custom fields and create ID mapping
        if (building.value.customFields) {
            customFieldsData.value = building.value.customFields;

            // Create a map of custom field names to their data (including IDs)
            building.value.customFields.forEach((field) => {
                customFieldsMap.value[field.fieldName] = {
                    definitionID: field.definitionID,
                    dataType: field.dataType,
                    value: field.value,
                    fieldName: field.fieldName,
                };
            });

            // Optional: Pre-populate form with existing values
            populateFormFromCustomFields();

            console.log("Custom fields mapped:", customFieldsMap.value);
            toast({
                title: "Property Found!",
                description: "You can now update the property information.",
            });

            // Mark search section as completed
            markSectionCompleted("search");
        }

        console.info("Owner's property:", building.value);
    } catch (error) {
        building.value = null;
        toast({
            variant: "destructive",
            title: "Search failed",
            description: "Unable to fetch building info. Please try again.",
        });
        console.error("Error searching owner's property:", error);
    } finally {
        loading.value = false;
    }
};

// Function to populate form with existing custom field values
const populateFormFromCustomFields = () => {
    Object.entries(FORM_FIELD_TO_CUSTOM_FIELD_MAPPING).forEach(
        ([formField, customFieldName]) => {
            const customField = customFieldsMap.value[customFieldName];
            if (
                customField &&
                customField.value &&
                customField.value !== "Not Completed"
            ) {
                // Only populate if the field has a meaningful value
                if (form[formField] !== undefined) {
                    form[formField] = customField.value;
                }
            }
        }
    );
};

// Function to prepare custom fields for update (only fields from our form)
const prepareCustomFieldsForUpdate = () => {
    const fieldSetDTOS = [];
    const fieldsToUpdate = {};

    Object.entries(FORM_FIELD_TO_CUSTOM_FIELD_MAPPING).forEach(
        ([formField, customFieldName]) => {
            const customField = customFieldsMap.value[customFieldName];
            const formValue = form[formField];

            // Only include if:
            // 1. The custom field exists in Propertyware
            // 2. The form has a value for this field
            // 3. The value is different from what's in Propertyware
            if (customField && formValue && formValue !== customField.value) {
                fieldsToUpdate[customFieldName] = formValue;
            }
        }
    );

    // Special handling for complex fields

    // Pool Service - needs special value format
    if (form.swimmingPool && customFieldsMap.value["Pool Service"]) {
        const poolValue =
            form.swimmingPool === "No"
                ? "No Pool"
                : form.poolService === "Yes"
                ? "Pool Service Included"
                : "Owner Responsible";
        fieldsToUpdate["Pool Service"] = poolValue;
    }

    // HVAC Plan - needs special value format
    if (form.hvacMaintenancePlan && customFieldsMap.value["HVAC Plan"]) {
        const hvacValue =
            form.hvacMaintenancePlan === "Yes"
                ? "Premium"
                : "Opted out HVAC Plan";
        fieldsToUpdate["HVAC Plan"] = hvacValue;
    }

    // Home Warranty - combine multiple fields
    if (
        form.homeWarranty === "Yes" &&
        form.homeWarrantyCompanyName &&
        customFieldsMap.value["Home Warranty"]
    ) {
        const warrantyValue = `${form.homeWarrantyCompanyName} - ${
            form.homeWarrantyServiceNumber || "N/A"
        }`;
        fieldsToUpdate["Home Warranty"] = warrantyValue;
    }

    // Convert to Propertyware API format
    Object.entries(fieldsToUpdate).forEach(([fieldName, value]) => {
        if (customFieldsMap.value[fieldName]) {
            fieldSetDTOS.push({
                name: fieldName,
                value: value.toString(), // Ensure value is string
            });
        }
    });

    return fieldSetDTOS;
};

// Submit function
const submitForm = async () => {
    // Validate signature
    const signatureData = signature1.value?.save();
    if (
        !signatureData ||
        signatureData ===
            "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=="
    ) {
        toast({
            variant: "destructive",
            title: "Signature Required",
            description: "Please provide your signature before submitting.",
        });
        return;
    }

    // Prepare only the custom fields that need updating
    const fieldSetDTOS = prepareCustomFieldsForUpdate();

    if (fieldSetDTOS.length === 0) {
        toast({
            variant: "destructive",
            title: "No Changes",
            description: "Please make changes to the form before submitting.",
        });
        return;
    }

    loading.value = true;

    try {
        // Prepare the request body in Propertyware's expected format
        const requestBody = {
            entityId: buildingInfo.id,
            fieldSetDTOS: fieldSetDTOS,
        };

        // Submit to your Laravel endpoint
        const response = await axios.post(
            `/api/buildings/${buildingInfo.id}/update-custom-fields`,
            {
                propertywareData: requestBody,
                formData: form,
                signature: signatureData,
                ownerName: fullName.value,
            }
        );

        toast({
            title: "Success!",
            description: `Your property information has been updated successfully.`,
        });

        console.log("Updated fields:", fieldSetDTOS);

        // Mark signature section as completed
        markSectionCompleted("signature");

        // Optional: redirect or reset form
        // window.location.href = '/thank-you';
    } catch (error) {
        toast({
            variant: "destructive",
            title: "Submission Failed",
            description:
                "Unable to update building information. Please try again.",
        });
        console.error("Submission error:", error);
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <Head title="Property Onboarding | Easy Setup Process" />
    <div
        class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50"
    >
        <!-- Modern Header -->
        <header
            class="sticky top-0 z-40 bg-white/90 backdrop-blur-md shadow-sm border-b"
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <img
                        :src="$page.props.logo"
                        alt="Logo"
                        class="h-10 w-auto"
                    />
                    <div class="flex items-center gap-4">
                        <Badge
                            variant="secondary"
                            class="hidden sm:inline-flex"
                        >
                            <Clock class="w-4 h-4 mr-1" />
                            Estimated time: 15-20 minutes
                        </Badge>
                        <Button variant="ghost" size="icon">
                            <Phone class="h-5 w-5" />
                        </Button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Progress Bar -->
        <div class="bg-white border-b">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="font-medium">Overall Progress</span>
                        <span class="text-gray-600"
                            >{{ Math.round(progress) }}% Complete</span
                        >
                    </div>
                    <Progress :model-value="progress" class="h-2" />
                </div>
            </div>
        </div>

        <!-- Step Navigation -->
        <div class="bg-white border-b mb-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <div
                    class="flex flex-wrap items-center justify-center gap-2 sm:gap-4"
                >
                    <div
                        v-for="(section, index) in sections"
                        :key="section.id"
                        class="flex items-center"
                    >
                        <button
                            @click="navigateToSection(section.id)"
                            :class="[
                                'flex items-center px-3 py-2 rounded-lg text-xs sm:text-sm font-medium transition-colors',
                                currentSection === section.id
                                    ? 'bg-blue-100 text-blue-700 border-2 border-blue-300'
                                    : completedSections.includes(section.id)
                                    ? 'bg-green-100 text-green-700 border-2 border-green-300'
                                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200',
                            ]"
                        >
                            <component
                                :is="section.icon"
                                class="h-4 w-4 sm:h-5 sm:w-5 mr-1 sm:mr-2"
                            />
                            <span class="hidden sm:inline">{{
                                section.title
                            }}</span>
                            <span class="sm:hidden">{{ index + 1 }}</span>
                            <CheckCircle
                                v-if="completedSections.includes(section.id)"
                                class="ml-1 h-3 w-3 sm:h-4 sm:w-4 text-green-500"
                            />
                        </button>
                        <div
                            v-if="index < sections.length - 1"
                            class="hidden sm:block w-4 h-0.5 bg-gray-300 mx-2"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Main Content -->
            <main class="space-y-8">
                <!-- Hero Section -->
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold text-gray-900 sm:text-4xl">
                        Welcome to Property Onboarding
                    </h1>
                    <p class="mt-3 text-lg text-gray-600 max-w-2xl mx-auto">
                        We'll guide you through a simple process to set up your
                        property for management. Your responses help us provide
                        the best service for your investment.
                    </p>
                </div>

                <!-- Property Search Section -->
                <section id="search">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-blue-100 rounded-lg">
                                    <Home class="h-6 w-6 text-blue-600" />
                                </div>
                                <div>
                                    <CardTitle>Find Your Property</CardTitle>
                                    <CardDescription>
                                        Let's start by locating your property in
                                        our system
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <Alert>
                                <Info class="h-4 w-4" />
                                <AlertTitle>Important</AlertTitle>
                                <AlertDescription>
                                    Please ensure the information matches
                                    exactly with your Propertyware records.
                                </AlertDescription>
                            </Alert>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div class="space-y-2">
                                    <label class="text-sm font-medium">
                                        Full Name
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <Input
                                        v-model="fullName"
                                        placeholder="John Doe"
                                        class="w-full"
                                    />
                                    <p class="text-xs text-gray-500">
                                        As registered in Propertyware
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium">
                                        Contact Number
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <Input
                                        v-model="contactNumber"
                                        placeholder="1234567890"
                                        class="w-full"
                                    />
                                    <p class="text-xs text-gray-500">
                                        10-digit phone number
                                    </p>
                                </div>

                                <div class="space-y-2 md:col-span-2">
                                    <label class="text-sm font-medium">
                                        Property Name
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <Input
                                        v-model="propertyName"
                                        placeholder="123 Main Street"
                                        class="w-full"
                                    />
                                    <p class="text-xs text-gray-500">
                                        Exact property name or address
                                    </p>
                                </div>
                            </div>

                            <Button
                                @click="searchProperty"
                                :disabled="loading"
                                class="w-full sm:w-auto"
                            >
                                <Building class="w-4 h-4 mr-2" />
                                {{
                                    loading ? "Searching..." : "Search Property"
                                }}
                            </Button>

                            <!-- Search Results -->
                            <div v-if="building" class="mt-6">
                                <Alert class="border-green-200 bg-green-50">
                                    <CheckCircle
                                        class="h-4 w-4 text-green-600"
                                    />
                                    <AlertTitle class="text-green-800"
                                        >Property Found!</AlertTitle
                                    >
                                    <AlertDescription class="text-green-700">
                                        <div class="mt-2 space-y-1">
                                            <p
                                                class="font-semibold flex gap-2 items-center"
                                            >
                                                <Building class="w-4 h-4" />
                                                {{ buildingInfo.name }}
                                            </p>
                                            <p
                                                class="text-sm flex gap-2 items-center"
                                            >
                                                <MapIcon class="w-4 h-4" />
                                                {{
                                                    buildingInfo.address.address
                                                }}
                                                {{ buildingInfo.address.city }},
                                                {{
                                                    buildingInfo.address
                                                        .stateRegion
                                                }}
                                                {{
                                                    buildingInfo.address
                                                        .postalCode
                                                }}
                                            </p>
                                        </div>
                                    </AlertDescription>
                                </Alert>
                            </div>
                        </CardContent>
                    </Card>
                </section>

                <!-- Property Preparation Section -->
                <section id="preparation" v-if="building">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-purple-100 rounded-lg">
                                    <PaintBucket
                                        class="h-6 w-6 text-purple-600"
                                    />
                                </div>
                                <div>
                                    <CardTitle>Property Preparation</CardTitle>
                                    <CardDescription>
                                        Let's make your property market-ready
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div
                                class="prose prose-sm max-w-none text-gray-600"
                            >
                                <p>
                                    A well-presented property attracts quality
                                    tenants and commands better rent. We'll help
                                    you prepare your property for success.
                                </p>
                                <p>
                                    We want to make sure that your home is
                                    presentable during the marketing phase.
                                    There are a few key areas that need to be
                                    taken care of prior to placing your home on
                                    the market. A neat, clean home will help you
                                    to attract the best possible tenant.
                                </p>
                            </div>
                            <Alert class="mb-4">
                                <Info class="h-4 w-4" />

                                <AlertDescription>
                                    <div class="space-y-2">
                                        <p>
                                            <span class="font-bold underline"
                                                >Curb Appeal:</span
                                            >
                                            The first thing that someone will
                                            see when they pull up to the home is
                                            the lawn. Your home will lease
                                            faster if the yard is cut and the
                                            bushes & trees are trimmed. Also, if
                                            the home has any mildew or mold it
                                            should be cleaned or pressure
                                            washed.
                                        </p>
                                    </div>
                                </AlertDescription>
                            </Alert>
                            <Alert class="mb-4">
                                <Info class="h-4 w-4" />

                                <AlertDescription>
                                    <div class="space-y-2">
                                        <p>
                                            <span class="font-bold underline"
                                                >Flooring:</span
                                            >
                                            The flooring should be free of
                                            obvious defects, large stains, and
                                            pet odors.
                                        </p>
                                    </div>
                                </AlertDescription>
                            </Alert>

                            <div class="space-y-4">
                                <h3
                                    class="font-semibold flex items-center gap-2"
                                >
                                    <Sparkles class="h-5 w-5 text-yellow-500" />
                                    Paint & Aesthetics
                                </h3>
                                <Alert class="mb-4">
                                    <Info class="h-4 w-4" />

                                    <AlertDescription>
                                        <div class="space-y-2">
                                            <p>
                                                <span
                                                    class="font-bold underline"
                                                    >Walls:</span
                                                >
                                                Neutral colors will appeal to
                                                the most people. Brighter colors
                                                may be acceptable in some areas
                                                of the home. While it is not a
                                                requirement that you repaint the
                                                property, you may want to
                                                consider it if you have numerous
                                                scuff marks, holes, or rooms
                                                that have colors that might not
                                                appeal to a large group of
                                                people. If you have the paint
                                                code for your wall, please
                                                include them in the space
                                                provided below.
                                            </p>
                                        </div>
                                    </AlertDescription>
                                </Alert>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Apply New Paint?</label
                                        >
                                        <Select v-model="form.paint">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="No"
                                                    >No - Current paint is
                                                    fine</SelectItem
                                                >
                                                <SelectItem value="Yes"
                                                    >Yes - Needs new
                                                    paint</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div
                                        v-if="form.paint === 'Yes'"
                                        class="space-y-2"
                                    >
                                        <label class="text-sm font-medium"
                                            >Paint Color</label
                                        >
                                        <div class="flex items-center gap-3">
                                            <input
                                                type="color"
                                                v-model="form.paintColor"
                                                class="h-10 w-20 rounded border cursor-pointer"
                                            />
                                            <span class="text-sm">{{
                                                form.paintColor ||
                                                "Select color"
                                            }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <Alert class="mb-4">
                                <Info class="h-4 w-4" />

                                <AlertDescription>
                                    <div class="space-y-2">
                                        <p>
                                            <span class="font-bold underline"
                                                >Valuables:</span
                                            >
                                            Crime can happen at any time. Please
                                            help to minimize the risk by putting
                                            away or removing valuables and fire
                                            arms from the property.
                                        </p>
                                    </div>
                                </AlertDescription>
                            </Alert>
                            <Alert class="mb-4">
                                <Info class="h-4 w-4" />

                                <AlertDescription>
                                    <div class="space-y-2">
                                        <p>
                                            <span class="font-bold underline"
                                                >Insurance:</span
                                            >
                                            Make sure to contact your insurance
                                            company to learn about your
                                            insurance policy. If you are moving
                                            out of the property, and it will be
                                            vacant for any period, let your
                                            insurance company know. Some
                                            policies do not cover vandalism when
                                            home is vacant
                                        </p>
                                    </div>
                                </AlertDescription>
                            </Alert>
                            <Alert class="mb-4">
                                <Info class="h-4 w-4" />

                                <AlertDescription>
                                    <div class="space-y-2">
                                        <p>
                                            <span class="font-bold underline"
                                                >Utilities:</span
                                            >
                                            We require that utilities are turned
                                            on. If vacant, we ask that you shut
                                            off water at the main cut off valve
                                            to prevent water leaks that might
                                            otherwise go unnoticed before
                                            causing significant damage. It's
                                            Texas, and it's hot. Prospective
                                            tenant's viewing your home will turn
                                            down your AC to see if it blows cold
                                            air, and will then walk out without
                                            turning the thermostat back up or
                                            off. Make sure that you have
                                            programmable thermostat that
                                            automatically go back to a
                                            reasonable temperature, so that you
                                            don't receive a surprise electric
                                            bill for a vacant home.
                                        </p>
                                    </div>
                                </AlertDescription>
                            </Alert>
                            <div
                                class="prose prose-sm max-w-none text-gray-600"
                            >
                                <p class="mb-4">
                                    For the above items, we are happy to arrange
                                    the services below. Please let us know by
                                    indicating in the appropriate box with your
                                    initials, if you would like to take care of
                                    the above items before the property goes on
                                    the market, or if you would like us to
                                    handle them for you.
                                </p>
                                <p>
                                    The following items are only for the initial
                                    lease term. After the initial tenant moves
                                    out, if TexasRenters.com is still managing
                                    the property, will handle utilities, lawn
                                    care, carpet clearning and cleaning unless
                                    otherwise instructed at that tme, per the
                                    property management agreement.
                                </p>
                            </div>

                            <Button
                                @click="markSectionCompleted('preparation')"
                                variant="outline"
                                class="w-full sm:w-auto"
                            >
                                <CheckCircle class="w-4 h-4 mr-2" />
                                Mark Section Complete
                            </Button>
                        </CardContent>
                    </Card>
                </section>

                <!-- Service Responsibilities Section -->
                <section id="services" v-if="building">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-orange-100 rounded-lg">
                                    <Wrench class="h-6 w-6 text-orange-600" />
                                </div>
                                <div>
                                    <CardTitle
                                        >Service Responsibilities</CardTitle
                                    >
                                    <CardDescription>
                                        Define who handles what during each
                                        phase
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div>
                                <h3
                                    class="font-semibold mb-4 flex items-center gap-2"
                                >
                                    <Calendar class="h-5 w-5" />
                                    Pre-Market Services
                                </h3>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Lawn Care</label
                                        >
                                        <Select
                                            v-model="
                                                form.goingOnTheMarketLawnCare
                                            "
                                        >
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select responsibility"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Management"
                                                    >Management will
                                                    handle</SelectItem
                                                >
                                                <SelectItem value="Owner"
                                                    >I'll handle it</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Professional Cleaning</label
                                        >
                                        <Select
                                            v-model="
                                                form.goingOnTheMarketCleaning
                                            "
                                        >
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select responsibility"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Management"
                                                    >Management will
                                                    handle</SelectItem
                                                >
                                                <SelectItem value="Owner"
                                                    >I'll handle it</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            </div>

                            <Button
                                @click="markSectionCompleted('services')"
                                variant="outline"
                                class="w-full sm:w-auto"
                            >
                                <CheckCircle class="w-4 h-4 mr-2" />
                                Mark Section Complete
                            </Button>
                        </CardContent>
                    </Card>
                </section>

                <!-- Security & Safety Section -->
                <section id="security" v-if="building">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-red-100 rounded-lg">
                                    <Shield class="h-6 w-6 text-red-600" />
                                </div>
                                <div>
                                    <CardTitle>Security & Safety</CardTitle>
                                    <CardDescription>
                                        Alarm systems and security features
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div>
                                <h3 class="font-semibold mb-4">Alarm System</h3>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Is there an Alarm System?</label
                                        >
                                        <Select v-model="form.alarmSystem">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
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
                                        <Select
                                            v-model="
                                                form.alarmSystemIncludedInPrice
                                            "
                                        >
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
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
                                        <Select
                                            v-model="
                                                form.alarmSystemUnderContract
                                            "
                                        >
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
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
                                            v-model="
                                                form.alarmSystemBeArmDuringMarketing
                                            "
                                        >
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h3 class="font-semibold mb-4">
                                    Re-Key & Code Work
                                </h3>
                                <Alert class="mb-4">
                                    <Info class="h-4 w-4" />

                                    <AlertDescription>
                                        <div class="space-y-2">
                                            <p>
                                                <span
                                                    class="font-bold underline"
                                                    >Re-Key & Code Work:</span
                                                >
                                                State law requires a working
                                                smoke detector in each bedroom
                                                and each hallway servicing more
                                                than one bedroom along with a
                                                peep hole and keyless locking
                                                device on each exterior door.
                                                Homes must be re-keyed between
                                                each tenant.
                                            </p>
                                            <p>
                                                <span
                                                    class="font-bold underline"
                                                    >This is a very large
                                                    liability issue, therefore,
                                                    if the items have not been
                                                    completed prior to our move
                                                    in inspection, the
                                                    management company will
                                                    automatically complete the
                                                    items.</span
                                                >
                                            </p>
                                        </div>
                                    </AlertDescription>
                                </Alert>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium"
                                        >Re-Key & Code Work
                                        Responsibility</label
                                    >
                                    <Select v-model="form.reKey">
                                        <SelectTrigger>
                                            <SelectValue
                                                placeholder="Select responsibility"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Management"
                                                >Management will
                                                handle</SelectItem
                                            >
                                            <SelectItem value="Owner"
                                                >I'll handle it</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <Button
                                @click="markSectionCompleted('security')"
                                variant="outline"
                                class="w-full sm:w-auto"
                            >
                                <CheckCircle class="w-4 h-4 mr-2" />
                                Mark Section Complete
                            </Button>
                        </CardContent>
                    </Card>
                </section>

                <!-- Policies & Rules Section -->
                <section id="policies" v-if="building">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-green-100 rounded-lg">
                                    <Clipboard class="h-6 w-6 text-green-600" />
                                </div>
                                <div>
                                    <CardTitle>Policies & Rules</CardTitle>
                                    <CardDescription>
                                        Set your preferences for tenant policies
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div>
                                <h3 class="font-semibold mb-4">Pet Policy</h3>
                                <Alert variant="secondary" class="mb-4">
                                    <Info class="h-4 w-4" />
                                    <AlertDescription>
                                        <p>
                                            <span class="font-bold underline"
                                                >Pets:</span
                                            >
                                            Many renters are choosing to live in
                                            a home versus an apartment because
                                            they have pets. This reality means
                                            that if you do not accept pets, it
                                            will take about twice as long to
                                            rent your home if you do not allow
                                            pets. However, this is of course
                                            your choice. Please let us know if
                                            you would accept pets, and what type
                                            below.
                                        </p>
                                    </AlertDescription>
                                </Alert>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Dogs Allowed?</label
                                        >
                                        <Select v-model="form.dogsAllowed">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes - Dogs
                                                    allowed</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No dogs</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Cats Allowed?</label
                                        >
                                        <Select v-model="form.catsAllowed">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes - Cats
                                                    allowed</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No cats</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div
                                        class="space-y-2"
                                        v-if="form.dogsAllowed === 'Yes'"
                                    >
                                        <label class="text-sm font-medium"
                                            >Maximum Dog Weight (lbs)</label
                                        >
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
                                            >Cat Restrictions</label
                                        >
                                        <Input
                                            v-model="form.catRestrictions"
                                            placeholder="Any specific restrictions"
                                        />
                                    </div>
                                </div>

                                <div class="space-y-2 mt-4">
                                    <label class="text-sm font-medium"
                                        >Other Pet Restrictions</label
                                    >
                                    <Textarea
                                        v-model="form.otherPetsRestriction"
                                        placeholder="Any other pet-related restrictions or policies"
                                        rows="2"
                                    />
                                </div>
                            </div>

                            <div>
                                <h3 class="font-semibold mb-4">
                                    Service Request Policy
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
                                            <SelectItem value="$75 Co-Pay"
                                                >$75 Tenant
                                                Co-Payment</SelectItem
                                            >
                                            <SelectItem value="Owner Handles"
                                                >Owner Handles
                                                Directly</SelectItem
                                            >
                                            <SelectItem
                                                value="Management Handles"
                                                >Management Handles
                                                Everything</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <Button
                                @click="markSectionCompleted('policies')"
                                variant="outline"
                                class="w-full sm:w-auto"
                            >
                                <CheckCircle class="w-4 h-4 mr-2" />
                                Mark Section Complete
                            </Button>
                        </CardContent>
                    </Card>
                </section>

                <!-- Amenities & Features Section -->
                <section id="amenities" v-if="building">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-indigo-100 rounded-lg">
                                    <Sparkles class="h-6 w-6 text-indigo-600" />
                                </div>
                                <div>
                                    <CardTitle>Amenities & Features</CardTitle>
                                    <CardDescription>
                                        Property features and neighborhood
                                        amenities
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <Alert>
                                <Info class="h-4 w-4" />
                                <AlertDescription>
                                    <p>
                                        <span
                                            >We need to know more about your
                                            home so that we can properly market
                                            the home and give the new tenant
                                            information on how to take care of
                                            the home.</span
                                        >
                                    </p>
                                </AlertDescription>
                            </Alert>
                            <div>
                                <h3 class="font-semibold mb-4">
                                    Swimming Pool
                                </h3>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Swimming Pool Present?</label
                                        >
                                        <Select v-model="form.swimmingPool">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes - Has pool</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No pool</SelectItem
                                                >
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
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes - Service
                                                    included</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No - Tenant
                                                    responsible</SelectItem
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
                                <h3 class="font-semibold mb-4">
                                    Neighborhood Amenities
                                </h3>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Community Pool?</label
                                        >
                                        <Select v-model="form.communityPool">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Nearby Park?</label
                                        >
                                        <Select v-model="form.park">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Playground?</label
                                        >
                                        <Select v-model="form.playGround">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Tennis Court?</label
                                        >
                                        <Select v-model="form.tennisCourt">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
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
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Microwave Included?</label
                                        >
                                        <Select v-model="form.microwave">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Washing Machine Included?</label
                                        >
                                        <Select v-model="form.washingMachine">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Washing Machine Hookups?</label
                                        >
                                        <Select
                                            v-model="form.washingMachineHookups"
                                        >
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Gas"
                                                    >Gas</SelectItem
                                                >
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
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Dryer Hookups?</label
                                        >
                                        <Select v-model="form.dryerHookups">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Gas"
                                                    >Gas</SelectItem
                                                >
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
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
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

                            <div>
                                <h3 class="font-semibold mb-4">
                                    Garage Access & Mailbox
                                </h3>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Garage Door Opener?</label
                                        >
                                        <Select v-model="form.garageDoorOpener">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
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

                            <Button
                                @click="markSectionCompleted('amenities')"
                                variant="outline"
                                class="w-full sm:w-auto"
                            >
                                <CheckCircle class="w-4 h-4 mr-2" />
                                Mark Section Complete
                            </Button>
                        </CardContent>
                    </Card>
                </section>

                <!-- Utilities & Maintenance Section -->
                <section id="utilities" v-if="building">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-yellow-100 rounded-lg">
                                    <Wrench class="h-6 w-6 text-yellow-600" />
                                </div>
                                <div>
                                    <CardTitle
                                        >Utilities & Maintenance</CardTitle
                                    >
                                    <CardDescription>
                                        Utility providers and maintenance
                                        preferences
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div>
                                <h3 class="font-semibold mb-4">
                                    Service Responsibilities
                                </h3>
                                <Alert class="mb-4">
                                    <Info class="h-4 w-4" />

                                    <AlertDescription>
                                        The following responsibilities are only
                                        for the initial lease term.
                                    </AlertDescription>
                                </Alert>

                                <div class="space-y-6">
                                    <div>
                                        <h4 class="font-medium mb-3">
                                            Prior to Marketing
                                        </h4>
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
                                                    >Lawn Care</label
                                                >
                                                <Select
                                                    v-model="
                                                        form.goingOnTheMarketLawnCare
                                                    "
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue
                                                            placeholder="Who handles?"
                                                        />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
                                                    >Professional
                                                    Cleaning</label
                                                >
                                                <Select
                                                    v-model="
                                                        form.goingOnTheMarketCleaning
                                                    "
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue
                                                            placeholder="Who handles?"
                                                        />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
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
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
                                                    >Paint</label
                                                >
                                                <Select
                                                    v-model="
                                                        form.goingOnTheMarketPaint
                                                    "
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue
                                                            placeholder="Who handles?"
                                                        />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
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
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
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
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
                                                    >Utilities</label
                                                >
                                                <Select
                                                    v-model="
                                                        form.goingOnTheMarketUtilities
                                                    "
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue
                                                            placeholder="Who handles?"
                                                        />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 class="font-medium mb-3">
                                            While on Market
                                        </h4>
                                        <Alert class="mb-4">
                                            <Info class="h-4 w-4" />
                                            <AlertDescription>
                                                <span
                                                    class="font-bold underline"
                                                    >During the time the
                                                    property is on the
                                                    market:</span
                                                >
                                                It is important that the lawn
                                                and home stay in good shape
                                                while the home is on the market.
                                                Please indicate below if you
                                                would like to be responsible for
                                                the below items, or if you would
                                                like us to handle it for you.
                                            </AlertDescription>
                                        </Alert>
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
                                                    >Lawn Care</label
                                                >
                                                <Select
                                                    v-model="
                                                        form.homeOnTheMarketLawnCare
                                                    "
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue
                                                            placeholder="Who handles?"
                                                        />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
                                                    >Cleaning</label
                                                >
                                                <Select
                                                    v-model="
                                                        form.homeOnTheMarketCleaning
                                                    "
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue
                                                            placeholder="Who handles?"
                                                        />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
                                                    >Utilities</label
                                                >
                                                <Select
                                                    v-model="
                                                        form.homeOnTheMarketUtilities
                                                    "
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue
                                                            placeholder="Who handles?"
                                                        />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 class="font-medium mb-3">
                                            Before Tenant Move-In
                                        </h4>
                                        <Alert class="mb-4">
                                            <Info class="h-4 w-4" />

                                            <AlertDescription>
                                                <div class="space-y-2">
                                                    <p>
                                                        <span
                                                            class="font-bold underline"
                                                            >Final Touches
                                                            before Tenant Move
                                                            in:</span
                                                        >
                                                        We require that all
                                                        homes be profesionally
                                                        cleaned, and carpets
                                                        thermostat are not new
                                                        be professionally
                                                        cleaned, a final lawn
                                                        cut be done and pest
                                                        control prior to move
                                                        in. We expect that when
                                                        a tenant moves out of
                                                        the home, they will also
                                                        have the home
                                                        professionally cleaned
                                                        and carpets steam
                                                        cleaned. By having the
                                                        home professionally
                                                        cleaned, and being able
                                                        to provide receipts, we
                                                        can help assure that as
                                                        the home owner, you will
                                                        not be paying to clean
                                                        up after tenants in the
                                                        future. In most cases,
                                                        this will be the only
                                                        time that you need to
                                                        pay for cleaning. If the
                                                        carpets were not
                                                        professionally cleaned
                                                        prior to putting the
                                                        home on the market, this
                                                        is the time to do it. We
                                                        do however, allow you to
                                                        choose between us
                                                        providing the service,
                                                        and you arranging the
                                                        service on your own. If
                                                        you arrange the service,
                                                        please provide receipts
                                                        for our records.
                                                    </p>
                                                </div>
                                            </AlertDescription>
                                        </Alert>
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
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
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div class="space-y-2">
                                                <label
                                                    class="text-sm font-medium"
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
                                                        <SelectItem
                                                            value="Management"
                                                            >Management will
                                                            handle</SelectItem
                                                        >
                                                        <SelectItem
                                                            value="Owner"
                                                            >I'll handle
                                                            this</SelectItem
                                                        >
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 class="font-medium mb-3">
                                            After Tenant Move-In
                                        </h4>
                                        <div class="space-y-2">
                                            <label class="text-sm font-medium"
                                                >Ongoing Lawn Care</label
                                            >
                                            <Select
                                                v-model="
                                                    form.afterTenantMoveInLawnCare
                                                "
                                            >
                                                <SelectTrigger>
                                                    <SelectValue
                                                        placeholder="Who handles?"
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="Tenant"
                                                        >Tenant will handle
                                                        this</SelectItem
                                                    >
                                                    <SelectItem value="Owner"
                                                        >I'll handle
                                                        this</SelectItem
                                                    >
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <Button
                                @click="markSectionCompleted('utilities')"
                                variant="outline"
                                class="w-full sm:w-auto"
                            >
                                <CheckCircle class="w-4 h-4 mr-2" />
                                Mark Section Complete
                            </Button>
                        </CardContent>
                    </Card>
                </section>

                <!-- Preferred Vendors Section -->
                <section id="vendors" v-if="building">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-purple-100 rounded-lg">
                                    <Users class="h-6 w-6 text-purple-600" />
                                </div>
                                <div>
                                    <CardTitle>Preferred Vendors</CardTitle>
                                    <CardDescription>
                                        Your trusted service providers
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <Alert class="mb-4">
                                <Info class="h-4 w-4" />
                                <AlertDescription>
                                    <span class="font-bold underline"
                                        >Preferred Vendors.</span
                                    >
                                    If you have a preferred vendor, we will use
                                    them for non-emergency repairs, and make a
                                    reasonable effor to tuse them for emergency
                                    repairs. If your preferred vendor cannot be
                                    reached in a situation where delay would
                                    cause damage to your property or harm to the
                                    tenant, we will select a vendor who can get
                                    the job completed as soon as possible.
                                </AlertDescription>
                            </Alert>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div class="space-y-4 border p-3">
                                    <h3 class="font-semibold">HVAC</h3>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Company Name</label
                                        >
                                        <Input
                                            v-model="form.hvacVendorName"
                                            placeholder="HVAC company name"
                                        />
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Phone Number</label
                                        >
                                        <Input
                                            v-model="form.hvacVendorNumber"
                                            placeholder="Phone number"
                                        />
                                    </div>
                                </div>

                                <div class="space-y-4 border p-3">
                                    <h3 class="font-semibold">Electrical</h3>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Company Name</label
                                        >
                                        <Input
                                            v-model="form.electricVendorName"
                                            placeholder="Electrician company name"
                                        />
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Phone Number</label
                                        >
                                        <Input
                                            v-model="form.electricVendorNumber"
                                            placeholder="Phone number"
                                        />
                                    </div>
                                </div>

                                <div class="space-y-4 border p-3">
                                    <h3 class="font-semibold">Plumbing</h3>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Company Name</label
                                        >
                                        <Input
                                            v-model="form.plumbingVendorName"
                                            placeholder="Plumbing company name"
                                        />
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Phone Number</label
                                        >
                                        <Input
                                            v-model="form.plumbingVendorNumber"
                                            placeholder="Phone number"
                                        />
                                    </div>
                                </div>

                                <div class="space-y-4 border p-3">
                                    <h3 class="font-semibold">Pest Control</h3>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Company Name</label
                                        >
                                        <Input
                                            v-model="form.pestControlVendorName"
                                            placeholder="Pest control company name"
                                        />
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Phone Number</label
                                        >
                                        <Input
                                            v-model="
                                                form.pestControlVenodrNumber
                                            "
                                            placeholder="Phone number"
                                        />
                                    </div>
                                </div>

                                <div class="space-y-4 border p-3">
                                    <h3 class="font-semibold">Lawn Care</h3>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Company Name</label
                                        >
                                        <Input
                                            v-model="form.lawnCareVendorName"
                                            placeholder="Lawn care company name"
                                        />
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Phone Number</label
                                        >
                                        <Input
                                            v-model="form.lawnCareVendorNumber"
                                            placeholder="Phone number"
                                        />
                                    </div>
                                </div>

                                <div class="space-y-4 border p-3">
                                    <h3 class="font-semibold">Other Service</h3>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Company Name</label
                                        >
                                        <Input
                                            v-model="form.otherVendorName"
                                            placeholder="Other service company name"
                                        />
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Phone Number</label
                                        >
                                        <Input
                                            v-model="form.otherVendorNumber"
                                            placeholder="Phone number"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <h3 class="font-semibold">
                                    Utilities & Services
                                </h3>
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

                            <div class="space-y-4">
                                <h3 class="font-semibold">
                                    HVAC & Home Warranty
                                </h3>
                                <Alert class="mb-4">
                                    <Info class="h-4 w-4" />
                                    <AlertDescription>
                                        <div class="space-y-3">
                                            <p>
                                                <span
                                                    class="font-bold underline"
                                                    >HVAC Maintenance:</span
                                                >
                                                The most common cause for damage
                                                to homes that we see is from the
                                                emergency drain line overflowing
                                                from the air-conditioning drain
                                                pan. This is caused from mold or
                                                other debris clogging up the
                                                primary and secondary drain
                                                lines. This can be prevented by
                                                doing 2 things. First, the HVAC
                                                system can be serviced every 6
                                                months, which includes blowing
                                                out the drain lines, cleaning
                                                exterior coils, checking for
                                                carbon monoxide leaks, and
                                                changing your air filter (all of
                                                this also extends the life of
                                                and increases the efficiency of
                                                your HVAC system). Second, an
                                                emergency float switch will be
                                                installed in the pan, so that if
                                                water builds up in the pan, the
                                                HVAC systems will shut off and
                                                stop producing water. The HVAC
                                                inspections are included on the
                                                premium plan and a float switch
                                                will be added if there is not
                                                already one in place.
                                            </p>
                                        </div>
                                    </AlertDescription>
                                </Alert>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Participate in HVAC Maintenance
                                            Plan? <br />
                                            (Included only on Premium
                                            Plan)</label
                                        >
                                        <Select
                                            v-model="form.hvacMaintenancePlan"
                                        >
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Install Float Switch? <br />
                                            (Automatically Completed)</label
                                        >
                                        <Select
                                            v-model="form.installFloatSwitch"
                                        >
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <Alert class="mb-4">
                                    <Info class="h-4 w-4" />
                                    <AlertDescription>
                                        <div class="space-y-3">
                                            <p>
                                                <span
                                                    class="font-bold underline"
                                                    >Home Warranty: We generally
                                                    do not recommend them,
                                                    because the responsiveness,
                                                    and quality of the service
                                                    are not often up to
                                                    par.</span
                                                >
                                                They usually have 2 business
                                                days to respond to an
                                                air-conditioning service call
                                                (respond not complete), which
                                                means that if your tenant calls
                                                on Friday, they may not get a
                                                response until Tuesday. Also, if
                                                you have a plan or decide to get
                                                a plan, please read the
                                                exclusions and limitations very
                                                closely. Our experience is that
                                                many of the plans cap the most
                                                expensive such as the air
                                                air-conditioning at a set
                                                amount, and stick you with their
                                                vendor to install the new
                                                system, who usually charges 2x
                                                what we can get it done for.
                                                Also, a repair may be covered,
                                                but damage caused by the repair
                                                or malfunction, along with
                                                bringing older items up to code
                                                may not be covered. That being
                                                said, some would prefer the
                                                comfort of knowing that many
                                                cost can be covered. If you do
                                                not have a home warranty, get
                                                our advice on who to pick first.
                                                If you already have one, please
                                                include the information below,
                                                so that we can use the home
                                                warranty if your home needs
                                                service.
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
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes - Have
                                                    warranty</SelectItem
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
                                            v-model="
                                                form.homeWarrantyCompanyName
                                            "
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
                                            v-model="
                                                form.homeWarrantyServiceNumber
                                            "
                                            placeholder="Service request number"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <h3 class="font-semibold">Property History</h3>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="space-y-2">
                                        <label class="text-sm font-medium"
                                            >Ever Flooded?</label
                                        >
                                        <Select v-model="form.floodedProperty">
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder="Select option"
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Yes"
                                                    >Yes - Has
                                                    flooded</SelectItem
                                                >
                                                <SelectItem value="No"
                                                    >No flooding
                                                    history</SelectItem
                                                >
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div
                                        class="space-y-2"
                                        v-if="form.floodedProperty === 'Yes'"
                                    >
                                        <label class="text-sm font-medium"
                                            >Last Flood Date</label
                                        >
                                        <Input
                                            type="date"
                                            v-model="form.floodedPropertyDate"
                                        />
                                    </div>
                                </div>
                            </div>

                            <Button
                                @click="markSectionCompleted('vendors')"
                                variant="outline"
                                class="w-full sm:w-auto"
                            >
                                <CheckCircle class="w-4 h-4 mr-2" />
                                Mark Section Complete
                            </Button>
                        </CardContent>
                    </Card>
                </section>

                <!-- Final Submit Section -->
                <section id="signature" v-if="building">
                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div class="p-2 bg-green-100 rounded-lg">
                                    <CheckCircle
                                        class="h-6 w-6 text-green-600"
                                    />
                                </div>
                                <div>
                                    <CardTitle
                                        >Final Details & Signature</CardTitle
                                    >
                                    <CardDescription>
                                        Almost done! Add any additional
                                        information and sign
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div class="space-y-2">
                                <Alert class="mb-4">
                                    <Info class="h-4 w-4" />
                                    <AlertDescription>
                                        If there is anything not Covered above,
                                        please let us know if the space below.
                                        This would also include any repairs or
                                        bids that you would like us to have
                                        completed for you.
                                    </AlertDescription>
                                </Alert>
                                <label class="text-sm font-medium"
                                    >Additional Information</label
                                >
                                <Textarea
                                    v-model="form.otherComments"
                                    placeholder="Any other details we should know about your property?"
                                    class="min-h-[100px]"
                                />
                            </div>

                            <div class="border-t pt-6">
                                <h3 class="font-semibold mb-4">
                                    Electronic Signature
                                </h3>
                                <p class="text-sm text-gray-600 mb-4">
                                    Please sign below to authorize and verify
                                    the information provided.
                                </p>

                                <div class="border rounded-lg overflow-hidden">
                                    <Vue3Signature
                                        ref="signature1"
                                        :h="'250px'"
                                        :sigOption="state.option"
                                        :disabled="state.disabled"
                                        class="w-full bg-gray-50"
                                    />
                                </div>

                                <div class="flex gap-2 mt-4">
                                    <Button
                                        @click="clear"
                                        variant="outline"
                                        size="sm"
                                        >Clear Signature</Button
                                    >
                                    <Button
                                        @click="undo"
                                        variant="outline"
                                        size="sm"
                                        >Undo</Button
                                    >
                                </div>
                            </div>

                            <div class="border-t pt-6">
                                <Button
                                    @click="submitForm"
                                    :disabled="loading || !building"
                                    size="lg"
                                    class="w-full"
                                >
                                    <CheckCircle class="w-5 h-5 mr-2" />
                                    {{
                                        loading
                                            ? "Submitting..."
                                            : "Complete Property Setup"
                                    }}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </section>
            </main>
        </div>

        <!-- Modern Footer -->
        <footer class="bg-gray-50 border-t mt-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="text-center text-sm text-gray-600">
                    <p>
                        &copy; 2025
                        <a
                            href="https://www.texasrenters.com/"
                            target="_blank"
                            class="font-bold text-primary"
                            >TexasRenters.com</a
                        >. All rights reserved.
                    </p>
                    <p class="mt-2">
                        Need help? Call us at 1-800-XXX-XXXX or email
                        support@example.com
                    </p>
                    <p>
                        Developed and maintained by Texas Renters IT Department.
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <Toaster />
</template>

<style scoped>
/* Add smooth scrolling for better UX */
html {
    scroll-behavior: smooth;
}

/* Custom animation for progress sections */
.section-transition {
    transition: all 0.3s ease;
}

/* Make signature canvas more visible */
:deep(.vue3-signature) {
    border: 2px dashed #e5e7eb;
    border-radius: 0.5rem;
}

/* Improve print styles */
@media print {
    .no-print {
        display: none;
    }
}
</style>
