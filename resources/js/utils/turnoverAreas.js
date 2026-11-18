// Parts of a home a turnover "after" photo can be filed under, in the order
// the office walks the property. Mirrors Attachments::TURNOVER_AREAS in
// app/Models/Attachments.php, which is what the server accepts; keep both
// lists the same.
export const TURNOVER_AREAS = [
    "Entrance",
    "Living Room",
    "Dining Room",
    "Kitchen",
    "Bedroom 1",
    "Bedroom 2",
    "Bedroom 3",
    "Bedroom 4",
    "Bathroom 1",
    "Bathroom 2",
    "Bathroom 3",
    "Bathroom 4",
    "Office Room",
    "Game Room",
    "Laundry Room",
    "Hallway",
    "Garage",
    "Carpet Cleaning",
    "Lawn",
    "Window Glass Replacement",
    "Haul Away",
];

/**
 * Group photos by area in walk-through order. Areas outside the list follow
 * in the order they appear, and photos with no area come last under
 * `otherLabel`. Returns null when no photo carries an area, so callers can
 * keep rendering one flat list.
 */
export function groupByTurnoverArea(files, otherLabel = "Other after photos") {
    const list = files || [];
    if (!list.some((file) => file?.area)) {
        return null;
    }

    const byArea = new Map();
    for (const file of list) {
        const key = file?.area || "";
        if (!byArea.has(key)) byArea.set(key, []);
        byArea.get(key).push(file);
    }

    const ordered = TURNOVER_AREAS.filter((area) => byArea.has(area));
    const extras = [...byArea.keys()].filter(
        (area) => area && !TURNOVER_AREAS.includes(area),
    );

    const groups = [...ordered, ...extras].map((area) => ({
        label: area,
        files: byArea.get(area),
    }));
    if (byArea.has("")) {
        groups.push({ label: otherLabel, files: byArea.get("") });
    }
    return groups;
}
