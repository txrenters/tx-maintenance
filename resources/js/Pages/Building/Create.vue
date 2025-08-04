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
    Lock,
    Users2,
    Dog,
    AppWindow,
} from "lucide-vue-next";
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from "@/Components/ui/card";
import { Alert, AlertDescription, AlertTitle } from "@/Components/ui/alert";
import FindProperty from "./partial/FindProperty.vue";
import PreparetionProperty from "./partial/PropertyPreparetion.vue";
import PropertyPriorToMarket from "./partial/PropertyPriorToMarket.vue";
import ReKeyCodeWork from "./partial/ReKeyCodeWork.vue";
import TenantServiceRequest from "./partial/TenantServiceRequest.vue";
import PetPolicy from "./partial/PetPolicy.vue";
import Amenities from "./partial/Amenities.vue";
import HVACMaintenance from "./partial/HVACMaintenance.vue";
import Vendors from "./partial/Vendors.vue";
import FloodHistory from "./partial/FloodHistory.vue";
import Footer from "./partial/Footer.vue";

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
    homeOnTheMarketCleaning: "Management",
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
    tenantToContactNeighborhoodAmenities: "",
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
    homeWarrantyContactNumber: "",
    //Preferred Vendors
    hvacVendorName: "",
    hvacVendorNumber: "",
    electricVendorName: "",
    electricVendorNumber: "",
    plumbingVendorName: "",
    plumbingVendorNumber: "",
    pestControlVendorName: "",
    pestControlVendorNumber: "",
    lawnCareVendorName: "",
    lawnCareVendorNumber: "",
    otherVendorName: "",
    otherVendorNumber: "",
    //Has the property ever flooded?
    floodedProperty: "",
    floodedPropertyDate: "",

    otherComments: "",

    // New location fields
    gasShutoffValveLocation: "",
    breakerBoxLocation: "",
    hvacFilterLocation1: "",
    hvacFilterLocation2: "",
    hvacFilterLocation3: "",
    hvacFilterLocation4: "",
    hvacFilterSize1: "",
    hvacFilterSize2: "",
    hvacFilterSize3: "",
    hvacFilterSize4: "",
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

    // Restore form data from localStorage
    const savedFormData = localStorage.getItem("buildingOnboardingForm");
    if (savedFormData) {
        try {
            const parsedData = JSON.parse(savedFormData);
            // Only restore data if building ID matches (to avoid restoring wrong building data)
            if (
                parsedData.buildingId &&
                parsedData.buildingId === buildingInfo?.id
            ) {
                Object.keys(parsedData).forEach((key) => {
                    if (key !== "buildingId" && form.hasOwnProperty(key)) {
                        form[key] = parsedData[key];
                    }
                });

                // Show notification that data was restored
                toast({
                    title: "Form Restored",
                    description: "Your previous progress has been restored.",
                });
            }
        } catch (error) {
            console.error("Error restoring form data:", error);
        }
    }
});

// Auto-save form data to localStorage
const saveFormToLocalStorage = () => {
    if (buildingInfo?.id) {
        // Extract only the data from the Inertia form object
        const formData = {
            buildingId: buildingInfo.id,
            ...form.data(),
        };
        localStorage.setItem(
            "buildingOnboardingForm",
            JSON.stringify(formData)
        );
    }
};

// Debounce timer reference
let saveTimer = null;

// Debounced save function to prevent too many localStorage writes
const debouncedSave = () => {
    if (saveTimer) {
        clearTimeout(saveTimer);
    }
    saveTimer = setTimeout(() => {
        saveFormToLocalStorage();
    }, 1000); // Save after 1 second of no changes
};

// Watch for form changes and auto-save
// Watch the form.data() which contains the actual form values
watch(
    () => form.data(),
    () => {
        debouncedSave();
    },
    { deep: true }
);

