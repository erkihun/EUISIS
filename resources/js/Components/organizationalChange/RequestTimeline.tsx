import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { JSX } from 'react';
import type { ChangeRequestDetail } from './types';

type Props = { request: ChangeRequestDetail };

type Stage = {
    key: string;
    labelKey: string;
    actorKey: string;
    at: string | null;
    actor: string | null;
    state: 'done' | 'current' | 'pending';
};

/**
 * Compact stage timeline built from real timestamps and real users.
 *
 * The stages deliberately separate approval from implementation, because that
 * separation is the point of the workflow: "Approved" being ticked says
 * nothing about whether any record has changed yet.
 */
export default function RequestTimeline({ request }: Props): JSX.Element {
    const { t } = useLocale();

    const isRejected = request.status === 'rejected';
    const isCancelled = request.status === 'cancelled';

    function stageState(at: string | null, isCurrent: boolean): Stage['state'] {
        if (at) return 'done';
        return isCurrent ? 'current' : 'pending';
    }

    const stages: Stage[] = [
        {
            key: 'submitted',
            labelKey: 'stageSubmitted',
            actorKey: 'actorRequester',
            at: request.submitted_at,
            actor: request.requester?.name ?? null,
            state: stageState(request.submitted_at, request.status === 'draft'),
        },
        {
            key: 'review',
            labelKey: 'stageReview',
            actorKey: 'actorReviewer',
            at: request.review_started_at,
            actor: request.reviewer?.name ?? null,
            state: stageState(request.review_started_at, ['submitted', 'resubmitted', 'under_review', 'correction_requested'].includes(request.status)),
        },
        {
            key: 'approved',
            labelKey: 'stageApproved',
            actorKey: 'actorApprover',
            at: request.approved_at,
            actor: request.approver?.name ?? null,
            state: stageState(request.approved_at, false),
        },
        {
            key: 'pending_implementation',
            labelKey: 'stagePendingImplementation',
            actorKey: 'actorImplementer',
            at: request.implementation_assigned_at ?? (request.approved_at ? request.approved_at : null),
            actor: request.implementation_assignee?.name ?? request.implementing_unit?.name_en ?? null,
            state: request.implemented_at
                ? 'done'
                : stageState(null, ['pending_implementation', 'implementing', 'implementation_blocked'].includes(request.status)),
        },
        {
            key: 'implemented',
            labelKey: 'stageImplemented',
            actorKey: 'actorImplementer',
            at: request.implemented_at,
            actor: request.implementer?.name ?? null,
            state: stageState(request.implemented_at, request.status === 'implementing'),
        },
        {
            key: 'completed',
            labelKey: 'stageCompleted',
            actorKey: 'actorImplementer',
            at: request.completed_at,
            actor: null,
            state: stageState(request.completed_at, request.status === 'implemented'),
        },
    ];

    function marker(state: Stage['state']): JSX.Element {
        if (state === 'done') {
            return <span className="text-emerald-600 dark:text-emerald-400" aria-hidden="true">&#10003;</span>;
        }
        if (state === 'current') {
            return <span className="text-[color:var(--color-primary)]" aria-hidden="true">&#9679;</span>;
        }
        return <span className="text-gray-300 dark:text-slate-600" aria-hidden="true">&#9675;</span>;
    }

    return (
        <section className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <header className="border-b border-gray-200 px-4 py-3 dark:border-slate-800">
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {t('organizationalChangeRequests.timeline.heading')}
                </h2>
            </header>

            <ol className="divide-y divide-gray-100 dark:divide-slate-800">
                {stages.map(stage => (
                    <li key={stage.key} className="flex items-baseline gap-3 px-4 py-2.5">
                        <span className="w-4 shrink-0 text-center text-sm">{marker(stage.state)}</span>
                        <span className="w-48 shrink-0 text-sm font-medium text-gray-900 dark:text-slate-100">
                            {t(`organizationalChangeRequests.timeline.${stage.labelKey}`)}
                        </span>
                        <span className="flex-1 text-xs text-gray-500 dark:text-slate-400">
                            {stage.actor ?? t(`organizationalChangeRequests.timeline.${stage.actorKey}`)}
                        </span>
                        <span className="shrink-0 text-xs text-gray-500 dark:text-slate-400">
                            {stage.at
                                ? <LocalizedDateDisplay value={stage.at} withTime />
                                : t('organizationalChangeRequests.timeline.pending')}
                        </span>
                    </li>
                ))}

                {(isRejected || isCancelled) && (
                    <li className="flex items-baseline gap-3 bg-red-50/50 px-4 py-2.5 dark:bg-red-950/20">
                        <span className="w-4 shrink-0 text-center text-sm text-red-600 dark:text-red-400" aria-hidden="true">&#10007;</span>
                        <span className="w-48 shrink-0 text-sm font-medium text-red-800 dark:text-red-300">
                            {t(`organizationalChangeRequests.statuses.${request.status}`)}
                        </span>
                        <span className="flex-1 text-xs text-red-700 dark:text-red-400">
                            {request.decision_comment ?? ''}
                        </span>
                        <span className="shrink-0 text-xs text-red-700 dark:text-red-400">
                            {(request.rejected_at ?? request.cancelled_at)
                                ? <LocalizedDateDisplay value={(request.rejected_at ?? request.cancelled_at) as string} withTime />
                                : ''}
                        </span>
                    </li>
                )}
            </ol>
        </section>
    );
}
