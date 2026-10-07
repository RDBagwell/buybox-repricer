/**
 * Bounded, de-duplicated buffers so a long-running dashboard never grows without limit.
 */

/** Merge items by id, newest (highest id) first, keeping at most `max`. */
export function mergeById<T extends { id: number }>(
    existing: T[],
    incoming: T[],
    max: number,
): T[] {
    const byId = new Map<number, T>();
    for (const item of existing) {
        byId.set(item.id, item);
    }
    for (const item of incoming) {
        byId.set(item.id, item); // newer copy (e.g. push status updated) wins
    }

    return [...byId.values()].sort((a, b) => b.id - a.id).slice(0, max);
}

/** Append to a time-ordered list, keeping only the last `max` entries. */
export function appendBounded<T>(list: T[], item: T, max: number): T[] {
    const next =
        list.length >= max ? list.slice(list.length - max + 1) : list.slice();
    next.push(item);

    return next;
}