// Define sections for navigation
const sections = [
    { id: "search", title: "Property Search", icon: Home },
    { id: "preparation", title: "Property Preparation", icon: PaintBucket },
    { id: "services", title: "Service Responsibilities", icon: Wrench },
    { id: "rekey", title: "Re-key and Code Work", icon: Lock },
    {
        id: "tenant_service_request",
        title: "Tenant Service Request",
        icon: Users2,
    },
    { id: "pet_policies", title: "Pet Policies", icon: Dog },
    { id: "amenities", title: "Amenities & Features", icon: Sparkles },
    { id: "hvac_maintenance", title: "HVAC & Home Warranty", icon: AppWindow },
    { id: "vendors", title: "Preferred Vendors", icon: Users },
    { id: "history", title: "Property History", icon: Home },
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
    // Save form data when section is completed
    saveFormToLocalStorage();
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

    // New location fields
    gasShutoffValveLocation: "Gas Shut Off Valve Location",
    breakerBoxLocation: "Breaker Box Location",
    hvacFilterLocation1: "HVAC Filter Location Information 1",
    hvacFilterLocation2: "HVAC Filter Location Information 2",
    hvacFilterLocation3: "HVAC Filter Location Information 3",
    hvacFilterLocation4: "HVAC Filter Location Information 4",
    hvacFilterSize1: "HVAC Filter Size 1",
    hvacFilterSize2: "HVAC Filter Size 2",
    hvacFilterSize3: "HVAC Filter Size 3",
    hvacFilterSize4: "HVAC Filter Size 4",
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
            form.tenantServiceRequest = "Lease Only";
        } else if (serviceValue === "Property Management") {
            form.tenantServiceRequest = "Property Management";
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
        // Parse the warranty value which might be in format "Company - ServiceNumber"
        const warrantyValue = customFieldsMap.value["Home Warranty"].value;
        const parts = warrantyValue.split(" - ");
        if (parts.length > 0) {
            form.homeWarrantyCompanyName = parts[0];
            if (parts.length > 1 && parts[1] !== "N/A") {
                form.homeWarrantyServiceNumber = parts[1];
            }
        } else {
            form.homeWarrantyCompanyName = warrantyValue;
        }
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

    // HVAC Filter info - populate the new filter size fields
    if (
        customFieldsMap.value["HVAC Filter Size 1"]?.value &&
        customFieldsMap.value["HVAC Filter Size 1"].value !== "NA"
    ) {
        form.hvacFilterSize1 =
            customFieldsMap.value["HVAC Filter Size 1"].value;
    }
    if (
        customFieldsMap.value["HVAC Filter Size 2"]?.value &&
        customFieldsMap.value["HVAC Filter Size 2"].value !== "NA"
    ) {
        form.hvacFilterSize2 =
            customFieldsMap.value["HVAC Filter Size 2"].value;
    }
    if (
        customFieldsMap.value["HVAC Filter Size 3"]?.value &&
        customFieldsMap.value["HVAC Filter Size 3"].value !== "NA"
    ) {
        form.hvacFilterSize3 =
            customFieldsMap.value["HVAC Filter Size 3"].value;
    }
    if (
        customFieldsMap.value["HVAC Filter Size 4"]?.value &&
        customFieldsMap.value["HVAC Filter Size 4"].value !== "NA"
    ) {
        form.hvacFilterSize4 =
            customFieldsMap.value["HVAC Filter Size 4"].value;
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
        form.beforeTenantMoveInClearning = "Management";
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

// Function to parse maintenance notice field for vendor information
const parseMaintenanceNotice = (maintenanceNotice) => {
    if (!maintenanceNotice || maintenanceNotice.trim() === "") return;

    // Split by semicolon to get individual vendor entries
    const vendorEntries = maintenanceNotice
        .split(";")
        .map((entry) => entry.trim());

    vendorEntries.forEach((entry) => {
        // Parse each entry format: "Type: Name Phone"
        const colonIndex = entry.indexOf(":");
        if (colonIndex > -1) {
            const entryType = entry.substring(0, colonIndex).trim();
            const entryInfo = entry.substring(colonIndex + 1).trim();

            // Parse vendor/warranty info to separate name and phone
            // Assume phone number is the last part that matches a phone pattern
            const phoneMatch = entryInfo.match(
                /(\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4})$/
            );

            if (phoneMatch) {
                const phone = phoneMatch[1].trim();
                const name = entryInfo
                    .substring(0, entryInfo.lastIndexOf(phoneMatch[0]))
                    .trim();

                // Populate the appropriate fields based on vendor type
                switch (entryType.toLowerCase()) {
                    case "warranty":
                        form.homeWarranty = "Yes";
                        form.homeWarrantyCompanyName = name;
                        form.homeWarrantyContactNumber = phone;
                        break;
                    case "hvac":
                        form.hvacVendorName = name;
                        form.hvacVendorNumber = phone;
                        break;
                    case "electric":
                        form.electricVendorName = name;
                        form.electricVendorNumber = phone;
                        break;
                    case "plumbing":
                        form.plumbingVendorName = name;
                        form.plumbingVendorNumber = phone;
                        break;
                    case "pest control":
                        form.pestControlVendorName = name;
                        form.pestControlVendorNumber = phone;
                        break;
                    case "lawn care":
                        form.lawnCareVendorName = name;
                        form.lawnCareVendorNumber = phone;
                        break;
                    case "other":
                        form.otherVendorName = name;
                        form.otherVendorNumber = phone;
                        break;
                }
            }
        }
    });
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

        // Parse maintenanceNotice built-in field for vendor information
        if (
            building.value.maintenanceNotice &&
            building.value.maintenanceNotice.trim() !== ""
        ) {
            parseMaintenanceNotice(building.value.maintenanceNotice);
        }

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

            // Skip fields that are handled separately to avoid conflicts
            if (
                customFieldName === "Pet Restrictions" ||
                customFieldName === "Owner Pet Prefences"
            ) {
                return;
            }

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

    // Owner Pet Prefences - always update to replace existing data completely
    if (customFieldsMap.value["Owner Pet Prefences"]) {
        const petPrefs = [];

        // Handle explicit pet preferences
        if (form.dogsAllowed === "Yes") {
            let dogText = "Dogs allowed";
            if (form.dogsMaxWeight) {
                dogText += ` (max weight: ${form.dogsMaxWeight} lbs)`;
            }
            petPrefs.push(dogText);
        } else if (form.dogsAllowed === "No") {
            petPrefs.push("No dogs allowed");
        }

        if (form.catsAllowed === "Yes") {
            let catText = "Cats allowed";
            if (form.catRestrictions) {
                catText += ` (${form.catRestrictions})`;
            }
            petPrefs.push(catText);
        } else if (form.catsAllowed === "No") {
            petPrefs.push("No cats allowed");
        }

        // If both dogs and cats are explicitly set to No, use single statement
        if (form.dogsAllowed === "No" && form.catsAllowed === "No") {
            petPrefs.length = 0; // Clear individual "No" statements
            petPrefs.push("No pets allowed");
        }

        // Add any other pet restrictions (but don't duplicate existing data)
        if (
            form.otherPetsRestriction &&
            form.otherPetsRestriction !== "Not Completed" &&
            form.otherPetsRestriction !==
                customFieldsMap.value["Owner Pet Prefences"].value
        ) {
            petPrefs.push(form.otherPetsRestriction);
        }

        // Always update - if no preferences set, clear the field
        const petValue =
            petPrefs.length > 0 ? petPrefs.join(", ") : "Not Completed";
        fieldsToUpdate["Owner Pet Prefences"] = petValue;
    }

    // Pet Restrictions - always update to replace existing data completely
    if (customFieldsMap.value["Pet Restrictions"]) {
        // Only use otherPetsRestriction if it's different from the stored value and not empty
        if (
            form.otherPetsRestriction &&
            form.otherPetsRestriction !== "Not Completed"
        ) {
            fieldsToUpdate["Pet Restrictions"] = form.otherPetsRestriction;
        } else {
            // Clear the field if no restrictions are set
            fieldsToUpdate["Pet Restrictions"] = "Not Completed";
        }
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

    // Maintenance Notice - concatenate vendor names and phone numbers, and warranty info
    // This will be handled as a built-in field, not a custom field
    const notices = [];

    // Add warranty information first if available
    if (
        form.homeWarranty === "Yes" &&
        form.homeWarrantyCompanyName &&
        form.homeWarrantyContactNumber
    ) {
        notices.push(
            `Warranty: ${form.homeWarrantyCompanyName} ${form.homeWarrantyContactNumber}`
        );
    }

    // Add vendor information
    if (form.hvacVendorName && form.hvacVendorNumber) {
        notices.push(`HVAC: ${form.hvacVendorName} ${form.hvacVendorNumber}`);
    }
    if (form.electricVendorName && form.electricVendorNumber) {
        notices.push(
            `Electric: ${form.electricVendorName} ${form.electricVendorNumber}`
        );
    }
    if (form.plumbingVendorName && form.plumbingVendorNumber) {
        notices.push(
            `Plumbing: ${form.plumbingVendorName} ${form.plumbingVendorNumber}`
        );
    }
    if (form.pestControlVendorName && form.pestControlVendorNumber) {
        notices.push(
            `Pest Control: ${form.pestControlVendorName} ${form.pestControlVendorNumber}`
        );
    }
    if (form.lawnCareVendorName && form.lawnCareVendorNumber) {
        notices.push(
            `Lawn Care: ${form.lawnCareVendorName} ${form.lawnCareVendorNumber}`
        );
    }
    if (form.otherVendorName && form.otherVendorNumber) {
        notices.push(
            `Other: ${form.otherVendorName} ${form.otherVendorNumber}`
        );
    }

    // Store the maintenance notice data to be sent as built-in field
    const maintenanceNoticeValue = notices.length > 0 ? notices.join("; ") : "";

    // Included Appliances - concatenate all included appliances
    if (customFieldsMap.value["Included Appliances"]) {
        const includedAppliances = [];
        if (form.refrigerator === "Yes")
            includedAppliances.push("Refrigerator");
        if (form.microwave === "Yes") includedAppliances.push("Microwave");
        if (form.washingMachine === "Yes")
            includedAppliances.push("Washing Machine");
        if (form.dryer === "Yes") includedAppliances.push("Dryer");
        if (form.waterSoftener === "Yes")
            includedAppliances.push("Water Softener");
        if (form.dishWasherModelYear && form.dishWasherModelYear !== "")
            includedAppliances.push("Dishwasher");

        if (includedAppliances.length > 0) {
            fieldsToUpdate["Included Appliances"] =
                includedAppliances.join(", ");
        }
    }

    // HVAC Filter Information - concatenate location and size for each filter
    const hvacFilters = [];
    for (let i = 1; i <= 4; i++) {
        const location = form[`hvacFilterLocation${i}`];
        const size = form[`hvacFilterSize${i}`];

        if (location && location.trim() !== "" && size && size.trim() !== "") {
            hvacFilters.push(`Filter ${i}: ${location} - Size: ${size}`);
        }
    }

    // Store concatenated HVAC filter information in a single custom field
    if (
        hvacFilters.length > 0 &&
        customFieldsMap.value["HVAC Filter Location Information"]
    ) {
        fieldsToUpdate["HVAC Filter Location Information"] =
            hvacFilters.join("; ");
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
                if (value === "Lease Only") {
                    mappedValue = "Lease Only";
                } else if (value === "Property Management") {
                    mappedValue = "Property Management";
                }
            }

            fieldSetDTOS.push({
                name: fieldName,
                value: mappedValue.toString(), // Ensure value is string
            });
        }
    });

    return { fieldSetDTOS, maintenanceNoticeValue };
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
    const { fieldSetDTOS, maintenanceNoticeValue } =
        prepareCustomFieldsForUpdate();

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
                maintenanceNotice: maintenanceNoticeValue,
            }
        );

        toast({
            title: "Success!",
            description: `Your property information has been updated successfully.`,
        });

        console.log("Updated fields:", fieldSetDTOS);

        // Mark signature section as completed
        markSectionCompleted("signature");

        // Clear localStorage after successful submission
        localStorage.removeItem("buildingOnboardingForm");

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
                        <div
                            class="mt-4 inline-flex items-center text-sm text-green-600 bg-green-50 px-3 py-1.5 rounded-full"
                        >
                            <CheckCircle class="w-4 h-4 mr-2" />
                            Your progress is automatically saved
                        </div>
                    </div>

                    <FindProperty
                        :buildings="buildings"
                        :buildingInfo="buildingInfo"
                        v-model:full-name="fullName"
                        v-model:contact-number="contactNumber"
                        v-model:property-name="propertyName"
                        v-model:selectedBuilding-id="selectedBuildingId"
                        v-model:loadBuilding-data="loadBuildingData"
                        v-model:loading="loading"
                        @search="searchProperty"
                    />

                    <PreparetionProperty
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <PropertyPriorToMarket
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <ReKeyCodeWork
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <TenantServiceRequest
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <PetPolicy
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <Amenities
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <HVACMaintenance
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <Vendors
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <FloodHistory
                        v-if="buildingInfo.id"
                        v-model:form="form"
                        :completedSections="completedSections"
                        @sectionComplete="markSectionCompleted"
                    />

                    <!-- Preferred Vendors Section -->

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
                                        <Loader
                                            class="w-5 h-5 mr-2 animate-spin"
                                            v-if="loading"
                                        />
                                        <CheckCircle
                                            class="w-5 h-5 mr-2"
                                            v-else
                                        />
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

                    <Footer />
                </main>
            </div>
        </div>

        <!-- Modern Footer -->
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
