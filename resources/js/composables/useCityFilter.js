import { ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";

const BASE_STORAGE_KEY = "work_order_hidden_cities";

// Module-scoped singleton so every page using the filter sees the same choice.
const hiddenCities = ref([]);
let loadedForKey = null;
let activeStorageKey = null;

const loadStored = (storageKey) => {
    try {
        const stored = JSON.parse(localStorage.getItem(storageKey));

        return Array.isArray(stored)
            ? stored.filter((city) => typeof city === "string" && city !== "")
            : [];
    } catch {
        return [];
    }
};

const persist = () => {
    if (!activeStorageKey) return;

    try {
        localStorage.setItem(activeStorageKey, JSON.stringify(hiddenCities.value));
    } catch {
        // Storage full or unavailable — the in-memory list still works.
    }
};

watch(hiddenCities, persist, { deep: true });

/**
 * Whether a work order in a building with the given city is visible. A card
 * without a city (no building) is never hidden — hiding one city should not
 * blank out unmapped cards.
 */
export const matchesCityFilter = (hidden, city) =>
    !city || !(hidden ?? []).includes(city);

/**
 * The user's hidden-cities picks on the vendor work orders page, persisted per
 * user in localStorage so they survive refreshes (e.g. THMP keeps Nacogdoches
 * hidden permanently). Keyed by user id so accounts sharing a browser never
 * inherit each other's picks.
 */
export function useCityFilter() {
    const userId = usePage().props.auth?.user?.id ?? "guest";
    const storageKey = `${BASE_STORAGE_KEY}:${userId}`;

    if (loadedForKey !== storageKey) {
        loadedForKey = storageKey;
        activeStorageKey = storageKey;
        hiddenCities.value = loadStored(storageKey);
    }

    const isCityHidden = (city) => hiddenCities.value.includes(city);

    const toggleCity = (city) => {
        hiddenCities.value = isCityHidden(city)
            ? hiddenCities.value.filter((hidden) => hidden !== city)
            : [...hiddenCities.value, city];
    };

    const showAllCities = () => {
        hiddenCities.value = [];
    };

    return { hiddenCities, isCityHidden, toggleCity, showAllCities };
}