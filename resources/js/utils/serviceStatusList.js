/**
 * The board's `service_status` prop as a real array.
 *
 * Inertia serializes the board as a JSON object keyed by index ("0", "1", …)
 * rather than a JSON array, because the controller hands it an Eloquent
 * collection whose keys survive the reject()/push() reordering. Vue's v-for
 * walks either shape happily, which is why the template never had to care —
 * but Array methods (reduce, filter, for…of) throw on the object form, and a
 * throw inside a computed blanks the whole board.
 *
 * @param {Array|Object|null|undefined} serviceStatus
 * @returns {Array<Object>}
 */
export function statusList(serviceStatus) {
    if (Array.isArray(serviceStatus)) {
        return serviceStatus;
    }

    if (serviceStatus && typeof serviceStatus === "object") {
        return Object.values(serviceStatus);
    }

    return [];
}
