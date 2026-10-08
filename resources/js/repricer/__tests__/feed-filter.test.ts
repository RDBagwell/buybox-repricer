import { describe, expect, it } from 'vitest';
import {
    DEFAULT_FEED_FILTER,
    feedKind,
    filterDecisions,
} from '../lib/feed-filter';
import type { Decision } from '../types';

type D = Pick<Decision, 'product_id' | 'outcome' | 'reason_code' | 'push'> & {
    id: number;
};

const d = (
    id: number,
    outcome: Decision['outcome'],
    reason_code: string | null = null,
    product_id = 1,
    push: Decision['push'] = null,
): D => ({ id, product_id, outcome, reason_code, push });

const feed: D[] = [
    d(1, 'reprice', null, 1, { status: 'succeeded', attempts: 1 }),
    d(2, 'skipped', 'cooldown'),
    d(3, 'skipped', 'cooldown', 2),
    d(4, 'no_change', 'no_change'),
    d(5, 'skipped', 'paused'),
    d(6, 'stale', 'stale', 2),
    d(7, 'reprice', null, 2, { status: 'blocked', attempts: 1 }),
    d(8, 'config_error', 'config'),
];

describe('filterDecisions', () => {
    it('hides cooldown skips by default and counts what is left per chip', () => {
        const r = filterDecisions(feed, DEFAULT_FEED_FILTER);
        expect(r.shown.map((x) => x.id)).toEqual([1, 4, 5, 6, 7, 8]);
        expect(r.cooldownHidden).toBe(2);
        expect(r.counts).toEqual({ price: 2, held: 1, skipped: 2, vetoed: 1 });
    });

    it('shows cooldown skips when asked', () => {
        const r = filterDecisions(feed, {
            ...DEFAULT_FEED_FILTER,
            hideCooldown: false,
        });
        expect(r.shown).toHaveLength(8);
        expect(r.counts.skipped).toBe(4);
    });

    it('narrows by kind and by product together', () => {
        const r = filterDecisions(feed, {
            productId: 2,
            kinds: ['price'],
            hideCooldown: true,
        });
        expect(r.shown.map((x) => x.id)).toEqual([7]);
        expect(r.cooldownHidden).toBe(1);
    });
});

describe('feedKind', () => {
    it('counts blocked and failed reprices as price moves, stale as skipped', () => {
        expect(
            feedKind(
                d(1, 'reprice', null, 1, { status: 'blocked', attempts: 1 }),
            ),
        ).toBe('price');
        expect(feedKind(d(1, 'dry_run'))).toBe('price');
        expect(feedKind(d(1, 'stale'))).toBe('skipped');
        expect(feedKind(d(1, 'config_error'))).toBe('vetoed');
    });
});
