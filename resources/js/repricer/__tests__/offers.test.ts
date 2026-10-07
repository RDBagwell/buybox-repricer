import { describe, expect, it } from 'vitest';
import { oursFirst } from '../lib/offers';

describe('oursFirst', () => {
    it('always lists our offer first, whatever the competitors are called', () => {
        const sellers = (o: { seller: string }[]) =>
            oursFirst(o).map((x) => x.seller);

        expect(
            sellers([
                { seller: 'BARGAIN-BIN' },
                { seller: 'ours' },
                { seller: 'PENNYWISE' },
            ]),
        ).toEqual(['ours', 'BARGAIN-BIN', 'PENNYWISE']);
        // Adding a competitor that sorts before everyone does not move us.
        expect(
            sellers([
                { seller: 'PENNYWISE' },
                { seller: 'ours' },
                { seller: 'AAA-NEW' },
            ]),
        ).toEqual(['ours', 'AAA-NEW', 'PENNYWISE']);
    });

    it('does not mutate its input', () => {
        const input = [{ seller: 'Z' }, { seller: 'ours' }];
        oursFirst(input);
        expect(input.map((o) => o.seller)).toEqual(['Z', 'ours']);
    });
});
