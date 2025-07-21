<script setup>
import { Button } from "@/Components/ui/button";
import { Checkbox } from "@/Components/ui/checkbox";
import Input from "@/Components/ui/input/Input.vue";
import { Textarea } from "@/Components/ui/textarea";
import { Head, useForm } from "@inertiajs/vue3";
import { reactive, ref, computed, watch, onMounted, onUnmounted } from "vue";
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
    MessageCircle,
    ChevronRight,
    ChevronLeft,
    Loader,
    Menu,
    X,
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
import { RadioGroup, RadioGroupItem } from "@/Components/ui/radio-group";
import { Label } from "@/Components/ui/label";

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
    lockboxCode: "",
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
const buildings = ref([]);
const selectedBuildingId = ref(null);
const building = computed(() => {
    if (!selectedBuildingId.value || buildings.value.length === 0) return null;
    return buildings.value.find((b) => b.id === selectedBuildingId.value);
});

// Sidebar state
const isSidebarOpen = ref(true); // Default open on desktop
const toggleSidebar = () => {
    isSidebarOpen.value = !isSidebarOpen.value;
};
const closeSidebar = () => {
    // Only close on mobile/tablet
    if (window.innerWidth < 1280) {
        isSidebarOpen.value = false;
    }
};

// Set initial state based on screen size
onMounted(() => {
    isSidebarOpen.value = window.innerWidth >= 1280;

    // Update on resize
    const handleResize = () => {
        if (window.innerWidth >= 1280) {
            isSidebarOpen.value = true;
        }
    };
    window.addEventListener("resize", handleResize);

    onUnmounted(() => {
        window.removeEventListener("resize", handleResize);
    });
});

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

// Watch for changes in alarmSystemCode - if it has a value, set alarmSystem to "Yes"
watch(
    () => form.alarmSystemCode,
    (newValue) => {
        if (
            newValue &&
            newValue.trim() !== "" &&
            newValue !== "Not Completed"
        ) {
            form.alarmSystem = "Yes";
        }
    }
);

