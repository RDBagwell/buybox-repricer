/**
 * Our offer first, then the competitors in a stable order (by seller), so adding a bot never
 * moves "Us" around in a listing.
 */
export function oursFirst<T extends { seller: string }>(offers: T[]): T[] {
    return [...offers].sort((a, b) => {
        if (a.seller === 'ours' || b.seller === 'ours') {
            return a.seller === 'ours' ? (b.seller === 'ours' ? 0 : -1) : 1;
        }

        return a.seller.localeCompare(b.seller);
    });
}
