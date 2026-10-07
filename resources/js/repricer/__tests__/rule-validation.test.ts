import { describe, expect, it } from 'vitest';
import type { RuleForm } from '../lib/rule-validation';
import { validateRule } from '../lib/rule-validation';

// FP-1L-STEEL: cost 14.00 + fees 4.35
const product = { cost: 1400, fees: 435 };

function form(overrides: Partial<RuleForm> = {}): RuleForm {
    return {
        strategy: 'beat_buybox',
        offset: '0.10',
        floor: '26.99',
        ceiling: '34.99',
        min_margin: '3.00',
        max_step_pct: '5',
        cooldown_sec: '120',
        ...overrides,
    };
}

describe('validateRule (client mirror of the server rules)', () => {
    it('accepts a valid rule and converts money to integer cents', () => {
        const { errors, payload } = validateRule(form(), product);
        expect(errors).toEqual({});
        expect(payload).toEqual({
            strategy: 'beat_buybox',
            offset: 10,
            floor: 2699,
            ceiling: 3499,
            min_margin: 300,
            max_step_pct: 5,
            cooldown_sec: 120,
        });
    });

    it('rejects a floor above the ceiling', () => {
        expect(
            validateRule(form({ floor: '36.00', ceiling: '35.00' }), product)
                .errors.floor,
        ).toBe('The floor ($36.00) must not be above the ceiling ($35.00).');
    });

    it('rejects a floor below the margin floor, naming it', () => {
        expect(
            validateRule(form({ floor: '20.00' }), product).errors.floor,
        ).toBe(
            'The floor ($20.00) is below the margin floor ($21.35 = cost + fees + minimum margin).',
        );
    });

    it('keeps percentages within sane bounds', () => {
        expect(
            validateRule(form({ max_step_pct: '0' }), product).errors
                .max_step_pct,
        ).toBeDefined();
        expect(
            validateRule(form({ max_step_pct: '75' }), product).errors
                .max_step_pct,
        ).toBeDefined();
        expect(
            validateRule(form({ max_step_pct: '7.5' }), product).errors
                .max_step_pct,
        ).toBeDefined();
        expect(
            validateRule(form({ max_step_pct: '50' }), product).errors
                .max_step_pct,
        ).toBeUndefined();
    });

    it('rejects malformed money and out-of-range values', () => {
        expect(
            validateRule(form({ ceiling: '12.345' }), product).errors.ceiling,
        ).toBe('Enter an amount like 12.34.');
        expect(
            validateRule(form({ offset: '150.00' }), product).errors.offset,
        ).toBe('Must be at most $100.00.');
        expect(
            validateRule(form({ cooldown_sec: '90000' }), product).errors
                .cooldown_sec,
        ).toBeDefined();
        expect(
            validateRule(form({ cooldown_sec: '-5' }), product).errors
                .cooldown_sec,
        ).toBeDefined();
    });

    it('returns no payload while anything is invalid', () => {
        expect(
            validateRule(form({ floor: '1.00' }), product).payload,
        ).toBeNull();
    });
});