// Watch for changes in reKey field based on Property Re-Key custom field
watch(
    () => form.reKey,
    (newValue) => {
        // This watcher is for when form.reKey is directly modified
        // The logic in populateFormFromBuilding handles the initial mapping
    }
);

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

    // Garage Access & Mailbox
    lockboxCode: "Lockbox Code",
    mailboxKeyNo: "Mailbox Keys",

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
    buildings.value = [];
    selectedBuildingId.value = null;
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
            buildings.value = [];
            return;
        }

        // Handle the new response format
        if (res.data.buildings && Array.isArray(res.data.buildings)) {
            buildings.value = res.data.buildings;
            // Auto-select if only one building is found
            if (buildings.value.length === 1) {
                selectedBuildingId.value = buildings.value[0].id;
                loadBuildingData();
            }
        } else {
            // Fallback for old format (single building)
            buildings.value = [res.data];
            selectedBuildingId.value = res.data.id;
            loadBuildingData();
        }

        if (buildings.value.length > 1) {
            toast({
                title: "Multiple Properties Found!",
                description: `Found ${buildings.value.length} properties. Please select one to continue.`,
            });
        }
    } catch (error) {
        buildings.value = [];
        selectedBuildingId.value = null;
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

    // Handle special field mappings that require transformation

    // Paint Color - if it has a hex value like "#b11010"
    if (customFieldsMap.value["Paint Color"]?.value) {
        form.paintColor = customFieldsMap.value["Paint Color"].value;
    }

    // Service Provided -> tenantServiceRequest with reverse mapping
    if (customFieldsMap.value["Service Provided"]?.value) {
        const serviceValue = customFieldsMap.value["Service Provided"].value;
        if (serviceValue === "Lease Only") {
            form.tenantServiceRequest = "$75 Co-Pay";
        } else if (serviceValue === "Property Management") {
            form.tenantServiceRequest = "Management Handles";
        }
    }

    // Pool Service handling
    if (customFieldsMap.value["Pool Service"]?.value) {
        const poolValue = customFieldsMap.value["Pool Service"].value;
        if (poolValue === "No Pool") {
            form.swimmingPool = "No";
        } else if (poolValue === "Required Contract") {
            form.swimmingPool = "Yes";
            form.poolService = "Yes";
        } else if (poolValue === "Cared By Owner") {
            form.swimmingPool = "Yes";
            form.poolService = "No";
        }
    }

    // HVAC Plan handling
    if (customFieldsMap.value["HVAC Plan"]?.value) {
        const hvacValue = customFieldsMap.value["HVAC Plan"].value;
        if (hvacValue === "On our AC Plan") {
            form.hvacMaintenancePlan = "Yes";
        } else if (hvacValue === "Opted out HVAC Plan") {
            form.hvacMaintenancePlan = "No";
        }
    }

    // Home Warranty - extract info from the combined field
    if (
        customFieldsMap.value["Home Warranty"]?.value &&
        customFieldsMap.value["Home Warranty"].value !== "Not Completed"
    ) {
        form.homeWarranty = "Yes";
        form.homeWarrantyCompanyName =
            customFieldsMap.value["Home Warranty"].value;
    }

    // Garage/Mailbox info
    if (customFieldsMap.value["Garage Remotes_Garage Code"]?.value) {
        const garageValue =
            customFieldsMap.value["Garage Remotes_Garage Code"].value;
        if (garageValue !== "Not Completed") {
            form.garageDoorRemote = garageValue;
        }
    }

    // Gated Community Gate Code - populate Garage Door Opener field
    if (customFieldsMap.value["Gated Community? Gate Code?"]?.value) {
        const gateValue =
            customFieldsMap.value["Gated Community? Gate Code?"].value;
        if (gateValue !== "Not Completed" && gateValue !== "") {
            form.garageDoorOpener = gateValue;
        }
    }

    // Appliances - extract from "Included Appliances" field
    if (
        customFieldsMap.value["Included Appliances"]?.value &&
        customFieldsMap.value["Included Appliances"].value !== "Not Completed"
    ) {
        const appliances =
            customFieldsMap.value["Included Appliances"].value.toLowerCase();

        if (appliances.includes("refrigerator")) form.refrigerator = "Yes";
        if (appliances.includes("microwave")) form.microwave = "Yes";
        if (appliances.includes("washing") || appliances.includes("washer"))
            form.washingMachine = "Yes";
        if (appliances.includes("dryer")) form.dryer = "Yes";
        if (appliances.includes("dishwasher"))
            form.dishWasherModelYear = "Included";
        if (appliances.includes("water softener")) form.waterSoftener = "Yes";
    }

    // HVAC Filter info
    if (
        customFieldsMap.value["HVAC Filter Size 1"]?.value &&
        customFieldsMap.value["HVAC Filter Size 1"].value !== "NA"
    ) {
        form.hvacModelYear = customFieldsMap.value["HVAC Filter Size 1"].value;
    }

    // Alarm System handling - if code exists, set alarm to Yes
    if (
        customFieldsMap.value["Alarm System Code"]?.value &&
        customFieldsMap.value["Alarm System Code"].value !== "Not Completed" &&
        customFieldsMap.value["Alarm System Code"].value !== ""
    ) {
        form.alarmSystem = "Yes";
        form.alarmSystemCode = customFieldsMap.value["Alarm System Code"].value;
    }

    // Key Information - parse for alarm system details
    if (
        customFieldsMap.value["Key Information - anything we need to know"]
            ?.value &&
        customFieldsMap.value["Key Information - anything we need to know"]
            .value !== "Not Completed"
    ) {
        const keyInfo =
            customFieldsMap.value[
                "Key Information - anything we need to know"
            ].value.toLowerCase();

        // Check for alarm system included in price
        if (
            keyInfo.includes("included in price") ||
            keyInfo.includes("included in rent")
        ) {
            form.alarmSystemIncludedInPrice = "Yes";
        } else if (
            keyInfo.includes("not included") ||
            keyInfo.includes("additional cost")
        ) {
            form.alarmSystemIncludedInPrice = "No";
        }

        // Check for alarm system under contract
        if (
            keyInfo.includes("under contract") ||
            keyInfo.includes("contracted")
        ) {
            form.alarmSystemUnderContract = "Yes";
        } else if (
            keyInfo.includes("no contract") ||
            keyInfo.includes("not contracted")
        ) {
            form.alarmSystemUnderContract = "No";
        }

        // Check for alarm system armed during marketing
        if (
            keyInfo.includes("armed during marketing") ||
            keyInfo.includes("armed while marketing")
        ) {
            form.alarmSystemBeArmDuringMarketing = "Yes";
        } else if (
            keyInfo.includes("not armed") ||
            keyInfo.includes("disarmed")
        ) {
            form.alarmSystemBeArmDuringMarketing = "No";
        }
    }

    // Re-Key & Code Work Responsibility - combine Property Re-Key and Code Work fields
    const reKeyValue = customFieldsMap.value["Property Re-Key"]?.value;
    const codeWorkValue = customFieldsMap.value["Code Work"]?.value;

    // Check if either field indicates management responsibility
    const reKeyByManagement = reKeyValue && reKeyValue.includes("Management");
    const codeWorkCompleted =
        codeWorkValue && codeWorkValue !== "Not Completed";

    if (reKeyByManagement || codeWorkCompleted) {
        form.reKey = "Management";
    } else if (reKeyValue && reKeyValue.includes("Owner")) {
        form.reKey = "Owner";
    } else {
        form.reKey = "";
    }

    // Yard Care During Lease mapping
    if (
        customFieldsMap.value["Yard Care During Lease"]?.value === "By Tenant"
    ) {
        form.afterTenantMoveInLawnCare = "Tenant";
    } else if (
        customFieldsMap.value["Yard Care During Lease"]?.value === "By Owner"
    ) {
        form.afterTenantMoveInLawnCare = "Owner";
    }

    // Cleaning Service mapping
    if (customFieldsMap.value["Cleaning Service"]?.value === "By Management") {
        form.goingOnTheMarketCleaning = "Management";
    }

    // Debris Removal mapping
    if (customFieldsMap.value["Debris Removal"]?.value === "By Management") {
        form.goingOnTheMarketDebrisRemoval = "Management";
    }

    // Lawn Care During Marketing mapping
    if (
        customFieldsMap.value["Lawn Care During Marketing"]?.value ===
        "By Management"
    ) {
        form.goingOnTheMarketLawnCare = "Management";
        form.homeOnTheMarketLawnCare = "Management";
    }

    // Utilities mapping
    if (
        customFieldsMap.value["Utilities"]?.value === "By Managment" ||
        customFieldsMap.value["Utilities"]?.value === "By Management"
    ) {
        form.goingOnTheMarketUtilities = "Management";
        form.homeOnTheMarketUtilities = "Management";
    }

    // Carpet Cleaning mapping
    if (customFieldsMap.value["Carpet Cleaning"]?.value === "By Management") {
        form.goingOnTheMarketCarpetCleaning = "Management";
    }

    // Final Clean mapping
    if (customFieldsMap.value["Final Clean"]?.value === "By Management") {
        form.homeOnTheMarketCleaning = "Management";
    }

    // Carpet Care mapping
    if (customFieldsMap.value["Carpet Care"]?.value === "Management") {
        form.goingOnTheMarketCarpetReplacement = "Management";
    }

    // Painting Required mapping
    if (customFieldsMap.value["Painting Required"]?.value === "Management") {
        form.paint = "Yes";
        form.goingOnTheMarketPaint = "Management";
    }

    // Owner Pet Preferences handling
    if (
        customFieldsMap.value["Owner Pet Prefences"]?.value &&
        customFieldsMap.value["Owner Pet Prefences"].value !== "Not Completed"
    ) {
        const petPrefs =
            customFieldsMap.value["Owner Pet Prefences"].value.toLowerCase();

        // Check for dogs
        if (petPrefs.includes("dog")) {
            form.dogsAllowed = "Yes";

            // Extract weight limit if mentioned
            const weightMatch = petPrefs.match(/(\d+)\s*(lb|lbs|pound)/i);
            if (weightMatch) {
                form.dogsMaxWeight = weightMatch[1];
            }
        } else if (petPrefs.includes("no dog")) {
            form.dogsAllowed = "No";
        }

        // Check for cats
        if (petPrefs.includes("cat") && !petPrefs.includes("no cat")) {
            form.catsAllowed = "Yes";

            // Extract cat restrictions if any
            const catMatch = petPrefs.match(
                /cat[s]?\s*[-:]?\s*(.+?)(?:,|;|$)/i
            );
            if (catMatch && catMatch[1]) {
                form.catRestrictions = catMatch[1].trim();
            }
        } else if (petPrefs.includes("no cat")) {
            form.catsAllowed = "No";
        }

        // Check if no pets allowed
        if (petPrefs.includes("no pet") || petPrefs.includes("not allowed")) {
            form.dogsAllowed = "No";
            form.catsAllowed = "No";
        }

        // Store any other pet restrictions
        if (!petPrefs.includes("no pet")) {
            form.otherPetsRestriction =
                customFieldsMap.value["Owner Pet Prefences"].value;
        }
    }

    // Also check the Pet Restrictions field for additional info
    if (
        customFieldsMap.value["Pet Restrictions"]?.value &&
        customFieldsMap.value["Pet Restrictions"].value !== "Not Completed" &&
        customFieldsMap.value["Pet Restrictions"].value !== "test"
    ) {
        // If we haven't set other restrictions yet, use this field
        if (!form.otherPetsRestriction) {
            form.otherPetsRestriction =
                customFieldsMap.value["Pet Restrictions"].value;
        }
    }
};

