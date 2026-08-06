import { ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";

const BASE_STORAGE_KEY = "work_order_city_filter";
const VALUE_PATTERN = /^(only|hide):.+$/;

// Module-scoped singleton so every page using the filter sees the same choice.
const cityFilter = ref("all");
let loadedForKey = null;
let activeStorageKey = null;

const loadStored = (storageKey) => {
    try {
        const stored = localStorage.getItem(storageKey);

        return stored === "all" || VALUE_PATTERN.test(stored ?? "") ? stored : "all";
    } catch {
        return "all";
    }
};

const persist = () => {
    if (!activeStorageKey) return;

    try {
        localStorage.setItem(activeStorageKey, cityFilter.value);
    } catch {
        // Storage full or unavailable — the in-memory value still works.
    }
};

watch(cityFilter, persist);

/** Split "only:Katy" / "hide:Katy" / "all" into its mode and city. */
export const parseCityFilter = (value) => {
    if (typeof value === "string" && VALUE_PATTERN.test(value)) {
        const separator = value.indexOf(":");

        return { mode: value.slice(0, separator), city: value.slice(separator + 1) };
    }

    return { mode: "all", city: null };
};

/**
 * Whether a work order in a building with the given city passes the filter.
 * A missing city (no building on the card) never matches "only" but does
 * survive "hide" — hiding one city should not blank out unmapped cards.
 */
export const matchesCityFilter = (filterValue, city) => {
    const { mode, city: filterCity } = parseCityFilter(filterValue);

    if (mode === "only") return city === filterCity;
    if (mode === "hide") return city !== filterCity;

    return true;
};

/**
 * FilterChip options: "All cities" plus an Only/Hide pair per city. When the
 * persisted value names a city with no work orders on the current board, a
 * synthetic pair is prepended so the chip still shows its label and can be
 * cleared.
 */
export const cityFilterOptions = (cities, currentValue) => {
    const list = [...new Set(cities ?? [])].filter(Boolean).sort();
    const { city: currentCity } = parseCityFilter(currentValue);

    if (currentCity && !list.includes(currentCity)) {
        list.unshift(currentCity);
    }

    return [
        { value: "all", label: "All cities" },
        ...list.flatMap((city) => [
            { value: `only:${city}`, label: `Only ${city}` },
            { value: `hide:${city}`, label: `Hide ${city}` },
        ]),
    ];
};

/**
 * The user's city show/hide filter on the vendor work orders page, persisted
 * per user in localStorage so it survives refreshes (unlike the other filters,
 * which reset). Keyed by user id so accounts sharing a browser never inherit
 * each other's view.
 */
export function useCityFilter() {
    const userId = usePage().props.auth?.user?.id ?? "guest";
    const storageKey = `${BASE_STORAGE_KEY}:${userId}`;

    if (loadedForKey !== storageKey) {
        loadedForKey = storageKey;
        activeStorageKey = storageKey;
        cityFilter.value = loadStored(storageKey);
    }

    return { cityFilter };
}
