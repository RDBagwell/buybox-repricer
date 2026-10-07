import type { Decision, TraceEntry } from '../types';
import { formatCents } from './money';

export type StepTone = 'info' | 'changed' | 'ok' | 'stop';

export interface TraceStep {
    rule: string;
    text: string;
    tone: StepTone;
}

export interface DescribedTrace {
    /** One line, e.g. "Strategy proposed $18.49 → step limit capped it to $18.95 → floor OK → pushed". */
    summary: string;
    steps: TraceStep[];
    /** The engine's own reason for each rule, for the expanded view. */
    detail: TraceEntry[];
}

const RULE_LABELS: Record<string, string> = {
    should_act: 'Should we act?',
    competitor_filter: 'Competitor filter',
    already_winning: 'Already winning?',
    strategy: 'Strategy',
    step_limit: 'Step limit',
    margin_floor: 'Margin floor',
    floor_ceiling: 'Floor / ceiling',
    no_op: 'No-op check',
    post_condition: 'Final guardrail check',
};

export function ruleLabel(rule: string): string {
    return RULE_LABELS[rule] ?? rule.replace(/_/g, ' ');
}

/** Turn one rule verdict into a short plain-language step, or null when it adds nothing. */
export function describeStep(t: TraceEntry): TraceStep | null {
    const after = formatCents(t.price_after);
    const moved = t.price_after !== t.price_before;

    if (t.verdict === 'veto') {
        switch (t.code) {
            case 'kill_switch':
                return {
                    rule: t.rule,
                    text: 'kill switch is on: skipped',
                    tone: 'stop',
                };
            case 'paused':
                return {
                    rule: t.rule,
                    text: 'product is paused: skipped',
                    tone: 'stop',
                };
            case 'cooldown':
                return {
                    rule: t.rule,
                    text: 'still cooling down: skipped',
                    tone: 'stop',
                };
            case 'no_change':
                return {
                    rule: t.rule,
                    text: `no change from ${after}`,
                    tone: 'stop',
                };
            case 'config_error':
            case 'guardrail_violation':
                return {
                    rule: t.rule,
                    text: 'configuration error: vetoed',
                    tone: 'stop',
                };
            default:
                return {
                    rule: t.rule,
                    text: `vetoed (${t.reason})`,
                    tone: 'stop',
                };
        }
    }

    switch (t.rule) {
        case 'should_act':
            return null;
        case 'competitor_filter': {
            const m = t.reason.match(/^Ignored (\d+) of (\d+)/);
            if (!m) {
                return null;
            }

            return {
                rule: t.rule,
                text: `ignored ${m[1]} of ${m[2]} competitors`,
                tone: 'info',
            };
        }
        case 'already_winning':
            if (t.verdict === 'propose') {
                return {
                    rule: t.rule,
                    text: `holding the Buy Box, raised to ${after}`,
                    tone: 'changed',
                };
            }

            return t.reason.startsWith('We hold')
                ? { rule: t.rule, text: 'holding the Buy Box', tone: 'info' }
                : null;
        case 'strategy':
            if (t.verdict === 'propose') {
                return {
                    rule: t.rule,
                    text: `Strategy proposed ${after}`,
                    tone: 'changed',
                };
            }

            return /competitor|filtered/i.test(t.reason)
                ? {
                      rule: t.rule,
                      text: 'no competitors to price against',
                      tone: 'info',
                  }
                : null;
        case 'step_limit':
            return moved
                ? {
                      rule: t.rule,
                      text: `step limit capped it to ${after}`,
                      tone: 'changed',
                  }
                : null;
        case 'margin_floor':
            return moved
                ? {
                      rule: t.rule,
                      text: `margin floor raised it to ${after}`,
                      tone: 'changed',
                  }
                : { rule: t.rule, text: 'margin OK', tone: 'ok' };
        case 'floor_ceiling':
            if (!moved) {
                return { rule: t.rule, text: 'floor OK', tone: 'ok' };
            }

            return t.price_after > t.price_before
                ? {
                      rule: t.rule,
                      text: `floor raised it to ${after}`,
                      tone: 'changed',
                  }
                : {
                      rule: t.rule,
                      text: `ceiling lowered it to ${after}`,
                      tone: 'changed',
                  };
        case 'no_op':
            return null;
        default:
            return moved
                ? {
                      rule: t.rule,
                      text: `${ruleLabel(t.rule)} set ${after}`,
                      tone: 'changed',
                  }
                : null;
    }
}

/** How the decision ended, in words. */
export function describeEnding(d: Decision): TraceStep {
    switch (d.outcome) {
        case 'reprice': {
            const status = d.push?.status;
            if (status === 'succeeded') {
                return { rule: 'push', text: 'pushed', tone: 'ok' };
            }
            if (status === 'retrying') {
                return {
                    rule: 'push',
                    text: `push retrying (attempt ${d.push?.attempts ?? 1})`,
                    tone: 'info',
                };
            }
            if (status === 'superseded') {
                return {
                    rule: 'push',
                    text: 'superseded by a newer decision',
                    tone: 'info',
                };
            }
            if (status === 'blocked') {
                return {
                    rule: 'push',
                    text: 'blocked by the circuit breaker',
                    tone: 'stop',
                };
            }
            if (status === 'cancelled' || status === 'failed') {
                return { rule: 'push', text: `push ${status}`, tone: 'stop' };
            }

            return { rule: 'push', text: 'push queued', tone: 'info' };
        }
        case 'dry_run':
            return { rule: 'push', text: 'dry run: not pushed', tone: 'info' };
        case 'stale':
            return {
                rule: 'stale',
                text: 'older than the latest snapshot: skipped',
                tone: 'stop',
            };
        default:
            return { rule: 'end', text: '', tone: 'info' };
    }
}

export function describeTrace(d: Decision): DescribedTrace {
    const steps = d.trace
        .map(describeStep)
        .filter((s): s is TraceStep => s !== null);
    const ending = describeEnding(d);
    if (ending.text !== '') {
        steps.push(ending);
    }

    const summary =
        steps.length === 0
            ? d.reason
            : steps
                  .map((s, i) =>
                      i === 0
                          ? s.text.charAt(0).toUpperCase() + s.text.slice(1)
                          : s.text,
                  )
                  .join(' → ');

    return { summary, steps, detail: d.trace };
}

export const OUTCOME_LABELS: Record<Decision['outcome'], string> = {
    reprice: 'Repriced',
    dry_run: 'Dry run',
    no_change: 'No change',
    skipped: 'Skipped',
    config_error: 'Vetoed',
    stale: 'Stale',
};

/** What the feed's badge shows: the decision, unless its push never landed. */
export type Badge = Decision['outcome'] | 'blocked' | 'push_failed';

export const BADGE_LABELS: Record<Badge, string> = {
    ...OUTCOME_LABELS,
    blocked: 'Blocked',
    push_failed: 'Push failed',
};

/**
 * A "reprice" decision whose push the circuit breaker blocked (or that failed after its
 * retries) did not change the price, so the badge must not say "Repriced".
 */
export function badgeFor(d: Pick<Decision, 'outcome' | 'push'>): Badge {
    if (d.outcome === 'reprice' && d.push) {
        if (d.push.status === 'blocked') {
            return 'blocked';
        }
        if (d.push.status === 'failed') {
            return 'push_failed';
        }
    }

    return d.outcome;
}