// Function to load data for the selected building
const loadBuildingData = () => {
    if (!building.value) return;

    // Set building info
    buildingInfo.id = building.value.id;
    buildingInfo.name = building.value.name;
    buildingInfo.abbreviation = building.value.abbreviation;
    buildingInfo.address.address = building.value.address?.address ?? "";
    buildingInfo.address.addressCont =
        building.value.address?.addressCont ?? "";
    buildingInfo.address.city = building.value.address?.city ?? "";
    buildingInfo.address.country = building.value.address?.country ?? "";
    buildingInfo.address.postalCode = building.value.address?.postalCode ?? "";
    buildingInfo.address.stateRegion =
        building.value.address?.stateRegion ?? "";

    // Handle amenities data - implode from array to individual form fields
    if (building.value.amenities && Array.isArray(building.value.amenities)) {
        const amenities = building.value.amenities.map((a) => a.toLowerCase());

        // Reset amenity fields
        form.communityPool = "";
        form.park = "";
        form.playGround = "";
        form.tennisCourt = "";

        // Set amenity fields based on amenities array
        if (
            amenities.some((a) => a.includes("pool") || a.includes("swimming"))
        ) {
            form.communityPool = "Yes";
        }
        if (amenities.some((a) => a.includes("park"))) {
            form.park = "Yes";
        }
        if (
            amenities.some(
                (a) => a.includes("playground") || a.includes("play ground")
            )
        ) {
            form.playGround = "Yes";
        }
        if (
            amenities.some((a) => a.includes("tennis") || a.includes("court"))
        ) {
            form.tennisCourt = "Yes";
        }
    } else if (building.value.customFields) {
        // Check if amenities are stored in custom fields like "Neighborhood Ammenity Access"
        const neighborhoodAmenities = building.value.customFields.find(
            (field) => field.fieldName === "Neighborhood Ammenity Access"
        );

        if (
            neighborhoodAmenities?.value &&
            neighborhoodAmenities.value !== "Not Provided"
        ) {
            const amenitiesText = neighborhoodAmenities.value.toLowerCase();

            // Reset all amenity fields first
            form.communityPool = "No";
            form.park = "No";
            form.playGround = "No";
            form.tennisCourt = "No";

            // Parse the concatenated amenities and set individual fields
            if (amenitiesText.includes("community pool"))
                form.communityPool = "Yes";
            if (amenitiesText.includes("park")) form.park = "Yes";
            if (amenitiesText.includes("playground")) form.playGround = "Yes";
            if (amenitiesText.includes("tennis court"))
                form.tennisCourt = "Yes";
        } else {
            // Set all to "No" if no amenities are provided
            form.communityPool = "No";
            form.park = "No";
            form.playGround = "No";
            form.tennisCourt = "No";
        }
    }

    // Store custom fields and create ID mapping
    if (building.value.customFields) {
        customFieldsData.value = building.value.customFields;

        // Clear previous mapping
        customFieldsMap.value = {};

        // Create a map of custom field names to their data (including IDs)
        building.value.customFields.forEach((field) => {
            customFieldsMap.value[field.fieldName] = {
                definitionID: field.definitionID,
                dataType: field.dataType,
                value: field.value,
                fieldName: field.fieldName,
            };
        });

        // Pre-populate form with existing values
        populateFormFromCustomFields();

        console.log("Custom fields mapped:", customFieldsMap.value);
        toast({
            title: "Property Selected!",
            description: "You can now update the property information.",
        });

        // Mark search section as completed
        markSectionCompleted("search");
    }

    console.info("Selected property:", building.value);
};

