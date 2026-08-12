import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';

type StepStatus = 'completed' | 'current' | 'upcoming' | 'terminal';

type TimelineStep = {
    key: string;
    label: string;
    status: StepStatus;
    timestamp?: string | null;
    actor?: string | null;
    note?: string | null;
};

/** Ordered lifecycle stages for a card request → card */
const REQUEST_LIFECYCLE = ['submitted', 'verified', 'approved'];
const CARD_LIFECYCLE    = ['pending_print', 'printed', 'issued', 'active'];
const ALL_STEPS         = [...REQUEST_LIFECYCLE, ...CARD_LIFECYCLE];

const TERMINAL_STATUSES = ['rejected', 'cancelled', 'lost', 'damaged', 'suspended', 'revoked', 'expired', 'replaced'];

function stepStatus(cardStatus: string, stepKey: string): StepStatus {
    if (TERMINAL_STATUSES.includes(cardStatus)) {
        const stepIdx = ALL_STEPS.indexOf(stepKey);
        const cardIdx = ALL_STEPS.indexOf(cardStatus);
        if (cardIdx === -1) {
            // cardStatus is a terminal with no position in ALL_STEPS; mark all as completed
            return stepIdx >= 0 ? 'completed' : 'upcoming';
        }
        return stepIdx < cardIdx ? 'completed' : 'upcoming';
    }
    const cardIdx = ALL_STEPS.indexOf(cardStatus);
    const stepIdx = ALL_STEPS.indexOf(stepKey);
    if (cardIdx === -1) return 'upcoming';
    if (stepIdx < cardIdx)  return 'completed';
    if (stepIdx === cardIdx) return 'current';
    return 'upcoming';
}

const stepDotCls: Record<StepStatus, string> = {
    completed: 'bg-emerald-500 border-emerald-500 shadow-sm shadow-emerald-200 dark:shadow-emerald-900',
    current:   'bg-blue-500 border-blue-500 shadow-sm shadow-blue-200 dark:shadow-blue-900 ring-2 ring-blue-100 dark:ring-blue-900/40',
    upcoming:  'bg-white border-gray-300 dark:bg-slate-800 dark:border-slate-600',
    terminal:  'bg-red-500 border-red-500 shadow-sm shadow-red-200 dark:shadow-red-900',
};

const stepLabelCls: Record<StepStatus, string> = {
    completed: 'text-gray-800 dark:text-slate-200 font-medium',
    current:   'text-blue-700 dark:text-blue-400 font-semibold',
    upcoming:  'text-gray-400 dark:text-slate-500',
    terminal:  'text-red-600 dark:text-red-400 font-semibold',
};

const stepLineCls: Record<StepStatus, string> = {
    completed: 'bg-emerald-400',
    current:   'bg-gradient-to-b from-emerald-400 to-blue-300',
    upcoming:  'bg-gray-200 dark:bg-slate-700',
    terminal:  'bg-red-300',
};

export default function CardLifecycleTimeline({
    cardStatus,
    events,
}: {
    cardStatus: string;
    events?: Record<string, { timestamp?: string | null; actor?: string | null; note?: string | null }>;
}) {
    const { t } = useLocale();

    const steps: TimelineStep[] = ALL_STEPS.map((key) => ({
        key,
        label: t(`idCards.status_${key}`) || key.replace(/_/g, ' '),
        status: stepStatus(cardStatus, key),
        ...(events?.[key] ?? {}),
    }));

    const isTerminal = TERMINAL_STATUSES.includes(cardStatus);
    const terminalLabel = isTerminal
        ? (t(`idCards.status_${cardStatus}`) || cardStatus.replace(/_/g, ' '))
        : null;

    return (
        <div>
            <h4 className="mb-4 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-slate-500">
                {t('idCards.cardLifecycle')}
            </h4>

            <ol className="relative space-y-0">
                {steps.map((step, idx) => {
                    const isLast = idx === steps.length - 1 && !isTerminal;
                    const lineStatus: StepStatus =
                        step.status === 'completed' ? 'completed'
                        : step.status === 'current' ? 'current'
                        : 'upcoming';

                    return (
                        <li key={step.key} className="relative flex gap-3 pb-4 last:pb-0">
                            {/* Vertical connector line */}
                            {!isLast && (
                                <div
                                    className={`absolute left-[7px] top-3.5 w-0.5 bottom-0 ${stepLineCls[lineStatus]}`}
                                    aria-hidden
                                />
                            )}

                            {/* Step dot */}
                            <div className="relative flex-shrink-0 mt-0.5">
                                <div
                                    className={`h-3.5 w-3.5 rounded-full border-2 flex items-center justify-center ${stepDotCls[step.status]}`}
                                >
                                    {step.status === 'completed' && (
                                        <svg className="h-2 w-2 text-white" fill="currentColor" viewBox="0 0 8 8">
                                            <path d="M1.5 4l2 2 3-3" stroke="currentColor" strokeWidth="1.5" fill="none" strokeLinecap="round" strokeLinejoin="round"/>
                                        </svg>
                                    )}
                                </div>
                            </div>

                            {/* Step content */}
                            <div className="min-w-0 flex-1 pt-px">
                                <p className={`text-sm leading-tight ${stepLabelCls[step.status]}`}>
                                    {step.label}
                                </p>
                                {step.timestamp && (
                                    <p className="mt-0.5 text-[11px] text-gray-400 dark:text-slate-500">
                                        <LocalizedDateDisplay value={step.timestamp} withTime />
                                    </p>
                                )}
                                {step.actor && (
                                    <p className="text-[11px] text-gray-400 dark:text-slate-500">
                                        {t('idCards.byActor')} {step.actor}
                                    </p>
                                )}
                                {step.note && (
                                    <p className="mt-0.5 text-[11px] italic text-gray-400 dark:text-slate-500 truncate">
                                        {step.note}
                                    </p>
                                )}
                            </div>
                        </li>
                    );
                })}

                {/* Terminal node */}
                {isTerminal && terminalLabel && (
                    <li className="relative flex gap-3">
                        <div className="relative flex-shrink-0 mt-0.5">
                            <div className={`h-3.5 w-3.5 rounded-full border-2 ${stepDotCls.terminal}`} />
                        </div>
                        <div className="min-w-0 flex-1 pt-px">
                            <p className={`text-sm leading-tight ${stepLabelCls.terminal}`}>
                                {terminalLabel}
                            </p>
                        </div>
                    </li>
                )}
            </ol>
        </div>
    );
}
