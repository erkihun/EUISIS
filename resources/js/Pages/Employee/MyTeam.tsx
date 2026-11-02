import PortalPage from '@/Components/employees/portal/PortalPage';
import EmptyState from '@/Components/EmptyState';
import DelegationForm from '@/Components/ReportingLines/DelegationForm';
import DelegationTable from '@/Components/ReportingLines/DelegationTable';
import type { ActionOption, DelegationRow, EmployeePage, EmployeeRow } from '@/Components/ReportingLines/types';
import { Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { Button, ListPagination, badgeClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import { useState } from 'react';

/**
 * My Portal: the people the organization structure places under the
 * signed-in employee (ADR 0025), direct first, indirect on request, and the
 * delegations they have given and received.
 */
export default function MyTeam({ employee, direct, indirect, indirect_total, indirect_truncated, delegations, received_delegations, actions, can }: {
    employee: EmployeeRow;
    direct: EmployeePage;
    indirect: EmployeePage | null;
    indirect_total: number;
    indirect_truncated: boolean;
    delegations: DelegationRow[];
    received_delegations: DelegationRow[];
    actions: ActionOption[];
    can: { delegate: boolean };
}) {
    const { t, locale } = useLocale();
    const [showForm, setShowForm] = useState(false);
    const name = (en: string | null, am: string | null): string => (locale === 'am' ? (am ?? en ?? '') : (en ?? ''));

    const table = (page: EmployeePage, pageName: string) => (
        <>
            {page.data.length === 0 ? <EmptyState title={t('reportingLines.noReports')} /> : (
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[t('reportingLines.employee'), t('reportingLines.position'), t('reportingLines.unit')].map((h, i) => (
                                    <th key={i} className={cx(tableHeaderCellClassName, 'text-left')}>{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {page.data.map((r) => (
                                <tr key={r.id} className={tableRowClassName}>
                                    <td className={cx(tableCellClassName, 'font-medium')}>{name(r.full_name, r.name_am)}<span className="ml-2 text-xs font-normal text-(--app-muted-foreground)">{r.employee_number}</span></td>
                                    <td className={tableCellClassName}>
                                        {localizedName(r.position_title_en ?? '', r.position_title_am, locale)}
                                        {r.is_unit_head && <span className={cx(badgeClassName('info'), 'ml-2')}>{t('positions.unitHead')}</span>}
                                    </td>
                                    <td className={tableCellClassName}>{r.unit_name_en ? localizedName(r.unit_name_en, r.unit_name_am, locale) : '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <ListPagination meta={page.meta} onPageChange={(p) => router.get(route('employee.my-team'), { [pageName]: p, indirect: indirect ? 1 : undefined }, { preserveState: true, preserveScroll: true })} />
        </>
    );

    return (
        <PortalPage title={t('reportingLines.myTeam')} description={`${localizedName(employee.position_title_en ?? '', employee.position_title_am, locale)}${employee.unit_name_en ? ` · ${localizedName(employee.unit_name_en, employee.unit_name_am, locale)}` : ''}`} actions={<Link href={route('employee.reporting-line')} className="text-sm text-primary hover:underline">{t('reportingLines.myReportingLine')}</Link>}>
            <p className="text-sm text-(--app-muted-foreground)">{t('reportingLines.teamHint')}</p>

            <section className={tableContainerClassName}>
                <div className="border-b border-(--app-border) px-4 py-3"><h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.directReports')} ({direct.meta.total})</h3></div>
                {table(direct, 'page')}
            </section>

            <section className={tableContainerClassName}>
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-(--app-border) px-4 py-3">
                    <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.indirectReports')} ({indirect_total}{indirect_truncated ? '+' : ''})</h3>
                    {!indirect && indirect_total > 0 && (
                        <Link href={route('employee.my-team', { indirect: 1 })} className="text-sm text-primary hover:underline" preserveScroll>{t('reportingLines.showIndirect')}</Link>
                    )}
                </div>
                {indirect ? table(indirect, 'indirect_page') : <p className="px-4 py-3 text-sm text-(--app-muted-foreground)">{indirect_truncated ? t('reportingLines.truncated') : t('reportingLines.indirectHint')}</p>}
            </section>

            <section className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.myDelegations')}</h3>
                    {can.delegate && <Button type="button" size="sm" onClick={() => setShowForm((v) => !v)}>{t('reportingLines.newDelegation')}</Button>}
                </div>
                {showForm && can.delegate && (
                    <DelegationForm organizationId={employee.organization_id} actions={actions} storeRoute={route('employee.my-team.delegations.store')} onDone={() => setShowForm(false)} />
                )}
                <div className={tableContainerClassName}>
                    <DelegationTable delegations={delegations} revokeRoute={(id) => route('employee.my-team.delegations.revoke', id)} canRevoke={() => can.delegate} linkEmployees={false} />
                </div>
            </section>

            {received_delegations.length > 0 && (
                <section className="space-y-3">
                    <h3 className="text-sm font-semibold text-(--app-foreground)">{t('reportingLines.delegatedToMe')}</h3>
                    <div className={tableContainerClassName}>
                        <DelegationTable delegations={received_delegations} revokeRoute={(id) => route('employee.my-team.delegations.revoke', id)} canRevoke={() => false} linkEmployees={false} />
                    </div>
                </section>
            )}
        </PortalPage>
    );
}
