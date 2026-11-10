import { panelCls, useLetters } from '@/Components/letters/helpers';
import { cx } from '@/Components/ui';
import type { LetterFull, Registration } from '@/types/letters';
import type { JSX } from 'react';

type Hint = { key: string; params?: Record<string, string>; action: boolean };

const COMPOSED = ['draft', 'pending_approval', 'approved', 'dispatched'] as const;
const HANDLING = ['registered', 'routed', 'in_progress', 'closed'] as const;

/** The registry entry the viewer works on when the letter reached their institution from another one. */
function incomingEntry(letter: LetterFull): Registration | undefined {
    if (letter.direction === 'incoming') return letter.registrations[0];
    const senderActs = letter.registrations.some((r) => r.direction !== 'incoming' && (r.can.acknowledge || r.can.close || r.can.route));
    if (letter.status !== 'dispatched' || senderActs) return undefined;

    return letter.registrations.find((r) => r.direction === 'incoming' && (r.can.receive || r.can.route || r.can.close));
}

function composedHint(letter: LetterFull): Hint {
    switch (letter.status) {
        case 'cancelled': return { key: 'cancelled', action: false };
        case 'draft': return letter.can.submit ? { key: 'draft', action: true } : { key: 'draftWaiting', action: false };
        case 'pending_approval':
            if (letter.can.approve) return { key: 'sign', action: true };
            return letter.signatory ? { key: 'waitingSignatureOf', params: { name: letter.signatory.name }, action: false } : { key: 'waitingSignature', action: false };
        case 'approved': return letter.can.dispatch ? { key: 'dispatch', action: true } : { key: 'waitingDispatch', action: false };
        default: {
            const own = letter.registrations.find((r) => r.direction !== 'incoming');
            return own?.can.acknowledge && !own.acknowledged_at ? { key: 'acknowledgeReceipt', action: true } : { key: 'dispatched', action: false };
        }
    }
}

function handlingHint(entry: Registration): Hint | null {
    if (entry.status === 'pending_receipt') return entry.can.receive ? { key: 'receive', action: true } : null;
    if (entry.status === 'closed') return { key: 'closed', action: false };
    const open = entry.routings.filter((r) => r.status === 'pending' || r.status === 'acknowledged');
    if (open.some((r) => r.can_act)) return { key: 'act', action: true };
    if (open.length > 0) return { key: 'waitingHandler', action: false };
    if (entry.routings.length === 0) return entry.can.route ? { key: 'forward', action: true } : null;

    return entry.can.close ? { key: 'close', action: true } : null;
}

/**
 * Where a letter stands and what happens next, in one strip: the steps of
 * its life (drafting to dispatch, or registration to closing for a letter
 * that came in) with the current one marked, and one sentence that names the
 * viewer's next action or says who the letter is waiting for.
 */
export default function LetterProgress({ letter }: { letter: LetterFull }): JSX.Element | null {
    const { lt } = useLetters();
    const entry = incomingEntry(letter);
    const handling = entry !== undefined;

    const steps: { key: string; label: string }[] = handling
        ? [...(entry.status === 'pending_receipt' ? ['pending_receipt'] : []), ...HANDLING].map((s) => ({ key: s, label: lt(`registrationStatus.${s}`) }))
        : COMPOSED.map((s) => ({ key: s, label: lt(`status.${s}`) }));
    const currentKey = handling ? entry.status : letter.status;
    const current = steps.findIndex((s) => s.key === currentKey);
    const cancelled = !handling && letter.status === 'cancelled';
    const finished = currentKey === 'dispatched' || currentKey === 'closed';
    const hint = handling ? handlingHint(entry) : composedHint(letter);
    if (current < 0 && !cancelled) return null;

    return (
        <section className={cx(panelCls, 'mb-4 p-4')} aria-label={lt('show.progress')}>
            {!cancelled && (
                <ol className="flex items-start">
                    {steps.map((step, index) => {
                        const done = index < current || (finished && index === current);
                        const active = index === current && !finished;

                        return (
                            <li key={step.key} className="relative flex min-w-0 flex-1 flex-col items-center text-center" aria-current={index === current ? 'step' : undefined}>
                                {index > 0 && <span className={cx('absolute end-1/2 top-3.5 h-0.5 w-full -translate-y-1/2', index <= current ? 'bg-[#122170] dark:bg-blue-300' : 'bg-(--app-border)')} aria-hidden="true" />}
                                <span className={cx('relative z-10 flex size-7 items-center justify-center rounded-full border-2 text-xs font-semibold',
                                    done ? 'border-[#122170] bg-[#122170] text-white dark:border-blue-300 dark:bg-blue-300 dark:text-slate-900'
                                        : active ? 'border-[#122170] bg-(--app-card) text-[#122170] ring-4 ring-[#122170]/15 dark:border-blue-300 dark:text-blue-200'
                                            : 'border-(--app-border) bg-(--app-card) text-(--app-muted-foreground)')}>
                                    {done ? <svg viewBox="0 0 16 16" className="size-3.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M3 8.5l3.2 3L13 4.5" /></svg> : index + 1}
                                </span>
                                <span className={cx('mt-1.5 px-1 text-xs leading-tight', index === current ? 'font-semibold text-(--app-foreground)' : 'text-(--app-muted-foreground)')}>{step.label}</span>
                            </li>
                        );
                    })}
                </ol>
            )}
            {hint && (
                <p className={cx('flex flex-wrap items-baseline gap-x-2 text-sm', !cancelled && 'mt-4 border-t border-(--app-border) pt-3')} role="status">
                    <span className={cx('rounded-full px-2 py-0.5 text-xs font-semibold', hint.action ? 'bg-[#122170] text-white dark:bg-blue-300 dark:text-slate-900' : 'bg-(--app-muted) text-(--app-muted-foreground)')}>{lt(hint.action ? 'show.nextTitle' : 'common.status')}</span>
                    <span className={hint.action ? 'font-medium text-(--app-foreground)' : 'text-(--app-muted-foreground)'}>{lt(`show.next.${hint.key}`, hint.params)}</span>
                </p>
            )}
        </section>
    );
}
