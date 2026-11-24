import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import { ListPagination, cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { TimelinessBadge, When, WorkflowBadge } from '@/modules/reporting/components/Badges';
import ComplianceSummaryPanel from '@/modules/reporting/components/ComplianceSummaryPanel';
import { Notice } from '@/modules/reporting/components/Notices';
import { badge, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, timelinessTone, useReporting } from '@/modules/reporting/lib/helpers';
import type { ComplianceSummary, FilterOptions, Labelled, ObligationRow, OrganizationRef, Paginated, UnitRef } from '@/modules/reporting/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

interface MatrixCell {
    id: string;
    timeliness: string | null;
    workflow: string;
    applicability: string;
}

interface Props {
    filters: Record<string, string>;
    view: 'list' | 'matrix';
    options: FilterOptions;
    rows: Paginated<ObligationRow> | null;
    matrix: { cycles: ({ id: string; due_at: string } & Labelled)[]; institutions: Paginated<OrganizationRef & { unit?: UnitRef | null; cells: Record<string, MatrixCell> }> } | null;
    summary: ComplianceSummary | null;
    can: { export: boolean };
    archive: boolean;
}

const WORKFLOWS = ['not_started', 'draft', 'submitted', 'under_review', 'returned', 'approved', 'rejected'];
const TIMELINESS = ['on_time', 'late', 'overdue', 'early', 'not_due'];

/**
 * The compliance register: every expected report, whether or not anything
 * was ever done about it. The figures above the table, the rows and the
 * exported file all come from the same query with the same filters.
 */
export default function Register({ filters, view, options, rows, matrix, summary, can, archive }: Props): JSX.Element {
    const { rt, name, label, moment, subject } = useReporting();
    const [draft, setDraft] = useState<Record<string, string>>(filters);
    const historical = 'as_of' in filters;
    const base = archive ? 'reporting.archive' : 'reporting.register';
    const set = (key: string, value: string) => setDraft((d) => ({ ...d, [key]: value }));
    const go = (next: Record<string, string>, extra: Record<string, string> = {}) =>
        router.get(route(base), { ...Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '')), ...extra }, { preserveScroll: true });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        go(draft, view === 'matrix' ? { view: 'matrix' } : {});
    };
    const cycles = options.cycles.filter((c) => !draft.report_type_id || c.report_type_id === draft.report_type_id);

    return (
        <AuthenticatedLayout header={<PageHeader title={rt(archive ? 'archive.title' : 'register.title')} description={rt(archive ? 'archive.description' : 'register.description')}
            actions={can.export && view === 'list' ? (
                <span className="flex flex-wrap gap-2">
                    <a href={route('reporting.register.export', { ...filters, format: 'csv' })} className={secondaryBtn}>{rt('register.exportCsv')}</a>
                    <a href={route('reporting.register.export', { ...filters, format: 'xlsx' })} className={secondaryBtn}>{rt('register.exportExcel')}</a>
                </span>
            ) : undefined} />}>
            <Head title={rt(archive ? 'archive.title' : 'register.title')} />
            <div className="space-y-4 pb-4">
                <form onSubmit={submit} className={cx(panelCls, 'space-y-3 p-4')} aria-label={rt('filters.title')}>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label htmlFor="rg-q" className={labelCls}>{rt('filters.search')}</label>
                            <input id="rg-q" type="search" className={inputCls} value={draft.search ?? ''} placeholder={rt('filters.searchPlaceholder')} onChange={(e) => set('search', e.target.value)} />
                        </div>
                        <div>
                            <label htmlFor="rg-type" className={labelCls}>{rt('filters.reportType')}</label>
                            <select id="rg-type" className={inputCls} value={draft.report_type_id ?? ''} onChange={(e) => setDraft((d) => ({ ...d, report_type_id: e.target.value, cycle_id: '' }))}>
                                <option value="">{rt('filters.allTypes')}</option>
                                {options.report_types.map((type) => <option key={type.id} value={type.id}>{name(type)}</option>)}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="rg-cycle" className={labelCls}>{rt('filters.period')}</label>
                            <select id="rg-cycle" className={inputCls} value={draft.cycle_id ?? ''} onChange={(e) => set('cycle_id', e.target.value)}>
                                <option value="">{rt('filters.allPeriods')}</option>
                                {cycles.map((cycle) => <option key={cycle.id} value={cycle.id}>{label(cycle)}</option>)}
                            </select>
                        </div>
                        {archive ? (
                            <div>
                                <label htmlFor="rg-wf" className={labelCls}>{rt('filters.workflow')}</label>
                                <select id="rg-wf" className={inputCls} value={draft.workflow ?? 'approved'} onChange={(e) => set('workflow', e.target.value)}>
                                    <option value="approved">{rt('workflow.approved')}</option>
                                    <option value="rejected">{rt('workflow.rejected')}</option>
                                </select>
                            </div>
                        ) : (
                            <div>
                                <label htmlFor="rg-wf" className={labelCls}>{rt('filters.workflow')}</label>
                                <select id="rg-wf" className={inputCls} value={draft.workflow ?? ''} onChange={(e) => set('workflow', e.target.value)}>
                                    <option value="">{rt('filters.any')}</option>
                                    {WORKFLOWS.map((value) => <option key={value} value={value}>{rt(`workflow.${value}`)}</option>)}
                                </select>
                            </div>
                        )}
                        {!archive && (
                            <>
                                <div>
                                    <label htmlFor="rg-time" className={labelCls}>{rt('filters.timeliness')}</label>
                                    <select id="rg-time" className={inputCls} value={draft.timeliness ?? ''} disabled={Boolean(draft.as_of)} onChange={(e) => set('timeliness', e.target.value)}>
                                        <option value="">{rt('filters.any')}</option>
                                        {TIMELINESS.map((value) => <option key={value} value={value}>{rt(`timeliness.${value}`)}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label htmlFor="rg-appl" className={labelCls}>{rt('filters.applicability')}</label>
                                    <select id="rg-appl" className={inputCls} value={draft.applicability ?? ''} disabled={Boolean(draft.as_of)} onChange={(e) => set('applicability', e.target.value)}>
                                        <option value="">{rt('filters.any')}</option>
                                        {['required', 'exempted', 'cancelled'].map((value) => <option key={value} value={value}>{rt(`applicability.${value}`)}</option>)}
                                    </select>
                                </div>
                            </>
                        )}
                        <div>
                            <label htmlFor="rg-from" className={labelCls}>{rt('filters.dueFrom')}</label>
                            <input id="rg-from" type="date" className={inputCls} value={draft.due_from ?? ''} onChange={(e) => set('due_from', e.target.value)} />
                        </div>
                        <div>
                            <label htmlFor="rg-to" className={labelCls}>{rt('filters.dueTo')}</label>
                            <input id="rg-to" type="date" className={inputCls} value={draft.due_to ?? ''} onChange={(e) => set('due_to', e.target.value)} />
                        </div>
                        {!archive && (
                            <div>
                                <label htmlFor="rg-asof" className={labelCls}>{rt('filters.asOf')}</label>
                                <input id="rg-asof" type="date" className={inputCls} value={draft.as_of ?? ''} onChange={(e) => setDraft((d) => ({ ...d, as_of: e.target.value, timeliness: '', applicability: '' }))} />
                            </div>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap gap-2">
                            <button type="submit" className={primaryBtn}>{rt('filters.apply')}</button>
                            <button type="button" className={secondaryBtn} onClick={() => { setDraft({}); go({}); }}>{rt('filters.clear')}</button>
                        </div>
                        {!archive && (
                            <div className="flex gap-2" role="group" aria-label={rt('register.viewLabel')}>
                                <button type="button" className={view === 'list' ? primaryBtn : secondaryBtn} aria-pressed={view === 'list'} onClick={() => go(draft)}>{rt('register.viewList')}</button>
                                <button type="button" className={view === 'matrix' ? primaryBtn : secondaryBtn} aria-pressed={view === 'matrix'} disabled={!draft.report_type_id}
                                    title={draft.report_type_id ? undefined : rt('register.matrixNeedsType')} onClick={() => go(draft, { view: 'matrix' })}>{rt('register.viewMatrix')}</button>
                            </div>
                        )}
                    </div>
                </form>

                {historical && <Notice>{rt('register.historicalNote', { date: filters.as_of })}</Notice>}
                {summary && summary.counts.expected > 0 && <ComplianceSummaryPanel summary={summary} filters={filters} historical={historical} />}

                {view === 'list' && rows && (rows.data.length === 0 ? <EmptyState title={rt('register.emptyTitle')} description={rt('register.emptyDescription')} /> : (
                    <div className={cx(panelCls, 'overflow-x-auto')}>
                        {/* Seven columns carry the register's twelve facts: related ones share a cell, so a row stays two or three lines high. */}
                        <table className="w-full min-w-[56rem] text-sm">
                            <caption className="sr-only">{rt('register.title')}</caption>
                            <thead>
                                <tr className="border-b border-(--app-border) text-xs text-(--app-muted-foreground)">
                                    <th scope="col" className="min-w-40 px-3 py-2.5 text-start font-medium">{rt('register.col.institution')}<span className="block font-normal">{rt('register.col.officer')}</span></th>
                                    <th scope="col" className="px-3 py-2.5 text-start font-medium">{rt('register.col.type')}<span className="block font-normal">{rt('register.col.period')}</span></th>
                                    <th scope="col" className="px-3 py-2.5 text-start font-medium">{rt('register.col.effectiveDeadline')}</th>
                                    <th scope="col" className="px-3 py-2.5 text-start font-medium">{rt('register.col.firstSubmission')}</th>
                                    <th scope="col" className="px-3 py-2.5 text-start font-medium">{rt('register.col.timeliness')}<span className="block font-normal">{rt('register.col.workflow')}</span></th>
                                    <th scope="col" className="px-3 py-2.5 text-end font-medium">{rt('register.col.daysOverdue')}</th>
                                    <th scope="col" className="px-3 py-2.5 text-start font-medium">{rt('register.col.reviewer')}<span className="block font-normal">{rt('register.col.reviewDeadline')}</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-(--app-border)">
                                {rows.data.map((row) => (
                                    <tr key={row.id} className="align-top hover:bg-(--app-muted)">
                                        <th scope="row" className="px-3 py-3 text-start font-normal">
                                            <Link href={route('reporting.obligations.show', row.id)} className="font-medium text-primary hover:underline">{subject(row)}</Link>
                                            <span className={cx(mutedCls, 'block text-xs')}>
                                                {row.organization.code}{row.is_personal ? ` · ${rt('register.personal')}` : ''} · {row.accountable_name ?? row.preparer_name ?? rt('register.noOfficer')}
                                            </span>
                                        </th>
                                        <td className="px-3 py-3">
                                            {name(row.report_type)}
                                            <span className={cx(mutedCls, 'block text-xs')}>{label(row.period)}</span>
                                        </td>
                                        <td className="px-3 py-3">
                                            <When iso={row.effective_due_at} strong />
                                            {row.deadline_extended && (
                                                <span className="mt-1 block">
                                                    <span className={badge('info')}>{rt('register.extended')}</span>
                                                    <span className={cx(mutedCls, 'mt-0.5 block text-xs')}>{rt('register.col.originalDeadline')}: {moment(row.original_due_at)}</span>
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-3">{row.first_submitted_at ? <When iso={row.first_submitted_at} /> : <span className={mutedCls}>{rt('register.notSubmitted')}</span>}</td>
                                        <td className="px-3 py-3">
                                            <span className="flex flex-col items-start gap-1 whitespace-nowrap">
                                                <TimelinessBadge value={row.timeliness} applicability={row.applicability} />
                                                {row.applicability === 'required' && <WorkflowBadge status={row.workflow} />}
                                            </span>
                                            {row.deadline_extended && row.timeliness_original !== row.timeliness && row.timeliness_original && (
                                                <span className={cx(mutedCls, 'mt-1 block text-xs')}>{rt('register.byOriginal')}: {rt(`timeliness.${row.timeliness_original}`)}</span>
                                            )}
                                        </td>
                                        <td className={cx('px-3 py-3 text-end tabular-nums', row.days_overdue > 0 && 'font-semibold text-red-700 dark:text-red-400')}>{row.days_overdue > 0 ? row.days_overdue : '—'}</td>
                                        <td className="px-3 py-3">
                                            {row.review_due_at || row.reviewer_name ? (
                                                <>
                                                    {row.reviewer_name && <span className="block">{row.reviewer_name}</span>}
                                                    {row.review_due_at && <When iso={row.review_due_at} />}
                                                    {row.review_overdue && <span className={cx(badge('danger'), 'mt-1 block w-fit')}>{rt('register.reviewOverdue')}</span>}
                                                </>
                                            ) : <span className={mutedCls}>—</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <ListPagination meta={rows} />
                    </div>
                ))}

                {view === 'matrix' && matrix && (matrix.institutions.data.length === 0 ? <EmptyState title={rt('register.emptyTitle')} /> : (
                    <div className={cx(panelCls, 'overflow-x-auto')}>
                        <table className="w-full text-sm">
                            <caption className="sr-only">{rt('register.viewMatrix')}</caption>
                            <thead>
                                <tr className="border-b border-(--app-border) text-xs text-(--app-muted-foreground)">
                                    <th scope="col" className="sticky start-0 bg-(--app-surface) px-3 py-2 text-start font-medium">{rt('register.col.institution')}</th>
                                    {matrix.cycles.map((cycle) => <th key={cycle.id} scope="col" className="px-3 py-2 text-start font-medium">{label(cycle)}</th>)}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-(--app-border)">
                                {matrix.institutions.data.map((institution) => (
                                    <tr key={institution.id}>
                                        <th scope="row" className="sticky start-0 bg-(--app-surface) px-3 py-2 text-start font-normal">
                                            <span className="font-medium">{name(institution)}{institution.unit ? ` — ${name(institution.unit)}` : ''}</span>
                                            <span className={cx(mutedCls, 'block text-xs')}>{institution.code}</span>
                                        </th>
                                        {matrix.cycles.map((cycle) => {
                                            const cell = institution.cells[cycle.id];

                                            return (
                                                <td key={cycle.id} className="px-3 py-2">
                                                    {cell ? (
                                                        <Link href={route('reporting.obligations.show', cell.id)} className={badge(cell.applicability === 'required' ? timelinessTone(cell.timeliness) : 'neutral')}>
                                                            {cell.applicability === 'required' && cell.timeliness ? rt(`timeliness.${cell.timeliness}`) : rt(`applicability.${cell.applicability}`)}
                                                        </Link>
                                                    ) : <span className={mutedCls} title={rt('register.notExpected')}>—</span>}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <ListPagination meta={matrix.institutions} />
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