// Function to prepare custom fields for update (only fields from our form)
const prepareCustomFieldsForUpdate = () => {
    const fieldSetDTOS = [];
    const fieldsToUpdate = {};

    Object.entries(FORM_FIELD_TO_CUSTOM_FIELD_MAPPING).forEach(
        ([formField, customFieldName]) => {
            const customField = customFieldsMap.value[customFieldName];
            const formValue = form[formField];

            // Skip empty fields - don't update them
            if (!formValue || formValue === "") {
                return;
            }

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

    // Neighborhood Ammenity Access - concatenate the 4 amenity fields
    if (customFieldsMap.value["Neighborhood Ammenity Access"]) {
        const amenities = [];
        if (form.communityPool === "Yes") amenities.push("Community Pool");
        if (form.park === "Yes") amenities.push("Park");
        if (form.playGround === "Yes") amenities.push("Playground");
        if (form.tennisCourt === "Yes") amenities.push("Tennis Court");

        const amenityValue =
            amenities.length > 0 ? amenities.join(", ") : "Not Provided";
        fieldsToUpdate["Neighborhood Ammenity Access"] = amenityValue;
    }

    // Owner Pet Prefences - concatenate dog/cat preferences
    if (customFieldsMap.value["Owner Pet Prefences"]) {
        const petPrefs = [];

        if (form.dogsAllowed === "Yes") {
            let dogText = "Dogs allowed";
            if (form.dogsMaxWeight) {
                dogText += ` (max weight: ${form.dogsMaxWeight} lbs)`;
            }
            petPrefs.push(dogText);
        }

        if (form.catsAllowed === "Yes") {
            let catText = "Cats allowed";
            if (form.catRestrictions) {
                catText += ` (${form.catRestrictions})`;
            }
            petPrefs.push(catText);
        }

        if (form.dogsAllowed === "No" && form.catsAllowed === "No") {
            petPrefs.push("No pets allowed");
        }

        // Add any other pet restrictions
        if (
            form.otherPetsRestriction &&
            form.otherPetsRestriction !== "Not Completed"
        ) {
            petPrefs.push(form.otherPetsRestriction);
        }

        const petValue =
            petPrefs.length > 0 ? petPrefs.join(", ") : "Not Completed";
        fieldsToUpdate["Owner Pet Prefences"] = petValue;
    }

    // Re-Key & Code Work Responsibility - update both Property Re-Key and Code Work fields
    if (
        form.reKey &&
        (customFieldsMap.value["Property Re-Key"] ||
            customFieldsMap.value["Code Work"])
    ) {
        if (form.reKey === "Management") {
            if (customFieldsMap.value["Property Re-Key"]) {
                fieldsToUpdate["Property Re-Key"] = "Re-Key By Management";
            }
            if (customFieldsMap.value["Code Work"]) {
                fieldsToUpdate["Code Work"] = "By Management";
            }
        } else if (form.reKey === "Owner") {
            if (customFieldsMap.value["Property Re-Key"]) {
                fieldsToUpdate["Property Re-Key"] = "Re-Key completed by Owner";
            }
            if (customFieldsMap.value["Code Work"]) {
                fieldsToUpdate["Code Work"] = "By Owner";
            }
        }
    }

    // Key Information - combine alarm system details
    if (customFieldsMap.value["Key Information - anything we need to know"]) {
        const keyInfoParts = [];

        // Add alarm system pricing info
        if (form.alarmSystemIncludedInPrice === "Yes") {
            keyInfoParts.push("Alarm system included in price");
        } else if (form.alarmSystemIncludedInPrice === "No") {
            keyInfoParts.push("Alarm system not included in price");
        }

        // Add alarm system contract info
        if (form.alarmSystemUnderContract === "Yes") {
            keyInfoParts.push("Alarm system under contract");
        } else if (form.alarmSystemUnderContract === "No") {
            keyInfoParts.push("Alarm system not under contract");
        }

        // Add alarm system marketing info
        if (form.alarmSystemBeArmDuringMarketing === "Yes") {
            keyInfoParts.push("Alarm system armed during marketing");
        } else if (form.alarmSystemBeArmDuringMarketing === "No") {
            keyInfoParts.push("Alarm system not armed during marketing");
        }

        const keyInfoValue =
            keyInfoParts.length > 0 ? keyInfoParts.join(", ") : "Not Completed";
        fieldsToUpdate["Key Information - anything we need to know"] =
            keyInfoValue;
    }

    // Gated Community Gate Code - save garage door opener value
    if (
        form.garageDoorOpener &&
        customFieldsMap.value["Gated Community? Gate Code?"]
    ) {
        fieldsToUpdate["Gated Community? Gate Code?"] = form.garageDoorOpener;
    }

    // Garage Remotes_Garage Code - save garage door remote value
    if (
        form.garageDoorRemote &&
        customFieldsMap.value["Garage Remotes_Garage Code"]
    ) {
        fieldsToUpdate["Garage Remotes_Garage Code"] = form.garageDoorRemote;
    }

    // Pool Service - needs special value format
    if (form.swimmingPool && customFieldsMap.value["Pool Service"]) {
        const poolValue =
            form.swimmingPool === "No"
                ? "No Pool"
                : form.poolService === "Yes"
                ? "Required Contract"
                : "Cared By Owner";
        fieldsToUpdate["Pool Service"] = poolValue;
    }

    // HVAC Plan - needs special value format
    if (form.hvacMaintenancePlan && customFieldsMap.value["HVAC Plan"]) {
        const hvacValue =
            form.hvacMaintenancePlan === "Yes"
                ? "On our AC Plan"
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

    // Convert to Propertyware API format with proper value mapping
    Object.entries(fieldsToUpdate).forEach(([fieldName, value]) => {
        if (customFieldsMap.value[fieldName]) {
            // Map form values to PropertyWare accepted values
            let mappedValue = value;

            // Marketing Stage fields: Cleaning Service, Debris Removal, Lawn Care During Marketing, Utilities
            const marketingFields = [
                "Lawn Care During Marketing",
                "Cleaning Service",
                "Debris Removal",
                "Utilities",
            ];

            // Pre Move In fields: Carpet Cleaning, Final Clean
            const preMoveInFields = ["Carpet Cleaning", "Final Clean"];

            // Map "Management" to "By Management" for marketing and pre-move-in fields
            if (
                (marketingFields.includes(fieldName) ||
                    preMoveInFields.includes(fieldName)) &&
                value === "Management"
            ) {
                mappedValue = "By Management";
            }

            // Map "Owner" to "By Owner" for marketing and pre-move-in fields
            if (
                (marketingFields.includes(fieldName) ||
                    preMoveInFields.includes(fieldName)) &&
                value === "Owner"
            ) {
                mappedValue = "By Owner";
            }

            if (fieldName === "Utilities") {
                if (value === "Management") {
                    mappedValue = "By Managment";
                } else if (value === "Owner") {
                    mappedValue = "By Owner";
                }
            }
            // Property Re-Key mapping
            if (fieldName === "Property Re-Key") {
                if (value === "Management") {
                    mappedValue = "Re-Key By Management";
                } else if (value === "Owner") {
                    mappedValue = "Re-Key completed by Owner";
                }
            }

            // Yard Care During Lease mapping
            if (fieldName === "Yard Care During Lease") {
                if (value === "Tenant") {
                    mappedValue = "By Tenant";
                } else if (value === "Owner") {
                    mappedValue = "By Owner";
                }
            }

            // Service Provided mapping
            if (fieldName === "Service Provided") {
                if (value === "$75 Co-Pay" || value === "$75 Tenant Co-Pay") {
                    mappedValue = "Lease Only";
                } else if (value === "Management Handles") {
                    mappedValue = "Property Management";
                } else if (value === "Owner Handles") {
                    mappedValue = "Property Management";
                }
            }

            fieldSetDTOS.push({
                name: fieldName,
                value: mappedValue.toString(), // Ensure value is string
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

const startYear = 2025;
const currentYear = new Date().getFullYear();
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
                    <div class="flex items-center gap-4">
                        <img
                            :src="$page.props.logo"
                            alt="Logo"
                            class="h-10 w-auto"
                        />
                    </div>
                    <div class="flex items-center gap-4">
                        <Badge
                            variant="secondary"
                            class="hidden sm:inline-flex"
                        >
                            <Clock class="w-4 h-4 mr-1" />
                            Estimated time: 15-20 minutes
                        </Badge>
                        <Button variant="ghost" size="icon" as-child>
                            <a href="tel:+12812488018"
                                ><MessageCircle class="h-5 w-5"
                            /></a>
                        </Button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Floating Toggle Button -->
        <Button
            @click="toggleSidebar"
            :class="[
                'fixed top-32 z-50 rounded-r-lg transition-all duration-300 shadow-lg',
                isSidebarOpen ? 'left-80' : 'left-0',
            ]"
            size="sm"
            variant="outline"
        >
            <component
                :is="isSidebarOpen ? ChevronLeft : ChevronRight"
                class="h-4 w-4"
            />
            <span class="ml-1 hidden sm:inline"
                >{{ isSidebarOpen ? "Hide" : "Show" }} Steps</span
            >
        </Button>

        <!-- Backdrop for mobile -->
        <div
            v-if="isSidebarOpen"
            @click="closeSidebar"
            class="fixed inset-0 bg-black bg-opacity-50 z-40 xl:hidden"
        />

        <!-- Floating Sidebar Navigation -->
        <aside
            :class="[
                'fixed left-0 top-0 bottom-0 z-50 w-80 transform transition-transform duration-300 ease-in-out',
                isSidebarOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div
                class="h-full bg-white border-r border-gray-200 shadow-xl overflow-y-auto"
            >
                <div class="p-6">
                    <!-- Mobile Close Button -->
                    <div class="flex justify-end mb-4 xl:hidden">
                        <Button
                            @click="toggleSidebar"
                            variant="ghost"
                            size="icon"
                        >
                            <X class="h-5 w-5" />
                        </Button>
                    </div>

                    <!-- Progress Section -->
                    <div class="mb-8">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">
                            Overall Progress
                        </h3>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-primary"
                                    >{{ Math.round(progress) }}% Complete</span
                                >
                            </div>
                            <Progress
                                variant=""
                                :model-value="progress"
                                class="h-2 text-primary"
                            />
                        </div>
                    </div>

                    <!-- Steps Navigation -->
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">
                            Onboarding Steps
                        </h3>
                        <nav class="space-y-2">
                            <button
                                v-for="(section, index) in sections"
                                :key="section.id"
                                @click="
                                    () => {
                                        navigateToSection(section.id);
                                        closeSidebar();
                                    }
                                "
                                :class="[
                                    'w-full flex items-center justify-between px-4 py-3 rounded-lg text-sm font-medium transition-all duration-200 group',
                                    currentSection === section.id
                                        ? 'bg-blue-50 text-blue-700 border-l-4 border-blue-500'
                                        : completedSections.includes(section.id)
                                        ? 'bg-green-50 text-green-700 hover:bg-green-100'
                                        : 'bg-white text-gray-600 hover:bg-gray-50 hover:text-gray-900',
                                ]"
                            >
                                <div class="flex items-center">
                                    <div
                                        :class="[
                                            'flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center mr-3',
                                            currentSection === section.id
                                                ? 'bg-blue-200'
                                                : completedSections.includes(
                                                      section.id
                                                  )
                                                ? 'bg-green-200'
                                                : 'bg-gray-200',
                                        ]"
                                    >
                                        <CheckCircle
                                            v-if="
                                                completedSections.includes(
                                                    section.id
                                                )
                                            "
                                            class="h-5 w-5 text-green-600"
                                        />
                                        <component
                                            v-else
                                            :is="section.icon"
                                            :class="[
                                                'h-4 w-4',
                                                currentSection === section.id
                                                    ? 'text-blue-600'
                                                    : 'text-gray-500',
                                            ]"
                                        />
                                    </div>
                                    <div class="text-left">
                                        <div class="font-medium">
                                            {{ section.title }}
                                        </div>
                                        <div
                                            v-if="currentSection === section.id"
                                            class="text-xs text-blue-600 mt-0.5"
                                        >
                                            Currently editing
                                        </div>
                                        <div
                                            v-else-if="
                                                completedSections.includes(
                                                    section.id
                                                )
                                            "
                                            class="text-xs text-green-600 mt-0.5"
                                        >
                                            Completed
                                        </div>
                                    </div>
                                </div>
                                <ChevronRight
                                    :class="[
                                        'h-4 w-4 transition-transform group-hover:translate-x-1',
                                        currentSection === section.id
                                            ? 'text-blue-500'
                                            : completedSections.includes(
                                                  section.id
                                              )
                                            ? 'text-green-500'
                                            : 'text-gray-400',
                                    ]"
                                />
                            </button>
                        </nav>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Mobile Progress Bar (shown on small screens) -->
        <div
            class="xl:hidden fixed top-16 left-0 right-0 bg-white border-b z-10"
        >
            <div class="px-4 py-3">
                <div class="flex justify-between text-sm mb-2">
                    <span class="font-medium">Progress</span>
                    <span class="text-gray-600"
                        >{{ Math.round(progress) }}%</span
                    >
                </div>
                <Progress :model-value="progress" class="h-2" />
            </div>
        </div>

        <!-- Main Content Area -->
        <div :class="{ 'xl:ml-80': isSidebarOpen }">
            <div
                class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 pt-20 xl:pt-8"
            >
                <!-- Main Content -->
                <main class="space-y-8">
                    <!-- Hero Section -->
                    <div class="text-center mb-8">
                        <h1
                            class="text-3xl font-bold text-gray-900 sm:text-4xl"
                        >
                            Welcome to Property Onboarding
                        </h1>
                        <p class="mt-3 text-lg text-gray-600 max-w-2xl mx-auto">
                            We'll guide you through a simple process to set up
                            your property for management. Your responses help us
                            provide the best service for your investment.
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
                                        <CardTitle
                                            >Find Your Property</CardTitle
                                        >
                                        <CardDescription>
                                            Let's start by locating your
                                            property in our system
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
                                    <Loader
                                        class="w-4 h-4 mr-2 animate-spin"
                                        v-if="loading"
                                    />
                                    <Building class="w-4 h-4 mr-2" v-else />
                                    {{
                                        loading
                                            ? "Searching..."
                                            : "Search Property"
                                    }}
                                </Button>

                                <!-- Search Results -->
                                <div v-if="buildings.length > 0" class="mt-6">
                                    <!-- Multiple buildings found -->
                                    <div
                                        v-if="
                                            buildings.length > 1 &&
                                            !selectedBuildingId
                                        "
                                        class="space-y-4"
                                    >
                                        <Alert
                                            class="border-blue-200 bg-blue-50"
                                        >
                                            <Info
                                                class="h-4 w-4 text-blue-600"
                                            />
                                            <AlertTitle class="text-blue-800"
                                                >Multiple Properties
                                                Found</AlertTitle
                                            >
                                            <AlertDescription
                                                class="text-blue-700"
                                            >
                                                Found
                                                {{ buildings.length }}
                                                properties. Please select one to
                                                continue.
                                            </AlertDescription>
                                        </Alert>

                                        <RadioGroup
                                            v-model="selectedBuildingId"
                                            @update:modelValue="
                                                loadBuildingData
                                            "
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
                                                                <Building
                                                                    class="w-4 h-4"
                                                                />
                                                                {{ b.name }}
                                                            </p>
                                                            <p
                                                                class="text-sm text-gray-600 flex gap-2 items-center"
                                                            >
                                                                <MapIcon
                                                                    class="w-3 h-3"
                                                                />
                                                                {{
                                                                    b.address
                                                                        ?.address
                                                                }}
                                                                {{
                                                                    b.address
                                                                        ?.city
                                                                }},
                                                                {{
                                                                    b.address
                                                                        ?.stateRegion
                                                                }}
                                                                {{
                                                                    b.address
                                                                        ?.postalCode
                                                                }}
                                                            </p>
                                                        </div>
                                                    </Label>
                                                </div>
                                            </div>
                                        </RadioGroup>
                                    </div>

                                    <!-- Single building or building selected -->
                                    <Alert class="border-green-200 bg-green-50">
                                        <CheckCircle
                                            class="h-4 w-4 text-green-600"
                                        />
                                        <AlertTitle class="text-green-800"
                                            >Property
                                            {{
                                                buildings.length > 1
                                                    ? "Selected"
                                                    : "Found"
                                            }}!</AlertTitle
                                        >
                                        <AlertDescription
                                            class="text-green-700"
                                        >
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
                                                        buildingInfo.address
                                                            .address
                                                    }}
                                                    {{
                                                        buildingInfo.address
                                                            .city
                                                    }},
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
                    <section id="preparation" v-if="buildingInfo.id">
                        <Card>
                            <CardHeader>
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-purple-100 rounded-lg">
                                        <PaintBucket
                                            class="h-6 w-6 text-purple-600"
                                        />
                                    </div>
                                    <div>
                                        <CardTitle
                                            >Property Preparation</CardTitle
                                        >
                                        <CardDescription>
                                            Let's make your property
                                            market-ready
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent class="space-y-6">
                                <div
                                    class="prose prose-sm max-w-none text-gray-600"
                                >
                                    <p>
                                        A well-presented property attracts
                                        quality tenants and commands better
                                        rent. We'll help you prepare your
                                        property for success.
                                    </p>
                                    <p>
                                        We want to make sure that your home is
                                        presentable during the marketing phase.
                                        There are a few key areas that need to
                                        be taken care of prior to placing your
                                        home on the market. A neat, clean home
                                        will help you to attract the best
                                        possible tenant.
                                    </p>
                                </div>
                                <Alert class="mb-4">
                                    <Info class="h-4 w-4" />

                                    <AlertDescription>
                                        <div class="space-y-2">
                                            <p>
                                                <span
                                                    class="font-bold underline"
                                                    >Curb Appeal:</span
                                                >
                                                The first thing that someone
                                                will see when they pull up to
                                                the home is the lawn. Your home
                                                will lease faster if the yard is
                                                cut and the bushes & trees are
                                                trimmed. Also, if the home has
                                                any mildew or mold it should be
                                                cleaned or pressure washed.
                                            </p>
                                        </div>
                                    </AlertDescription>
                                </Alert>
                                <Alert class="mb-4">
                                    <Info class="h-4 w-4" />

                                    <AlertDescription>
                                        <div class="space-y-2">
                                            <p>
                                                <span
                                                    class="font-bold underline"
                                                    >Flooring:</span
                                                >
                                                The flooring should be free of
                                                obvious defects, large stains,
                                                and pet odors.
                                            </p>
                                        </div>
                                    </AlertDescription>
                                </Alert>

                                <div class="space-y-4">
                                    <h3
                                        class="font-semibold flex items-center gap-2"
                                    >
                                        <Sparkles
                                            class="h-5 w-5 text-yellow-500"
                                        />
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
                                                    Neutral colors will appeal
                                                    to the most people. Brighter
                                                    colors may be acceptable in
                                                    some areas of the home.
                                                    While it is not a
                                                    requirement that you repaint
                                                    the property, you may want
                                                    to consider it if you have
                                                    numerous scuff marks, holes,
                                                    or rooms that have colors
                                                    that might not appeal to a
                                                    large group of people. If
                                                    you have the paint code for
                                                    your wall, please include
                                                    them in the space provided
                                                    below.
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
                                            <div
                                                class="flex items-center gap-3"
                                            >
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
                                                <span
                                                    class="font-bold underline"
                                                    >Valuables:</span
                                                >
                                                Crime can happen at any time.
                                                Please help to minimize the risk
                                                by putting away or removing
                                                valuables and fire arms from the
                                                property.
                                            </p>
                                        </div>
                                    </AlertDescription>
                                </Alert>
                                <Alert class="mb-4">
                                    <Info class="h-4 w-4" />

                                    <AlertDescription>
                                        <div class="space-y-2">
                                            <p>
                                                <span
                                                    class="font-bold underline"
                                                    >Insurance:</span
                                                >
                                                Make sure to contact your
                                                insurance company to learn about
                                                your insurance policy. If you
                                                are moving out of the property,
                                                and it will be vacant for any
                                                period, let your insurance
                                                company know. Some policies do
                                                not cover vandalism when home is
                                                vacant
                                            </p>
                                        </div>
                                    </AlertDescription>
                                </Alert>
                                <Alert class="mb-4">
                                    <Info class="h-4 w-4" />

                                    <AlertDescription>
                                        <div class="space-y-2">
                                            <p>
                                                <span
                                                    class="font-bold underline"
                                                    >Utilities:</span
                                                >
                                                We require that utilities are
                                                turned on. If vacant, we ask
                                                that you shut off water at the
                                                main cut off valve to prevent
                                                water leaks that might otherwise
                                                go unnoticed before causing
                                                significant damage. It's Texas,
                                                and it's hot. Prospective
                                                tenant's viewing your home will
                                                turn down your AC to see if it
                                                blows cold air, and will then
                                                walk out without turning the
                                                thermostat back up or off. Make
                                                sure that you have programmable
                                                thermostat that automatically go
                                                back to a reasonable
                                                temperature, so that you don't
                                                receive a surprise electric bill
                                                for a vacant home.
                                            </p>
                                        </div>
                                    </AlertDescription>
                                </Alert>
                                <div
                                    class="prose prose-sm max-w-none text-gray-600"
                                >
                                    <p class="mb-4">
                                        For the above items, we are happy to
                                        arrange the services below. Please let
                                        us know by indicating in the appropriate
                                        box with your initials, if you would
                                        like to take care of the above items
                                        before the property goes on the market,
                                        or if you would like us to handle them
                                        for you.
                                    </p>
                                    <p>
                                        The following items are only for the
                                        initial lease term. After the initial
                                        tenant moves out, if TexasRenters.com is
                                        still managing the property, will handle
                                        utilities, lawn care, carpet clearning
                                        and cleaning unless otherwise instructed
                                        at that tme, per the property management
                                        agreement.
                                    </p>
                                </div>

                                <Button
                                    @click="markSectionCompleted('preparation')"
                                    :variant="
                                        completedSections.includes(
                                            'preparation'
                                        )
                                            ? 'default'
                                            : 'outline'
                                    "
                                    :class="[
                                        'w-full sm:w-auto',
                                        completedSections.includes(
                                            'preparation'
                                        )
                                            ? 'bg-green-600 hover:bg-green-700 text-white'
                                            : '',
                                    ]"
                                    :disabled="
                                        completedSections.includes(
                                            'preparation'
                                        )
                                    "
                                >
                                    <CheckCircle class="w-4 h-4 mr-2" />
                                    {{
                                        completedSections.includes(
                                            "preparation"
                                        )
                                            ? "Section Completed"
                                            : "Mark Section Complete"
                                    }}
                                </Button>
                            </CardContent>
                        </Card>
                    </section>

                    <!-- Service Responsibilities Section -->
                    <section id="services" v-if="buildingInfo.id">
                        <Card>
                            <CardHeader>
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-orange-100 rounded-lg">
                                        <Wrench
                                            class="h-6 w-6 text-orange-600"
                                        />
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
                                                    <SelectItem
                                                        value="Management"
                                                        >Management will
                                                        handle</SelectItem
                                                    >
                                                    <SelectItem value="Owner"
                                                        >I'll handle
                                                        it</SelectItem
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
                                                    <SelectItem
                                                        value="Management"
                                                        >Management will
                                                        handle</SelectItem
                                                    >
                                                    <SelectItem value="Owner"
                                                        >I'll handle
                                                        it</SelectItem
                                                    >
                                                </SelectContent>
                                            </Select>
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
                                    :disabled="
                                        completedSections.includes('services')
                                    "
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

                    <!-- Security & Safety Section -->
                    <section id="security" v-if="buildingInfo.id">
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
                                    <h3 class="font-semibold mb-4">
                                        Alarm System
                                    </h3>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div class="space-y-2">
                                            <label class="text-sm font-medium"
                                                >Is there an Alarm
                                                System?</label
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
                                                        >Re-Key & Code
                                                        Work:</span
                                                    >
                                                    State law requires a working
                                                    smoke detector in each
                                                    bedroom and each hallway
                                                    servicing more than one
                                                    bedroom along with a peep
                                                    hole and keyless locking
                                                    device on each exterior
                                                    door. Homes must be re-keyed
                                                    between each tenant.
                                                </p>
                                                <p>
                                                    <span
                                                        class="font-bold underline"
                                                        >This is a very large
                                                        liability issue,
                                                        therefore, if the items
                                                        have not been completed
                                                        prior to our move in
                                                        inspection, the
                                                        management company will
                                                        automatically complete
                                                        the items.</span
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
                                    :variant="
                                        completedSections.includes('security')
                                            ? 'default'
                                            : 'outline'
                                    "
                                    :class="[
                                        'w-full sm:w-auto',
                                        completedSections.includes('security')
                                            ? 'bg-green-600 hover:bg-green-700 text-white'
                                            : '',
                                    ]"
                                    :disabled="
                                        completedSections.includes('security')
                                    "
                                >
                                    <CheckCircle class="w-4 h-4 mr-2" />
                                    {{
                                        completedSections.includes("security")
                                            ? "Section Completed"
                                            : "Mark Section Complete"
                                    }}
                                </Button>
                            </CardContent>
                        </Card>
                    </section>

                    <!-- Policies & Rules Section -->
                    <section id="policies" v-if="buildingInfo.id">
                        <Card>
                            <CardHeader>
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-green-100 rounded-lg">
                                        <Clipboard
                                            class="h-6 w-6 text-green-600"
                                        />
                                    </div>
                                    <div>
                                        <CardTitle>Policies & Rules</CardTitle>
                                        <CardDescription>
                                            Set your preferences for tenant
                                            policies
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent class="space-y-6">
                                <div>
                                    <h3 class="font-semibold mb-4">
                                        Pet Policy
                                    </h3>
                                    <Alert variant="secondary" class="mb-4">
                                        <Info class="h-4 w-4" />
                                        <AlertDescription>
                                            <p>
                                                <span
                                                    class="font-bold underline"
                                                    >Pets:</span
                                                >
                                                Many renters are choosing to
                                                live in a home versus an
                                                apartment because they have
                                                pets. This reality means that if
                                                you do not accept pets, it will
                                                take about twice as long to rent
                                                your home if you do not allow
                                                pets. However, this is of course
                                                your choice. Please let us know
                                                if you would accept pets, and
                                                what type below.
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
                                            >Tenant Service Request
                                            Handling</label
                                        >
                                        <Select
                                            v-model="form.tenantServiceRequest"
                                        >
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
                                    :variant="
                                        completedSections.includes('policies')
                                            ? 'default'
                                            : 'outline'
                                    "
                                    :class="[
                                        'w-full sm:w-auto',
                                        completedSections.includes('policies')
                                            ? 'bg-green-600 hover:bg-green-700 text-white'
                                            : '',
                                    ]"
                                    :disabled="
                                        completedSections.includes('policies')
                                    "
                                >
                                    <CheckCircle class="w-4 h-4 mr-2" />
                                    {{
                                        completedSections.includes("policies")
                                            ? "Section Completed"
                                            : "Mark Section Complete"
                                    }}
                                </Button>
                            </CardContent>
                        </Card>
                    </section>

                    <!-- Amenities & Features Section -->
                    <section id="amenities" v-if="buildingInfo.id">
                        <Card>
                            <CardHeader>
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-indigo-100 rounded-lg">
                                        <Sparkles
                                            class="h-6 w-6 text-indigo-600"
                                        />
                                    </div>
                                    <div>
                                        <CardTitle
                                            >Amenities & Features</CardTitle
                                        >
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
                                                home so that we can properly
                                                market the home and give the new
                                                tenant information on how to
                                                take care of the home.</span
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
                                                        >Yes - Has
                                                        pool</SelectItem
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
                                            <Select
                                                v-model="form.communityPool"
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
                                    <h3 class="font-semibold mb-4">
                                        Appliances
                                    </h3>
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
                                                >Washing Machine
                                                Included?</label
                                            >
                                            <Select
                                                v-model="form.washingMachine"
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
                                                >Washing Machine Hookups?</label
                                            >
                                            <Select
                                                v-model="
                                                    form.washingMachineHookups
                                                "
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
                                            <Select
                                                v-model="form.waterSoftener"
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
                                                >Water Heater Model Year?</label
                                            >
                                            <Input
                                                v-model="
                                                    form.waterHeaterModelYear
                                                "
                                                placeholder="e.g. 2018"
                                            />
                                        </div>

                                        <div class="space-y-2">
                                            <label class="text-sm font-medium"
                                                >Dishwasher Model Year?</label
                                            >
                                            <Input
                                                v-model="
                                                    form.dishWasherModelYear
                                                "
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
                                            <Select
                                                v-model="form.garageDoorOpener"
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
                                    :disabled="
                                        completedSections.includes('amenities')
                                    "
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

                    <!-- Utilities & Maintenance Section -->
                    <section id="utilities" v-if="buildingInfo.id">
                        <Card>
                            <CardHeader>
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-yellow-100 rounded-lg">
                                        <Wrench
                                            class="h-6 w-6 text-yellow-600"
                                        />
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
                                            The following responsibilities are
                                            only for the initial lease term.
                                        </AlertDescription>
                                    </Alert>

                                    <div class="space-y-6">
                                        <div>
                                            <h4 class="font-medium mb-3">
                                                Prior to Marketing
                                            </h4>
                                            <div
                                                class="grid gap-4 md:grid-cols-2"
                                            >
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
                                                        >Carpet
                                                        Replacement</label
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
                                                    It is important that the
                                                    lawn and home stay in good
                                                    shape while the home is on
                                                    the market. Please indicate
                                                    below if you would like to
                                                    be responsible for the below
                                                    items, or if you would like
                                                    us to handle it for you.
                                                </AlertDescription>
                                            </Alert>
                                            <div
                                                class="grid gap-4 md:grid-cols-2"
                                            >
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
                                                                before Tenant
                                                                Move in:</span
                                                            >
                                                            We require that all
                                                            homes be
                                                            profesionally
                                                            cleaned, and carpets
                                                            thermostat are not
                                                            new be
                                                            professionally
                                                            cleaned, a final
                                                            lawn cut be done and
                                                            pest control prior
                                                            to move in. We
                                                            expect that when a
                                                            tenant moves out of
                                                            the home, they will
                                                            also have the home
                                                            professionally
                                                            cleaned and carpets
                                                            steam cleaned. By
                                                            having the home
                                                            professionally
                                                            cleaned, and being
                                                            able to provide
                                                            receipts, we can
                                                            help assure that as
                                                            the home owner, you
                                                            will not be paying
                                                            to clean up after
                                                            tenants in the
                                                            future. In most
                                                            cases, this will be
                                                            the only time that
                                                            you need to pay for
                                                            cleaning. If the
                                                            carpets were not
                                                            professionally
                                                            cleaned prior to
                                                            putting the home on
                                                            the market, this is
                                                            the time to do it.
                                                            We do however, allow
                                                            you to choose
                                                            between us providing
                                                            the service, and you
                                                            arranging the
                                                            service on your own.
                                                            If you arrange the
                                                            service, please
                                                            provide receipts for
                                                            our records.
                                                        </p>
                                                    </div>
                                                </AlertDescription>
                                            </Alert>
                                            <div
                                                class="grid gap-4 md:grid-cols-2"
                                            >
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
                                                <label
                                                    class="text-sm font-medium"
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
                                                        <SelectItem
                                                            value="Tenant"
                                                            >Tenant will handle
                                                            this</SelectItem
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
                                </div>

                                <Button
                                    @click="markSectionCompleted('utilities')"
                                    :variant="
                                        completedSections.includes('utilities')
                                            ? 'default'
                                            : 'outline'
                                    "
                                    :class="[
                                        'w-full sm:w-auto',
                                        completedSections.includes('utilities')
                                            ? 'bg-green-600 hover:bg-green-700 text-white'
                                            : '',
                                    ]"
                                    :disabled="
                                        completedSections.includes('utilities')
                                    "
                                >
                                    <CheckCircle class="w-4 h-4 mr-2" />
                                    {{
                                        completedSections.includes("utilities")
                                            ? "Section Completed"
                                            : "Mark Section Complete"
                                    }}
                                </Button>
                            </CardContent>
                        </Card>
                    </section>

                    <!-- Preferred Vendors Section -->
                    <section id="vendors" v-if="buildingInfo.id">
                        <Card>
                            <CardHeader>
                                <div class="flex items-center gap-3">
                                    <div class="p-2 bg-purple-100 rounded-lg">
                                        <Users
                                            class="h-6 w-6 text-purple-600"
                                        />
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
                                        If you have a preferred vendor, we will
                                        use them for non-emergency repairs, and
                                        make a reasonable effor to tuse them for
                                        emergency repairs. If your preferred
                                        vendor cannot be reached in a situation
                                        where delay would cause damage to your
                                        property or harm to the tenant, we will
                                        select a vendor who can get the job
                                        completed as soon as possible.
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
                                        <h3 class="font-semibold">
                                            Electrical
                                        </h3>
                                        <div class="space-y-2">
                                            <label class="text-sm font-medium"
                                                >Company Name</label
                                            >
                                            <Input
                                                v-model="
                                                    form.electricVendorName
                                                "
                                                placeholder="Electrician company name"
                                            />
                                        </div>
                                        <div class="space-y-2">
                                            <label class="text-sm font-medium"
                                                >Phone Number</label
                                            >
                                            <Input
                                                v-model="
                                                    form.electricVendorNumber
                                                "
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
                                                v-model="
                                                    form.plumbingVendorName
                                                "
                                                placeholder="Plumbing company name"
                                            />
                                        </div>
                                        <div class="space-y-2">
                                            <label class="text-sm font-medium"
                                                >Phone Number</label
                                            >
                                            <Input
                                                v-model="
                                                    form.plumbingVendorNumber
                                                "
                                                placeholder="Phone number"
                                            />
                                        </div>
                                    </div>

                                    <div class="space-y-4 border p-3">
                                        <h3 class="font-semibold">
                                            Pest Control
                                        </h3>
                                        <div class="space-y-2">
                                            <label class="text-sm font-medium"
                                                >Company Name</label
                                            >
                                            <Input
                                                v-model="
                                                    form.pestControlVendorName
                                                "
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
                                                v-model="
                                                    form.lawnCareVendorName
                                                "
                                                placeholder="Lawn care company name"
                                            />
                                        </div>
                                        <div class="space-y-2">
                                            <label class="text-sm font-medium"
                                                >Phone Number</label
                                            >
                                            <Input
                                                v-model="
                                                    form.lawnCareVendorNumber
                                                "
                                                placeholder="Phone number"
                                            />
                                        </div>
                                    </div>

                                    <div class="space-y-4 border p-3">
                                        <h3 class="font-semibold">
                                            Other Service
                                        </h3>
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
                                                    The most common cause for
                                                    damage to homes that we see
                                                    is from the emergency drain
                                                    line overflowing from the
                                                    air-conditioning drain pan.
                                                    This is caused from mold or
                                                    other debris clogging up the
                                                    primary and secondary drain
                                                    lines. This can be prevented
                                                    by doing 2 things. First,
                                                    the HVAC system can be
                                                    serviced every 6 months,
                                                    which includes blowing out
                                                    the drain lines, cleaning
                                                    exterior coils, checking for
                                                    carbon monoxide leaks, and
                                                    changing your air filter
                                                    (all of this also extends
                                                    the life of and increases
                                                    the efficiency of your HVAC
                                                    system). Second, an
                                                    emergency float switch will
                                                    be installed in the pan, so
                                                    that if water builds up in
                                                    the pan, the HVAC systems
                                                    will shut off and stop
                                                    producing water. The HVAC
                                                    inspections are included on
                                                    the premium plan and a float
                                                    switch will be added if
                                                    there is not already one in
                                                    place.
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
                                                v-model="
                                                    form.hvacMaintenancePlan
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

                                        <div class="space-y-2">
                                            <label class="text-sm font-medium"
                                                >Install Float Switch? <br />
                                                (Automatically Completed)</label
                                            >
                                            <Select
                                                v-model="
                                                    form.installFloatSwitch
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
                                    <Alert class="mb-4">
                                        <Info class="h-4 w-4" />
                                        <AlertDescription>
                                            <div class="space-y-3">
                                                <p>
                                                    <span
                                                        class="font-bold underline"
                                                        >Home Warranty: We
                                                        generally do not
                                                        recommend them, because
                                                        the responsiveness, and
                                                        quality of the service
                                                        are not often up to
                                                        par.</span
                                                    >
                                                    They usually have 2 business
                                                    days to respond to an
                                                    air-conditioning service
                                                    call (respond not complete),
                                                    which means that if your
                                                    tenant calls on Friday, they
                                                    may not get a response until
                                                    Tuesday. Also, if you have a
                                                    plan or decide to get a
                                                    plan, please read the
                                                    exclusions and limitations
                                                    very closely. Our experience
                                                    is that many of the plans
                                                    cap the most expensive such
                                                    as the air air-conditioning
                                                    at a set amount, and stick
                                                    you with their vendor to
                                                    install the new system, who
                                                    usually charges 2x what we
                                                    can get it done for. Also, a
                                                    repair may be covered, but
                                                    damage caused by the repair
                                                    or malfunction, along with
                                                    bringing older items up to
                                                    code may not be covered.
                                                    That being said, some would
                                                    prefer the comfort of
                                                    knowing that many cost can
                                                    be covered. If you do not
                                                    have a home warranty, get
                                                    our advice on who to pick
                                                    first. If you already have
                                                    one, please include the
                                                    information below, so that
                                                    we can use the home warranty
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
                                    <h3 class="font-semibold">
                                        Property History
                                    </h3>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div class="space-y-2">
                                            <label class="text-sm font-medium"
                                                >Ever Flooded?</label
                                            >
                                            <Select
                                                v-model="form.floodedProperty"
                                            >
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
                                            v-if="
                                                form.floodedProperty === 'Yes'
                                            "
                                        >
                                            <label class="text-sm font-medium"
                                                >Last Flood Date</label
                                            >
                                            <Input
                                                type="date"
                                                v-model="
                                                    form.floodedPropertyDate
                                                "
                                            />
                                        </div>
                                    </div>
                                </div>

                                <Button
                                    @click="markSectionCompleted('vendors')"
                                    :variant="
                                        completedSections.includes('vendors')
                                            ? 'default'
                                            : 'outline'
                                    "
                                    :class="[
                                        'w-full sm:w-auto',
                                        completedSections.includes('vendors')
                                            ? 'bg-green-600 hover:bg-green-700 text-white'
                                            : '',
                                    ]"
                                    :disabled="
                                        completedSections.includes('vendors')
                                    "
                                >
                                    <CheckCircle class="w-4 h-4 mr-2" />
                                    {{
                                        completedSections.includes("vendors")
                                            ? "Section Completed"
                                            : "Mark Section Complete"
                                    }}
                                </Button>
                            </CardContent>
                        </Card>
                    </section>

                    <!-- Final Submit Section -->
                    <section id="signature" v-if="buildingInfo.id">
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
                                            >Final Details &
                                            Signature</CardTitle
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
                                            If there is anything not Covered
                                            above, please let us know if the
                                            space below. This would also include
                                            any repairs or bids that you would
                                            like us to have completed for you.
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
                                        Please sign below to authorize and
                                        verify the information provided.
                                    </p>

                                    <div
                                        class="border rounded-lg overflow-hidden"
                                    >
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
        </div>

        <!-- Modern Footer -->
        <footer class="bg-gray-50 border-t mt-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="text-center text-sm text-gray-600">
                    <p>
                        &copy;
                        {{
                            startYear === currentYear
                                ? currentYear
                                : `${startYear}–${currentYear}`
                        }}
                        <a
                            href="https://www.texasrenters.com/"
                            target="_blank"
                            class="font-bold text-primary"
                            >TexasRenters.com</a
                        >. All rights reserved.
                    </p>
                    <p class="mt-2">Need help? Message us at 281-248-8018</p>
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
