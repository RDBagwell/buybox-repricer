import type { Decision } from '../types';
import { badgeFor } from './trace';

/** What the feed's filter chips group decisions into. */
export type FeedKind = 'price' | 'held' | 'skipped' | 'vetoed';

export const FEED_KINDS: { kind: FeedKind; label: string; hint: string }[] = [
    {
        kind: 'price',
        label: 'Price moves',
        hint: 'Repriced, dry runs, and reprices that were blocked or failed',
    },
    { kind: 'held', label: 'Held', hint: 'Decided to keep the current price' },
    {
        kind: 'skipped',
        label: 'Skipped',
        hint: 'Paused, kill switch, cooldown or a stale event',
    },
    { kind: 'vetoed', label: 'Vetoed', hint: 'Configuration errors' },
];

export interface FeedFilter {
    /** Only this product's decisions (null = every product). */
    productId: number | null;
    kinds: FeedKind[];
    /** "Still cooling down" skips are most of the feed in a busy war; hidden by default. */
    hideCooldown: boolean;
}

export const DEFAULT_FEED_FILTER: FeedFilter = {
    productId: null,
    kinds: ['price', 'held', 'skipped', 'vetoed'],
    hideCooldown: true,
};

export function feedKind(d: Pick<Decision, 'outcome' | 'push'>): FeedKind {
    switch (badgeFor(d)) {
        case 'reprice':
        case 'dry_run':
        case 'blocked':
        case 'push_failed':
            return 'price';
        case 'no_change':
            return 'held';
        case 'config_error':
            return 'vetoed';
        default:
            return 'skipped';
    }
}

export function isCooldownSkip(
    d: Pick<Decision, 'outcome' | 'reason_code'>,
): boolean {
    return d.outcome === 'skipped' && d.reason_code === 'cooldown';
}

/** The decisions the feed shows, and how many each chip would show (product filter applied). */
export function filterDecisions<
    T extends Pick<Decision, 'product_id' | 'outcome' | 'reason_code' | 'push'>,
>(
    decisions: T[],
    filter: FeedFilter,
): { shown: T[]; counts: Record<FeedKind, number>; cooldownHidden: number } {
    const counts: Record<FeedKind, number> = {
        price: 0,
        held: 0,
        skipped: 0,
        vetoed: 0,
    };
    let cooldownHidden = 0;
    const shown: T[] = [];

    for (const d of decisions) {
        if (filter.productId !== null && d.product_id !== filter.productId) {
            continue;
        }
        if (filter.hideCooldown && isCooldownSkip(d)) {
            cooldownHidden++;
            continue;
        }
        const kind = feedKind(d);
        counts[kind]++;
        if (filter.kinds.includes(kind)) {
            shown.push(d);
        }
    }

    return { shown, counts, cooldownHidden };
}
