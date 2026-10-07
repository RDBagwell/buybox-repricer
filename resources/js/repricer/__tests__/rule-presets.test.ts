import { describe, expect, it } from 'vitest';
import { PRESETS, presetRule } from '../lib/rule-presets';
import { validateRule } from '../lib/rule-validation';

describe('presetRule', () => {
    const product = { cost: 1400, fees: 435, ceiling: 3499 };

    it('always produces rules the validator accepts', () => {
        for (const p of PRESETS) {
            for (const prod of [
                product,
                { cost: 100, fees: 30, ceiling: null },
                { cost: 5000, fees: 900, ceiling: 3000 }, // ceiling below the new floor
            ]) {
                const { errors, payload } = validateRule(
                    presetRule(p.key, prod),
                    prod,
                );
                expect(errors).toEqual({});
                expect(payload).not.toBeNull();
            }
        }
    });

    it('puts the floor exactly at the margin floor and keeps a valid ceiling', () => {
        const r = presetRule('balanced', product);
        // 18.35 base, 15% → 2.76 (rounded up) minimum margin
        expect(r.min_margin).toBe('2.76');
        expect(r.floor).toBe('21.11');
        expect(r.ceiling).toBe('34.99');
        expect(r.strategy).toBe('beat_buybox');
    });

    it('raises a ceiling that would sit below the floor', () => {
        const r = presetRule('margin_first', {
            cost: 5000,
            fees: 900,
            ceiling: 3000,
        });
        expect(r.floor).toBe('76.70'); // 59.00 + 17.70
        expect(r.ceiling).toBe('95.88'); // + 25%
    });

    it('orders the presets from most to least aggressive', () => {
        const margins = PRESETS.map((p) =>
            Number(presetRule(p.key, product).min_margin),
        );
        expect([...margins].sort((a, b) => a - b)).toEqual(margins);
    });
});
