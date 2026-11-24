import { cx } from '@/Components/ui';
import { mutedCls, panelCls, sectionTitleCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { ComplianceSummary } from '@/modules/reporting/types';
import { Link } from '@inertiajs/react';
import type { JSX } from 'react';

interface Props {
    summary: ComplianceSummary;
    /** The current filters, kept when a figure is opened in the register. */
    filters?: Record<string, string>;
    historical?: boolean;
}

type Tone = 'danger' | 'warning' | 'success';

const TEXT: Record<Tone, string> = { danger: 'text-red-700 dark:text-red-400', warning: 'text-amber-700 dark:text-amber-400', success: 'text-green-700 dark:text-green-400' };
const FILL: Record<Tone, string> = { danger: 'bg-red-600 dark:bg-red-500', warning: 'bg-amber-500', success: 'bg-green-600 dark:bg-green-500' };
const groupTitleCls = 'text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)';
const focusCls = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';

/** A rate with its bar. A zero denominator shows "N/A" and no bar: never 0 % or 100 %. */
function Rate({ label, rate, text }: { label: string; rate: number | null; text: string }): JSX.Element {
    const width = rate === null ? 0 : Math.max(0, Math.min(100, rate * 100));

    return (
        <div className="min-w-0 rounded-control border border-(--app-border) p-3">
            <dt className="text-xs leading-snug text-(--app-muted-foreground)">{label}</dt>
            <dd className={cx('mt-1 text-2xl font-semibold tabular-nums', rate === null ? 'text-(--app-muted-foreground)' : 'text-(--app-foreground)')}>{text}</dd>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-(--app-muted)" aria-hidden="true">
                {rate !== null && <div className="h-full rounded-full bg-primary" style={{ width: `${width}%` }} />}
            </div>
        </div>
    );
}

/** A count with its label; a link to the register filtered to exactly what it counts when there is one. */
function Count({ label, value, href, tone, swatch }: { label: string; value: number | string; href?: string; tone?: Tone; swatch?: Tone }): JSX.Element {
    const body = (
        <>
            <span className={cx('text-lg font-semibold tabular-nums', tone ? TEXT[tone] : 'text-(--app-foreground)')}>{value}</span>
            <span className="flex min-w-0 items-center gap-1.5 text-xs leading-snug text-(--app-muted-foreground) [overflow-wrap:anywhere]">
                {swatch && <span className={cx('size-2 shrink-0 rounded-full', FILL[swatch])} aria-hidden="true" />}
                {label}
            </span>
        </>
    );
    const cls = 'flex min-w-0 flex-col rounded-control px-2.5 py-1.5';

    return href ? <Link href={href} className={cx(cls, 'hover:bg-(--app-muted)', focusCls)}>{body}</Link> : <div className={cls}>{body}</div>;
}

/**
 * The compliance figures for the filtered set, as three groups that each add
 * up on their own: the rates; the reports that are due now (one bar split
 * into on time, late and missing); and where each report stands. The page
 * says in words that categories of different groups overlap and must not be
 * summed together. Each count opens the register filtered to exactly the
 * obligations it counts.
 */
export default function ComplianceSummaryPanel({ summary, filters = {}, historical = false }: Props): JSX.Element {
    const { rt, percent, moment } = useReporting();
    const c = summary.counts;
    const to = (extra: Record<string, string>): string | undefined => (historical ? undefined : route('reporting.register', { ...filters, ...extra }));
    const due = c.submitted_on_time + c.submitted_late + c.overdue_unsubmitted;
    const segments: Array<{ tone: Tone; value: number }> = [
        { tone: 'success', value: c.submitted_on_time },
        { tone: 'warning', value: c.submitted_late },
        { tone: 'danger', value: c.overdue_unsubmitted },
    ];

    return (
        <section className={cx(panelCls, 'p-4 sm:p-5')} aria-label={rt('summary.title')}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className={sectionTitleCls}>{rt('summary.title')}</h2>
                <p className={cx(mutedCls, 'text-xs')}>{rt('summary.asOf', { time: moment(summary.as_of) })}</p>
            </div>

            <div className="mt-4 grid gap-6 xl:grid-cols-2">
                <div>
                    <h3 className={groupTitleCls}>{rt('summary.groupRates')}</h3>
                    <dl className="mt-2 grid gap-3 sm:grid-cols-3">
                        <Rate label={rt('summary.submissionRate')} rate={summary.rates.submission_rate} text={percent(summary.rates.submission_rate)} />
                        <Rate label={rt('summary.onTimeRate')} rate={summary.rates.on_time_rate} text={percent(summary.rates.on_time_rate)} />
                        <Rate label={rt('summary.approvalRate')} rate={summary.rates.approval_rate} text={percent(summary.rates.approval_rate)} />
                    </dl>
                    <p className={cx(mutedCls, 'mt-2 text-xs')}>{rt('summary.ratesHelp', { due: c.due_required, submitted: c.ever_submitted })}</p>
                </div>

                <div>
                    <h3 className={groupTitleCls}>{rt('summary.groupDue', { count: c.due_required })}</h3>
                    <div className="mt-2 flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-(--app-muted)" aria-hidden="true">
                        {due > 0 && segments.filter((segment) => segment.value > 0).map((segment) => (
                            <div key={segment.tone} className={cx('h-full', FILL[segment.tone])} style={{ width: `${(segment.value / due) * 100}%` }} />
                        ))}
                    </div>
                    <div className="mt-2 grid grid-cols-3 gap-1">
                        <Count label={rt('timeliness.on_time')} value={c.submitted_on_time} href={to({ timeliness: 'on_time' })} tone={c.submitted_on_time > 0 ? 'success' : undefined} swatch="success" />
                        <Count label={rt('timeliness.late')} value={c.submitted_late} href={to({ timeliness: 'late' })} tone={c.submitted_late > 0 ? 'warning' : undefined} swatch="warning" />
                        <Count label={rt('timeliness.overdue')} value={c.overdue_unsubmitted} href={to({ timeliness: 'overdue' })} tone={c.overdue_unsubmitted > 0 ? 'danger' : undefined} swatch="danger" />
                    </div>
                    <div className="mt-1 grid grid-cols-3 gap-1 border-t border-(--app-border) pt-1">
                        <Count label={rt('summary.expected')} value={c.expected} href={to({})} />
                        <Count label={rt('summary.notYetDue')} value={c.not_yet_due} href={to({ timeliness: 'not_due' })} />
                        <Count label={rt('summary.exemptedCancelled')} value={c.exempted + c.cancelled} />
                    </div>
                </div>
            </div>

            <div className="mt-5 border-t border-(--app-border) pt-4">
                <h3 className={groupTitleCls}>{rt(historical ? 'summary.groupWorkflowCurrent' : 'summary.groupWorkflow')}</h3>
                <div className="mt-2 grid grid-cols-2 gap-1 sm:grid-cols-4 xl:grid-cols-8">
                    <Count label={rt('workflow.not_started')} value={c.not_started} href={to({ workflow: 'not_started' })} />
                    <Count label={rt('workflow.draft')} value={c.draft} href={to({ workflow: 'draft' })} />
                    <Count label={rt('summary.awaitingReview')} value={c.submitted + c.under_review} />
                    <Count label={rt('workflow.returned')} value={c.returned} href={to({ workflow: 'returned' })} tone={c.returned > 0 ? 'warning' : undefined} />
                    <Count label={rt('workflow.approved')} value={c.approved} href={to({ workflow: 'approved' })} tone={c.approved > 0 ? 'success' : undefined} />
                    <Count label={rt('workflow.rejected')} value={c.rejected} href={to({ workflow: 'rejected' })} tone={c.rejected > 0 ? 'danger' : undefined} />
                    <Count label={rt('summary.overdueReviews')} value={c.overdue_reviews} tone={c.overdue_reviews > 0 ? 'danger' : undefined} />
                    <Count label={rt('summary.followUps')} value={`${summary.follow_ups.open}${summary.follow_ups.overdue > 0 ? ` (${summary.follow_ups.overdue})` : ''}`} href={historical ? undefined : route('reporting.follow-ups.index')} tone={summary.follow_ups.overdue > 0 ? 'warning' : undefined} />
                </div>
            </div>

            <p className={cx(mutedCls, 'mt-4 border-t border-(--app-border) pt-3 text-xs')}>
                {rt('summary.overlapNote')}{c.early_submissions > 0 ? ` ${rt('summary.earlyNote', { count: c.early_submissions })}` : ''}
            </p>
        </section>
    );
}
