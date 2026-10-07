import { describe, expect, it } from 'vitest';
import { appendBounded, mergeById } from '../lib/buffer';

describe('bounded buffers', () => {
    it('keeps the newest items by id, without duplicates, up to the limit', () => {
        const existing = [
            { id: 3, v: 'a' },
            { id: 2, v: 'a' },
        ];
        const merged = mergeById(
            existing,
            [
                { id: 2, v: 'b' },
                { id: 5, v: 'a' },
                { id: 4, v: 'a' },
            ],
            3,
        );
        expect(merged.map((x) => x.id)).toEqual([5, 4, 3]);
        expect(
            mergeById(existing, [{ id: 2, v: 'b' }], 10).find((x) => x.id === 2)
                ?.v,
        ).toBe('b');
    });

    it('never grows past its limit however long the session runs', () => {
        let list: number[] = [];
        for (let i = 0; i < 10_000; i++) {
            list = appendBounded(list, i, 500);
        }
        expect(list).toHaveLength(500);
        expect(list[0]).toBe(9500);
        expect(list[499]).toBe(9999);
    });
});

import { heldIntervals, timeTicks } from '../components/price-chart';

describe('chart helpers', () => {
    it('puts ticks on whole minutes inside the domain', () => {
        const start = Date.UTC(2026, 0, 1, 0, 0, 30);
        const ticks = timeTicks(start, start + 30 * 60_000, 6);
        expect(ticks.every((t) => t % 60_000 === 0 && t >= start)).toBe(true);
        expect(ticks.length).toBeGreaterThanOrEqual(5);
    });

    it('finds the intervals in which we held the Buy Box', () => {
        const s = {
            product_id: 1,
            now: 100,
            from: 0,
            floor: null,
            ceiling: null,
            margin_floor: null,
            points: [
                { t: 10, decision_id: 1, buybox: 'X', prices: {} },
                { t: 20, decision_id: 2, buybox: 'ours', prices: {} },
                { t: 40, decision_id: 3, buybox: 'X', prices: {} },
                { t: 60, decision_id: 4, buybox: 'ours', prices: {} },
            ],
        };
        expect(heldIntervals(s)).toEqual([
            { from: 20, to: 40 },
            { from: 60, to: 100 },
        ]);
    });
});
