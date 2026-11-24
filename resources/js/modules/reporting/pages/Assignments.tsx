import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { AppTabNav, ListPagination, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Deadline, TimelinessBadge, WorkflowBadge } from '@/modules/reporting/components/Badges';
import { mutedCls, panelCls, primaryBtn, secondaryBtn, useReporting } from '@/modules/reporting/lib/helpers';
import type { ObligationRow, Paginated } from '@/modules/reporting/types';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

interface Props {
    view: 'open' | 'waiting' | 'done';
    rows: Paginated<ObligationRow>;
    can: { prepare: boolean; submit: boolean };
}

/** The reports I, or my institution, owe: to prepare, awaiting review, and decided. */
export default function Assignments({ view, rows, can }: Props): JSX.Element {
    const { rt, name, label, moment, subject } = useReporting();
    const tabs = (['open', 'waiting', 'done'] as const).map((key) => ({ key, label: rt(`assignments.tab.${key}`), href: route('reporting.assignments'), data: { view: key } }));

    return (
        <AuthenticatedLayout header={<PageHeader title={rt('assignments.title')} description={rt('assignments.description')} />}>
            <Head title={rt('assignments.title')} />
            <div className="space-y-4 pb-4">
                <AppTabNav items={tabs} current={view} label={rt('assignments.title')} />
                {rows.data.length === 0 ? <EmptyState title={rt(`assignments.empty${view === 'open' ? 'Open' : view === 'waiting' ? 'Waiting' : 'Done'}`)} description={view === 'open' ? rt('assignments.emptyOpenHelp') : undefined} /> : (
                    <ul className="space-y-3">
                        {rows.data.map((row) => (
                            <li key={row.id} className={cx(panelCls, 'p-4')}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="text-base font-semibold">
                                            <Link href={route('reporting.obligations.show', row.id)} className="hover:underline">{name(row.report_type)}</Link>
                                        </h2>
                                        <p className="text-sm">{label(row.period)} · {subject(row)}</p>
                                        <p className={cx(mutedCls, 'text-xs')}>{rt('assignments.receivedBy')}: {name(row.receiving)}{row.version > 1 ? ` · ${rt('common.version', { n: row.version })}` : ''}</p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <WorkflowBadge status={row.workflow} />
                                        {row.first_submitted_at && <TimelinessBadge value={row.timeliness} />}
                                    </div>
                                </div>
                                <div className="mt-3 flex flex-wrap items-end justify-between gap-3 border-t border-(--app-border) pt-3">
                                    <div>
                                        <p className="text-xs font-medium text-(--app-muted-foreground)">{rt(row.workflow === 'returned' ? 'assignments.revisionDue' : 'common.deadline')}</p>
                                        {row.workflow === 'returned' && row.revision_due_at ? <p className="text-sm">{moment(row.revision_due_at)}</p> : <Deadline row={row} />}
                                        {row.first_submitted_at && <p className={cx(mutedCls, 'text-xs')}>{rt('common.firstSubmitted')}: {moment(row.first_submitted_at)}</p>}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Link href={route('reporting.obligations.show', row.id)} className={secondaryBtn}>{rt('common.open')}</Link>
                                        {view === 'open' && can.prepare && (
                                            <Link href={route('reporting.prepare.edit', row.id)} className={primaryBtn}>
                                                {rt(row.workflow === 'not_started' ? 'assignments.start' : row.workflow === 'returned' ? 'assignments.revise' : 'assignments.continue')}
                                            </Link>
                                        )}
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                <ListPagination meta={rows} />
            </div>
        </AuthenticatedLayout>
    );
}
