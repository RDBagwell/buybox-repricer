import { useMemo } from 'react';
import {
    CartesianGrid,
    ComposedChart,
    Legend,
    Line,
    ReferenceArea,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatCents } from '../lib/money';
import { OURS, OURS_HELD, sellerOrder, sellerStyle } from '../lib/palette';
import { formatMarketTime } from '../lib/time';
import type { Series } from '../types';

interface Props {
    series: Series | undefined;
    height?: number;
    ourLabel?: string;
}

type Row = { t: number } & Record<string, number | null>;

/** About `count` evenly spaced ticks on whole market minutes between start and end. */
export function timeTicks(start: number, end: number, count = 6): number[] {
    const minute = 60_000;
    const span = Math.max(minute, end - start);
    const step = Math.max(1, Math.round(span / count / minute)) * minute;
    const first = Math.ceil(start / step) * step;
    const ticks: number[] = [];
    for (let t = first; t <= end; t += step) {
        ticks.push(t);
    }

    return ticks;
}

/** Intervals [from, to) during which we held the Buy Box. */
export function heldIntervals(series: Series): { from: number; to: number }[] {
    const out: { from: number; to: number }[] = [];
    let start: number | null = null;
    for (const p of series.points) {
        if (p.buybox === 'ours' && start === null) {
            start = p.t;
        } else if (p.buybox !== 'ours' && start !== null) {
            out.push({ from: start, to: p.t });
            start = null;
        }
    }
    if (start !== null) {
        out.push({
            from: start,
            to: Math.max(series.now, series.points.at(-1)?.t ?? start),
        });
    }

    return out;
}

export function PriceChart({
    series,
    height = 320,
    ourLabel = 'Our price',
}: Props) {
    const view = useMemo(() => {
        if (!series || series.points.length === 0) {
            return null;
        }
        const sellers = sellerOrder(
            series.points.flatMap((p) => Object.keys(p.prices)),
        );
        // Carry each line forward (prices are step functions between events).
        const last: Record<string, number | null> = {};
        const rows: Row[] = series.points.map((p) => {
            const row: Row = { t: p.t };
            for (const key of ['ours', ...sellers]) {
                if (p.prices[key] !== undefined) {
                    last[key] = p.prices[key];
                } else {
                    // an offer missing from a snapshot is not for sale (out of stock): break its line
                    last[key] = null;
                }
                row[key] = last[key] ?? null;
            }

            return row;
        });
        const end = Math.max(series.now, rows.at(-1)!.t);
        rows.push({ ...rows.at(-1)!, t: end });

        const values = rows.flatMap((r) =>
            Object.entries(r)
                .filter(([k, v]) => k !== 't' && typeof v === 'number')
                .map(([, v]) => v as number),
        );
        const lo = Math.min(...values, series.floor ?? Infinity);
        const hi = Math.max(...values, series.ceiling ?? -Infinity);
        const pad = Math.max(25, Math.round((hi - lo) * 0.08));

        return {
            rows,
            sellers,
            held: heldIntervals(series),
            domain: [Math.max(0, lo - pad), hi + pad] as [number, number],
            start: rows[0].t,
            end,
        };
    }, [series]);

    if (!view) {
        return (
            <div
                className="flex items-center justify-center rounded-lg border border-dashed border-border text-sm text-muted-foreground"
                style={{ height }}
            >
                Waiting for the first offer change on this listing…
            </div>
        );
    }

    return (
        <figure aria-label="Landed price over market time">
            <ResponsiveContainer width="100%" height={height}>
                <ComposedChart
                    data={view.rows}
                    margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
                >
                    <CartesianGrid
                        strokeDasharray="2 4"
                        stroke="var(--border)"
                    />
                    {view.held.map((h) => (
                        <ReferenceArea
                            key={`held-${h.from}`}
                            x1={h.from}
                            x2={h.to}
                            fill={OURS_HELD}
                            strokeOpacity={0}
                            ifOverflow="hidden"
                        />
                    ))}
                    {series?.floor != null && (
                        <>
                            <ReferenceArea
                                y1={view.domain[0]}
                                y2={series.floor}
                                fill="rgba(213, 94, 0, 0.07)"
                                strokeOpacity={0}
                                ifOverflow="hidden"
                            />
                            <ReferenceLine
                                y={series.floor}
                                stroke="#D55E00"
                                strokeDasharray="4 4"
                                label={{
                                    value: `floor ${formatCents(series.floor)}`,
                                    position: 'insideBottomLeft',
                                    fontSize: 11,
                                    fill: '#A04500',
                                }}
                            />
                        </>
                    )}
                    {series?.ceiling != null && (
                        <>
                            <ReferenceArea
                                y1={series.ceiling}
                                y2={view.domain[1]}
                                fill="rgba(127, 127, 127, 0.08)"
                                strokeOpacity={0}
                                ifOverflow="hidden"
                            />
                            <ReferenceLine
                                y={series.ceiling}
                                stroke="#7F7F7F"
                                strokeDasharray="4 4"
                                label={{
                                    value: `ceiling ${formatCents(series.ceiling)}`,
                                    position: 'insideTopLeft',
                                    fontSize: 11,
                                    fill: '#555',
                                }}
                            />
                        </>
                    )}
                    <XAxis
                        dataKey="t"
                        type="number"
                        domain={[view.start, view.end]}
                        ticks={timeTicks(
                            view.start,
                            view.end,
                            height < 280 ? 4 : 7,
                        )}
                        tickFormatter={(t: number) => formatMarketTime(t)}
                        fontSize={11}
                        tickMargin={6}
                    />
                    <YAxis
                        domain={view.domain}
                        tickFormatter={(c: number) =>
                            formatCents(Math.round(c))
                        }
                        fontSize={11}
                        width={64}
                        allowDecimals={false}
                    />
                    <Tooltip
                        labelFormatter={(t) =>
                            `Market time ${formatMarketTime(Number(t), true)}`
                        }
                        formatter={(v, name) => [
                            typeof v === 'number' ? formatCents(v) : '—',
                            name === 'ours' ? ourLabel : String(name),
                        ]}
                        contentStyle={{ fontSize: 12, borderRadius: 8 }}
                    />
                    <Legend
                        formatter={(name) =>
                            name === 'ours' ? ourLabel : name
                        }
                        wrapperStyle={{ fontSize: 12 }}
                    />
                    {view.sellers.map((s, i) => {
                        const style = sellerStyle(i);

                        return (
                            <Line
                                key={s}
                                dataKey={s}
                                name={s}
                                type="stepAfter"
                                stroke={style.stroke}
                                strokeDasharray={style.dash}
                                strokeWidth={1.5}
                                dot={false}
                                isAnimationActive={false}
                                connectNulls={false}
                            />
                        );
                    })}
                    <Line
                        dataKey="ours"
                        name="ours"
                        type="stepAfter"
                        stroke={OURS}
                        strokeWidth={3.5}
                        dot={false}
                        isAnimationActive={false}
                    />
                </ComposedChart>
            </ResponsiveContainer>
            <figcaption className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                    <span
                        className="inline-block h-3 w-5 rounded-sm"
                        style={{
                            background: OURS_HELD,
                            outline: `1px solid ${OURS}`,
                        }}
                    />{' '}
                    we held the Buy Box
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span
                        className="inline-block h-3 w-5 rounded-sm"
                        style={{ background: 'rgba(213, 94, 0, 0.15)' }}
                    />{' '}
                    below our floor
                </span>
                <span>Landed price (price + shipping) · market time (UTC)</span>
            </figcaption>
        </figure>
    );
}
