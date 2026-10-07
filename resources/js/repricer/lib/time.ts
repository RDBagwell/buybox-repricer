/** Market time is shown as HH:MM (UTC) plus a day counter from the simulation epoch. */
export function formatMarketTime(
    input: string | number,
    withSeconds = false,
): string {
    const d = new Date(input);
    if (Number.isNaN(d.getTime())) {
        return '--:--';
    }
    const hh = String(d.getUTCHours()).padStart(2, '0');
    const mm = String(d.getUTCMinutes()).padStart(2, '0');
    const ss = String(d.getUTCSeconds()).padStart(2, '0');

    return withSeconds ? `${hh}:${mm}:${ss}` : `${hh}:${mm}`;
}

export function marketDay(
    input: string | number,
    epoch = Date.UTC(2026, 0, 1),
): number {
    return Math.floor((new Date(input).getTime() - epoch) / 86_400_000) + 1;
}
