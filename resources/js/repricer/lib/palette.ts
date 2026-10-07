/**
 * Okabe–Ito palette: distinguishable for the common forms of colour blindness. Competitors also
 * get distinct dash patterns, so lines never rely on colour alone.
 */
export const OURS = '#0072B2';
export const OURS_HELD = 'rgba(0, 114, 178, 0.16)';

const BOTS = ['#E69F00', '#009E73', '#D55E00', '#CC79A7', '#56B4E9', '#7F7F7F'];
const DASHES = ['6 3', '2 3', '10 4', '4 2 1 2', '1 2', '8 3 2 3'];

export function sellerStyle(index: number): { stroke: string; dash: string } {
    return {
        stroke: BOTS[index % BOTS.length],
        dash: DASHES[index % DASHES.length],
    };
}

/** Stable order: alphabetical, so a seller keeps its colour across renders. */
export function sellerOrder(sellers: Iterable<string>): string[] {
    return [...new Set(sellers)].filter((s) => s !== 'ours').sort();
}
