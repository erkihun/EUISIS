import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { AppTabNav, ListPagination, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TimelinessBadge, When, WorkflowBadge } from '@/modules/reporting/components/Badges';
import { ActionErrors } from '@/modules/reporting/components/Notices';
import { badge, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { ExtensionRow, ObligationRow, Paginated, ReviewStepRow } from '@/modules/reporting/types';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

interface Props {
    mine: boolean;
    rows: Paginated<ObligationRow & { step: ReviewStepRow | null; waiting_days: number }>;
    extensions: (ExtensionRow & { obligation: ObligationRow })[];
}

/**
 * Reports awaiting a decision at the institutions I review for, the most
 * urgent first, and the extension requests that wait for me. The submitter's
 * timeliness is shown as a fact; how long the review takes never changes it.
 */
export default function ReviewInbox({ mine, rows, extensions }: Props): JSX.Element {
    const { rt, name, label, moment, am, subject } = useReporting();
    const tabs = [
        { key: 'all', label: rt('review.tabAll'), href: route('reporting.review.index') },
        { key: 'mine', label: rt('review.tabMine'), href: route('reporting.review.index'), data: { mine: 1 } },
    ];

    return (
        <AuthenticatedLayout header={<PageHeader title={rt('review.inboxTitle')} description={rt('review.inboxDescription')} />}>
            <Head title={rt('review.inboxTitle')} />
            <ActionErrors />
            <div className="space-y-5 pb-4">
                {extensions.length > 0 && (
                    <section className={cx(panelCls, 'p-4')} aria-labelledby="rv-ext">
                        <h2 id="rv-ext" className={sectionTitleCls}>{rt('review.extensionsTitle')} <span className={badge('warning')}>{extensions.length}</span></h2>
                        <ul className="mt-2 divide-y divide-(--app-border)">
                            {extensions.map((extension) => (
                                <li key={extension.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                    <div className="min-w-0 text-sm">
                                        <p className="font-medium">{name(extension.obligation.organization)} · {name(extension.obligation.report_type)} · {label(extension.obligation.period)}</p>
                                        <p className={cx(mutedCls, 'text-xs')}>{rt('show.extensionTo', { date: moment(extension.requested_due_at) })} · {extension.requested_by_name}</p>
                                        <p className="line-clamp-2">{extension.reason}</p>
                                    </div>
                                    <Link href={route('reporting.obligations.show', extension.obligation_id)} className={secondaryBtn}>{rt('review.decide')}</Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <AppTabNav items={tabs} current={mine ? 'mine' : 'all'} label={rt('review.inboxTitle')} />
                {rows.data.length === 0 ? <EmptyState title={rt('review.emptyTitle')} description={rt('review.emptyDescription')} /> : (
                    <div className={cx(panelCls, 'overflow-x-auto')}>
                        <table className="w-full min-w-[60rem] text-sm">
                            <caption className="sr-only">{rt('review.inboxTitle')}</caption>
                            <thead>
                                <tr className="border-b border-(--app-border) text-xs text-(--app-muted-foreground)">
                                    {['institution', 'report', 'submitted', 'timeliness', 'step', 'reviewDeadline', 'waiting'].map((column) => <th key={column} scope="col" className="px-3 py-2 text-start font-medium">{rt(`review.col.${column}`)}</th>)}
                                    <th scope="col" className="px-3 py-2"><span className="sr-only">{rt('common.open')}</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-(--app-border)">
                                {rows.data.map((row) => (
                                    <tr key={row.id} className="align-top hover:bg-(--app-muted)">
                                        <th scope="row" className="px-3 py-2 text-start font-normal">
                                            <span className="font-medium">{subject(row)}</span>
                                            <span className={cx(mutedCls, 'block text-xs')}>{row.organization.code}</span>
                                        </th>
                                        <td className="px-3 py-2">{name(row.report_type)}<span className={cx(mutedCls, 'block text-xs')}>{label(row.period)} · {rt('common.version', { n: row.version })}</span></td>
                                        <td className="px-3 py-2"><When iso={row.latest_submitted_at ?? row.first_submitted_at} /></td>
                                        <td className="px-3 py-2"><TimelinessBadge value={row.timeliness} /></td>
                                        <td className="px-3 py-2">
                                            {row.step ? `${row.step.step_no}. ${am ? row.step.name_am : row.step.name_en}` : '—'}
                                            <span className="mt-1 block"><WorkflowBadge status={row.workflow} /></span>
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <When iso={row.review_due_at} strong />
                                            {row.review_overdue && <span className={cx(badge('danger'), 'ms-1')}>{rt('register.reviewOverdue')}</span>}
                                        </td>
                                        <td className="px-3 py-2 tabular-nums">{rt('review.waitingDays', { days: row.waiting_days })}</td>
                                        <td className="px-3 py-2 text-end">{row.latest_submission_id && <Link href={route('reporting.review.show', row.latest_submission_id)} className={primaryBtn}>{rt('review.open')}</Link>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <ListPagination meta={rows} />
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
