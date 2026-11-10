import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import SupervisorSummary from '@/Components/ReportingLines/SupervisorSummary';
import type { ActingAppointmentRow, DelegationRow, EmployeePage, EmployeeRow, SupervisorResolution } from '@/Components/ReportingLines/types';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { FilterField, FilterPanel, Input, ListPagination, badgeClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName, TableTools } from '@/Components/ui';
import { useState } from 'react';

export default function ReportingLineEmployee({ employee, as_of, supervisor, chain, reports, indirect, acting_appointments, delegations, can }: {
    employee: EmployeeRow;
    as_of: string;
    supervisor: SupervisorResolution;
    chain: SupervisorResolution[];
    reports: EmployeePage | null;
    indirect: { total: number; truncated: boolean } | null;
    acting_appointments: ActingAppointmentRow[];
    delegations: DelegationRow[];
    can: { view_team: boolean; manage: boolean; view_employee: boolean };
}) {
    const { t, locale } = useLocale();
    const [asOf, setAsOf] = useState(as_of);
    const name = (en: string | null, am: string | null): string => (locale === 'am' ? (am ?? en ?? '') : (en ?? ''));
    const employeeName = name(employee.full_name, employee.name_am);

    function submit() {
        router.get(route('reporting-lines.employees.show', employee.id), { as_of: asOf }, { preserveState: true, preserveScroll: true });
    }

    const lineHref = (id: string | null): string | null => (id ? route('reporting-lines.employees.show', id) : null);

    return (
        <AuthenticatedLayout header={<PageHeader backHref={route('reporting-lines.overview')} title={employeeName} description={`${employee.employee_number ?? ''} · ${localizedName(employee.position_title_en ?? '', employee.position_title_am, locale)}${employee.unit_name_en ? ` · ${localizedName(employee.unit_name_en, employee.unit_name_am, locale)}` : ''}`} actions={can.view_employee ? <Link href={route('employees.show', employee.id)} className="text-sm text-primary hover:underline">{t('reportingLines.viewEmployee')}</Link> : undefined} />}>
            <Head title={`${t('reportingLines.employeeLine')} · ${employeeName}`} />
            <div className="space-y-6">
                <FilterPanel onSubmit={submit} hasActiveFilters={false}>
                    <FilterField label={t('reportingLines.asOf')}>
                        <Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} />
                    </FilterField>
                </FilterPanel>

                <section className="space-y-3">
                    <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.supervisor')}</h3>
                    <SupervisorSummary resolution={supervisor} employeeHref={lineHref(supervisor.supervisor_employee_id)} />
                </section>

                <section className="space-y-3">
                    <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.chain')}</h3>
                    <ol className="space-y-2">
                        {chain.map((step, i) => (
                            <li key={`${step.employee_id}-${i}`} className="flex gap-3">
                                <span className="mt-3 w-6 shrink-0 text-right text-xs tabular-nums text-(--app-muted-foreground)">{i + 1}</span>
                                <div className="min-w-0 flex-1"><SupervisorSummary resolution={step} employeeHref={lineHref(step.supervisor_employee_id)} compact /></div>
                            </li>
                        ))}
                    </ol>
                </section>

                {reports && (
                    <section className={tableContainerClassName}>
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-(--app-border) px-4 py-3">
                            <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.directReports')} ({reports.meta.total})</h3>
                            {indirect && (
                                <span className="text-xs text-(--app-muted-foreground)">
                                    {t('reportingLines.indirectReports')}: {indirect.total}{indirect.truncated ? ` (${t('reportingLines.truncated')})` : ''}
                                </span>
                            )}
                        </div>
                        {reports.data.length === 0 ? (
                            <EmptyState title={t('reportingLines.noReports')} />
                        ) : (
                            <div className="overflow-x-auto">
                                <TableTools id="reporting-lines-employee"><table className={cx(tableClassName, 'min-w-full text-left')}>
                                    <thead className={tableHeadClassName}>
                                        <tr>
                                            {[t('reportingLines.employee'), t('reportingLines.position'), t('reportingLines.unit'), t('common.status')].map((h, i) => (
                                                <th key={i} className={cx(tableHeaderCellClassName, 'text-left')}>{h}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className={tableBodyClassName}>
                                        {reports.data.map((r) => (
                                            <tr key={r.id} className={tableRowClassName}>
                                                <td className={cx(tableCellClassName, 'font-medium')}>
                                                    <Link href={route('reporting-lines.employees.show', r.id)} className="text-primary hover:underline">{name(r.full_name, r.name_am)}</Link>
                                                    <span className="ml-2 text-xs font-normal text-(--app-muted-foreground)">{r.employee_number}</span>
                                                </td>
                                                <td className={tableCellClassName}>
                                                    {localizedName(r.position_title_en ?? '', r.position_title_am, locale)}
                                                    {r.is_unit_head && <span className={cx(badgeClassName('info'), 'ml-2')}>{t('positions.unitHead')}</span>}
                                                </td>
                                                <td className={tableCellClassName}>{r.unit_name_en ? localizedName(r.unit_name_en, r.unit_name_am, locale) : '—'}</td>
                                                <td className={tableCellClassName}>{r.status}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table></TableTools>
                            </div>
                        )}
                        <ListPagination meta={reports.meta} />
                    </section>
                )}

                {(acting_appointments.length > 0 || delegations.length > 0) && (
                    <section className="grid gap-4 lg:grid-cols-2">
                        <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                            <h3 className="mb-3 text-sm font-semibold text-(--app-foreground)">{t('reportingLines.actingAppointments')}</h3>
                            {acting_appointments.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{t('reportingLines.noActingAppointments')}</p> : (
                                <ul className="space-y-2 text-sm">
                                    {acting_appointments.map((a) => (
                                        <li key={a.id} className="flex flex-wrap items-center justify-between gap-2">
                                            <span>{localizedName(a.position_title_en ?? '', a.position_title_am, locale)}{a.unit_name_en ? ` · ${localizedName(a.unit_name_en, a.unit_name_am, locale)}` : ''}</span>
                                            <span className="text-xs text-(--app-muted-foreground)">
                                                {a.starts_on && <LocalizedDateDisplay value={a.starts_on} />} – {a.ends_on ? <LocalizedDateDisplay value={a.ends_on} /> : t('reportingLines.openEnded')}
                                                <span className={cx(badgeClassName(a.in_force ? 'success' : a.status === 'revoked' ? 'danger' : 'neutral'), 'ml-2')}>{a.in_force ? t('reportingLines.inForce') : a.status_label}</span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-5">
                            <h3 className="mb-3 text-sm font-semibold text-(--app-foreground)">{t('reportingLines.delegations')}</h3>
                            {delegations.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{t('reportingLines.noDelegations')}</p> : (
                                <ul className="space-y-2 text-sm">
                                    {delegations.map((d) => (
                                        <li key={d.id} className="flex flex-wrap items-center justify-between gap-2">
                                            <span>
                                                {name(d.delegator_name, d.delegator_name_am)} → {name(d.delegate_name, d.delegate_name_am)}
                                                <span className="ml-2 text-xs text-(--app-muted-foreground)">{d.action_labels.join(', ')}</span>
                                            </span>
                                            <span className={badgeClassName(d.in_force ? 'success' : d.status === 'revoked' ? 'danger' : 'neutral')}>{d.in_force ? t('reportingLines.inForce') : d.status_label}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
