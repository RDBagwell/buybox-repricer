/**
 * Money is integer cents end to end; it only becomes text here, at the edge of the UI.
 * No floating-point arithmetic: dollars and cents are split with integer remainder.
 */
export function formatCents(cents: number): string {
    if (!Number.isInteger(cents)) {
        throw new Error(`Money must be integer cents, got ${cents}`);
    }
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(cents);
    const rem = abs % 100;
    const dollars = (abs - rem) / 100;

    return `${sign}$${dollars.toLocaleString('en-US')}.${String(rem).padStart(2, '0')}`;
}

/** "12.34" | "$12.34" | "12" -> 1234; anything else (including "12.345") -> null. */
export function parseMoney(input: string): number | null {
    const m = input
        .trim()
        .replace(/^\$/, '')
        .match(/^(\d{1,7})(?:\.(\d{1,2}))?$/);
    if (!m) {
        return null;
    }

    return Number(m[1]) * 100 + Number((m[2] ?? '0').padEnd(2, '0'));
}

/** Cents -> "12.34" for an input field. */
export function centsToInput(cents: number): string {
    const abs = Math.abs(cents);
    const rem = abs % 100;

    return `${cents < 0 ? '-' : ''}${(abs - rem) / 100}.${String(rem).padStart(2, '0')}`;
}

/** Basis points -> "12.3%" (one decimal, truncated). */
export function formatBps(bps: number): string {
    const sign = bps < 0 ? '-' : '';
    const abs = Math.abs(Math.trunc(bps));
    const tenths = (abs - (abs % 10)) / 10;

    return `${sign}${(tenths - (tenths % 10)) / 10}.${tenths % 10}%`;
}
