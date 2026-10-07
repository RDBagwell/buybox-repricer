import { describe, expect, it } from 'vitest';
import { centsToInput, formatBps, formatCents, parseMoney } from '../lib/money';

describe('money', () => {
    it('formats integer cents', () => {
        expect(formatCents(1849)).toBe('$18.49');
        expect(formatCents(5)).toBe('$0.05');
        expect(formatCents(-250)).toBe('-$2.50');
        expect(formatCents(123456789)).toBe('$1,234,567.89');
    });

    it('refuses non-integer money', () => {
        expect(() => formatCents(18.49)).toThrow();
    });

    it('parses user input into cents without floats', () => {
        expect(parseMoney('18.49')).toBe(1849);
        expect(parseMoney('$18.5')).toBe(1850);
        expect(parseMoney('18')).toBe(1800);
        expect(parseMoney('0.07')).toBe(7);
        expect(parseMoney('18.499')).toBeNull();
        expect(parseMoney('abc')).toBeNull();
        expect(parseMoney('-1')).toBeNull();
        // the classic float trap: 0.29 * 100 = 28.999999999999996
        expect(parseMoney('0.29')).toBe(29);
    });

    it('round-trips cents through the input format', () => {
        for (const c of [0, 1, 99, 100, 2699, 1234567]) {
            expect(parseMoney(centsToInput(c))).toBe(c);
        }
    });

    it('formats basis points', () => {
        expect(formatBps(1234)).toBe('12.3%');
        expect(formatBps(10000)).toBe('100.0%');
        expect(formatBps(5)).toBe('0.0%');
        expect(formatBps(-250)).toBe('-2.5%');
    });
});
