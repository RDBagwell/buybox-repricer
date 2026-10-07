import { describe, expect, it } from 'vitest';
import { badgeFor, describePreview, describeTrace } from '../lib/trace';
import type { Decision, TraceEntry } from '../types';

function t(
    rule: string,
    verdict: TraceEntry['verdict'],
    before: number,
    after: number,
    reason = '',
    code: string | null = null,
): TraceEntry {
    return {
        rule,
        verdict,
        reason,
        price_before: before,
        price_after: after,
        code,
    };
}

function decision(
    trace: TraceEntry[],
    overrides: Partial<Decision> = {},
): Decision {
    return {
        id: 1,
        product_id: 1,
        outcome: 'reprice',
        reason_code: null,
        reason: 'Reprice.',
        old_price: 1999,
        new_price: 1895,
        event_time: '2026-01-01T00:10:00+00:00',
        decided_at: '2026-01-01T00:10:00+00:00',
        trace,
        offers: [],
        push: { status: 'succeeded', attempts: 1 },
        ...overrides,
    };
}

const repriced = [
    t('should_act', 'pass', 1999, 1999, 'Cooldown expired.'),
    t('competitor_filter', 'pass', 1999, 1999, 'All 2 competitor(s) eligible.'),
    t('already_winning', 'pass', 1999, 1999, 'We do not hold the Buy Box.'),
    t('strategy', 'propose', 1999, 1849, 'beat_lowest: ...'),
    t('step_limit', 'propose', 1849, 1895, 'capped'),
    t('margin_floor', 'pass', 1895, 1895, 'ok'),
    t('floor_ceiling', 'pass', 1895, 1895, 'within'),
    t('no_op', 'pass', 1895, 1895, 'changes'),
];

describe('describeTrace', () => {
    it('renders a repricing decision as one plain-language line', () => {
        expect(describeTrace(decision(repriced)).summary).toBe(
            'Strategy proposed $18.49 → step limit capped it to $18.95 → margin OK → floor OK → pushed',
        );
    });

    it('explains the floor lifting a price', () => {
        const trace = [
            t('strategy', 'propose', 2699, 2688),
            t('step_limit', 'pass', 2688, 2688),
            t('margin_floor', 'pass', 2688, 2688),
            t('floor_ceiling', 'propose', 2688, 2699),
            t('no_op', 'veto', 2699, 2699, 'same', 'no_change'),
        ];
        expect(
            describeTrace(decision(trace, { outcome: 'no_change', push: null }))
                .summary,
        ).toBe(
            'Strategy proposed $26.88 → margin OK → floor raised it to $26.99 → no change from $26.99',
        );
    });

    it('explains the ceiling capping a raise', () => {
        const trace = [
            t(
                'already_winning',
                'propose',
                2900,
                3200,
                'We hold the Buy Box; raising',
            ),
            t('floor_ceiling', 'propose', 3200, 3000),
        ];
        expect(describeTrace(decision(trace)).summary).toBe(
            'Holding the Buy Box, raised to $32.00 → ceiling lowered it to $30.00 → pushed',
        );
    });

    it('shows gate vetoes as skips', () => {
        expect(
            describeTrace(
                decision(
                    [
                        t(
                            'should_act',
                            'veto',
                            1999,
                            1999,
                            'Cooldown active',
                            'cooldown',
                        ),
                    ],
                    { outcome: 'skipped', push: null },
                ),
            ).summary,
        ).toBe('Still cooling down: skipped');
        expect(
            describeTrace(
                decision(
                    [
                        t(
                            'should_act',
                            'veto',
                            1999,
                            1999,
                            'Kill',
                            'kill_switch',
                        ),
                    ],
                    { outcome: 'skipped', push: null },
                ),
            ).summary,
        ).toBe('Kill switch is on: skipped');
    });

    it('shows configuration errors as vetoes', () => {
        const trace = [
            t('strategy', 'propose', 1000, 900),
            t(
                'margin_floor',
                'veto',
                900,
                900,
                'Configuration error',
                'config_error',
            ),
        ];
        expect(
            describeTrace(
                decision(trace, { outcome: 'config_error', push: null }),
            ).summary,
        ).toBe('Strategy proposed $9.00 → configuration error: vetoed');
    });

    it('says when a decision was a dry run, superseded, blocked or stale', () => {
        expect(
            describeTrace(
                decision(repriced, { outcome: 'dry_run', push: null }),
            ).summary,
        ).toMatch(/→ dry run: not pushed$/);
        expect(
            describeTrace(
                decision(repriced, {
                    push: { status: 'superseded', attempts: 1 },
                }),
            ).summary,
        ).toMatch(/superseded by a newer decision$/);
        expect(
            describeTrace(
                decision(repriced, {
                    push: { status: 'blocked', attempts: 1 },
                }),
            ).summary,
        ).toMatch(/blocked by the circuit breaker$/);
        expect(
            describeTrace(
                decision([], {
                    outcome: 'stale',
                    push: null,
                    reason: 'Stale event',
                }),
            ).summary,
        ).toBe('Older than the latest snapshot: skipped');
    });

    it('mentions ignored competitors', () => {
        const trace = [
            t(
                'competitor_filter',
                'pass',
                1499,
                1499,
                'Ignored 1 of 2: BARGAIN-BIN (rating 71 < 85).',
            ),
            t('strategy', 'propose', 1499, 1394),
        ];
        expect(describeTrace(decision(trace, { push: null })).summary).toBe(
            'Ignored 1 of 2 competitors → Strategy proposed $13.94 → push queued',
        );
    });

    it('keeps every rule verdict for the expanded view', () => {
        expect(describeTrace(decision(repriced)).detail).toHaveLength(8);
    });
});

describe('badgeFor', () => {
    it('never calls a blocked or failed push "Repriced"', () => {
        const push = (status: string) => ({ status, attempts: 1 });
        expect(badgeFor({ outcome: 'reprice', push: push('succeeded') })).toBe(
            'reprice',
        );
        expect(badgeFor({ outcome: 'reprice', push: push('blocked') })).toBe(
            'blocked',
        );
        expect(badgeFor({ outcome: 'reprice', push: push('failed') })).toBe(
            'push_failed',
        );
        expect(badgeFor({ outcome: 'reprice', push: null })).toBe('reprice');
        expect(badgeFor({ outcome: 'skipped', push: null })).toBe('skipped');
    });
});

describe('describePreview', () => {
    it('ends with the price the draft rules would set', () => {
        expect(
            describePreview({
                available: true,
                outcome: 'reprice',
                old_price: 1388,
                new_price: 1382,
                trace: [
                    {
                        rule: 'strategy',
                        verdict: 'propose',
                        reason: 'beat lowest',
                        price_before: 1388,
                        price_after: 1382,
                        code: null,
                    },
                ],
            }),
        ).toBe('Strategy proposed $13.82 → would set $13.82');
    });

    it('says when the price would be kept, and explains a missing snapshot', () => {
        expect(
            describePreview({
                available: true,
                outcome: 'no_change',
                old_price: 2999,
                new_price: null,
                trace: [],
            }),
        ).toBe('Would keep $29.99');
        expect(
            describePreview({
                available: false,
                message: 'No market snapshot yet.',
            }),
        ).toBe('No market snapshot yet.');
    });
});
