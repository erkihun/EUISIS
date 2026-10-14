import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Breadcrumb, FilterBar, fmt, useDateLabel, useNamer, viewQuery, type Counts, type FilterOptions, type Filters, type Name, type ScopeProps } from '@/Components/situationRoom/shared';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Card, EmptyState, Select, TBody, THead, Table, Td, Th, Tr, buttonClassName } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';

type EmployeeRow = { id: string; latest_at: string | null } & Name & Counts;
type Props = {
    scope: ScopeProps;
    filters: Filters;
    filterOptions: FilterOptions;
    employees: { rows: EmployeeRow[]; total: number; page: number; per_page: number; sort: 'latest' | 'total'; generated_at: string };
};

/**
 * Employee-targeted submissions, counted per employee where they were
 * recorded. No photo, no ranking title; the default order is the most recent
 * submission, not the largest count.
 */
export default function SituationRoomEmployees({ scope, filters, filterOptions, employees }: Props) {
    const { t } = useLocale();
    const name = useNamer();
    const { dateTime } = useDateLabel();
    const query = viewQuery(scope, filters);
    const pages = Math.max(1, Math.ceil(employees.total / employees.per_page));
    const go = (extra: Record<string, string | number>) => router.get(route('situation-room.employees'), { ...query, sort: employees.sort, page: employees.page, ...extra }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={t('situationRoom.employeesTitle')} description={t('situationRoom.employeesIntro')} backHref={scope.node ? route('situation-room.organization', query) : route('situation-room.index', query)} />}>
            <Head title={t('situationRoom.employeesTitle')} />

            <div className="space-y-5">
                <div>
                    <Breadcrumb scope={scope} filters={filters} />
                    <h2 className="mt-1 text-xl font-semibold">{scope.node ? name(scope.node) : t('situationRoom.cityWide')}</h2>
                </div>

                <FilterBar key={JSON.stringify(query)} scope={scope} filters={filters} options={filterOptions} routeName="situation-room.employees" showTypeFilters={false} />

                <Card className="p-0">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[color:var(--app-border)] p-4">
                        <p className="text-sm text-[color:var(--app-muted-foreground)]">{employees.total.toLocaleString()}</p>
                        <label className="flex items-center gap-2 text-sm">
                            <span>{t('situationRoom.sortLabel')}</span>
                            <Select value={employees.sort} onChange={(e) => go({ sort: e.target.value, page: 1 })} className="w-auto">
                                <option value="latest">{t('situationRoom.sortLatest')}</option>
                                <option value="total">{t('situationRoom.sortTotal')}</option>
                            </Select>
                        </label>
                    </div>
                    {employees.rows.length === 0 ? <EmptyState title={t('situationRoom.employeesEmpty')} /> : (
                        <div className="overflow-x-auto">
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{t('situationRoom.employeeColumn')}</Th>
                                        <Th align="right">{t('situationRoom.kpiFeedback')}</Th>
                                        <Th align="right">{t('situationRoom.kpiSuggestions')}</Th>
                                        <Th align="right">{t('situationRoom.kpiComplaints')}</Th>
                                        <Th align="right">{t('situationRoom.kpiUnresolved')}</Th>
                                        <Th>{t('situationRoom.latestColumn')}</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {employees.rows.map((row) => (
                                        <Tr key={row.id}>
                                            <Td><Link href={route('situation-room.employee', { employee: row.id, ...query })} className="font-medium text-[color:var(--color-primary)] underline-offset-4 hover:underline">{name(row)}</Link></Td>
                                            <Td align="right" numeric>{row.feedback.toLocaleString()}</Td>
                                            <Td align="right" numeric>{row.suggestion.toLocaleString()}</Td>
                                            <Td align="right" numeric>{row.complaint.toLocaleString()}</Td>
                                            <Td align="right" numeric>{row.unresolved.toLocaleString()}</Td>
                                            <Td>{dateTime(row.latest_at)}</Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                    )}
                    {pages > 1 && (
                        <nav className="flex items-center justify-between gap-3 border-t border-[color:var(--app-border)] p-4 text-sm" aria-label={fmt(t('situationRoom.pageOf'), { page: employees.page, pages })}>
                            <button type="button" className={buttonClassName({ variant: 'outline', size: 'sm' })} disabled={employees.page <= 1} onClick={() => go({ page: employees.page - 1 })}>{t('situationRoom.previousPage')}</button>
                            <span>{fmt(t('situationRoom.pageOf'), { page: employees.page, pages })}</span>
                            <button type="button" className={buttonClassName({ variant: 'outline', size: 'sm' })} disabled={employees.page >= pages} onClick={() => go({ page: employees.page + 1 })}>{t('situationRoom.nextPage')}</button>
                        </nav>
                    )}
                </Card>

                <Alert tone="info">{t('situationRoom.neutralNote')}</Alert>
            </div>
        </AuthenticatedLayout>
    );
}
