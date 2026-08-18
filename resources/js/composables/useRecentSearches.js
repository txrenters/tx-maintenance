import { ref } from "vue";
import { usePage } from "@inertiajs/vue3";

const BASE_STORAGE_KEY = "work_order_recent_searches";
const MAX_RECENT = 10;

// Shared singleton so every dialog instance sees the same list.
const recentSearches = ref([]);
let loadedForKey = null;

const loadStored = (storageKey) => {
    try {
        const stored = JSON.parse(localStorage.getItem(storageKey));

        return Array.isArray(stored) ? stored : [];
    } catch {
        return [];
    }
};

const persist = (storageKey) => {
    try {
        localStorage.setItem(storageKey, JSON.stringify(recentSearches.value));
    } catch {
        // Storage full or unavailable — the in-memory list still works.
    }
};

const entryKey = (entry) =>
    `${(entry.query ?? "").trim().toLowerCase()}|${entry.building_id ?? ""}`;

/**
 * Tracks the searches the user ran most recently in the global search dialog,
 * so a repeated lookup (an address, a category, a work order number) is one
 * click instead of retyping. The property filter is part of the search, so it
 * is saved and restored together with the term.
 *
 * The list is keyed by user id so accounts sharing a browser (a shared WOC
 * station, or a vendor logging in after staff) never see each other's list,
 * and it is hard-capped at MAX_RECENT entries so it can never grow unbounded.
 */
export function useRecentSearches() {
    const userId = usePage().props.auth?.user?.id ?? "guest";
    const storageKey = `${BASE_STORAGE_KEY}:${userId}`;

    if (loadedForKey !== storageKey) {
        recentSearches.value = loadStored(storageKey);
        loadedForKey = storageKey;
    }

    const rememberSearch = ({ query, buildingId, buildingName }) => {
        const trimmed = (query ?? "").trim();
        if (!trimmed && !buildingId) return;

        const entry = {
            query: trimmed,
            building_id: buildingId ?? null,
            building_name: buildingId ? (buildingName ?? "") : "",
            searched_at: Date.now(),
        };

        recentSearches.value = [
            entry,
            ...recentSearches.value.filter(
                (recent) => entryKey(recent) !== entryKey(entry),
            ),
        ].slice(0, MAX_RECENT);

        persist(storageKey);
    };

    /** Drop a single entry via the row's remove button. */
    const forgetSearch = (search) => {
        recentSearches.value = recentSearches.value.filter(
            (recent) => entryKey(recent) !== entryKey(search),
        );

        persist(storageKey);
    };

    const clearSearches = () => {
        recentSearches.value = [];

        persist(storageKey);
    };

    const searchedAgo = (recent) => {
        const minutes = Math.round(
            (Date.now() - (recent.searched_at ?? 0)) / 60000,
        );

        if (minutes < 1) return "just now";
        if (minutes < 60) return `${minutes}m ago`;
        if (minutes < 60 * 24) return `${Math.round(minutes / 60)}h ago`;

        return `${Math.round(minutes / (60 * 24))}d ago`;
    };

    return {
        recentSearches,
        rememberSearch,
        forgetSearch,
        clearSearches,
        searchedAgo,
    };
}
