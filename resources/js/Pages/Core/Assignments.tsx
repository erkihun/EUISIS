import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ClipboardListIcon } from '@/Components/NavIcons';
import { FilterField, FilterPanel, Input, ListPagination, selectClassName, type LaravelPageMeta } from '@/Components/ui';
import { Card, EmptyState, Table, TBody, THead, Td, Th, Tr } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';

type Named = { id: string; code: string | null; name_en: string | null; name_am: string | null } | null;

interface AssignmentRow {
    id: string;
    status: string;
    is_current: boolean;
    effective_from: string | null;
    effective_to: string | null;
    /** Where the placement came from (ADR 0010); absent on older EUISIS data. */
    assignment_type?: string | null;
    reason_code?: string | null;
    source_system?: string | null;
    employee: { id: string; employee_number: string; full_name: string | null; status: string | null } | null;
    organization: Named;
    unit: Named;
    position: { id: string; code: string | null; title_en: string | null; title_am: string | null } | null;
}

interface Props {
    assignments: AssignmentRow[];
    meta: LaravelPageMeta;
    filters: { search: string; organization: string; status: string; history: boolean };
    statuses: string[];
}

/**
 * Core › Assignments: who holds which placement, inside the viewer's scope. Read-only;
 * placements change through transfers and employment status (ADR 0002).
 */
export default function CoreAssignments({ assignments, meta, filters, statuses }: Props) {
    const { t, locale } = useLocale();
    const form = useForm({
        search: filters.search,
        organization: filters.organization,
        status: filters.status,
        history: filters.history ? '1' : '',
    });
    const name = (item: Named) => (item === null ? '—' : (locale === 'am' ? item.name_am || item.name_en : item.name_en || item.name_am) ?? item.code ?? '—');
    const submit = () => router.get(route('core.assignments'), form.data, { preserveState: true, preserveScroll: true });
    const hasFilters = Boolean(filters.search || filters.organization || filters.status || filters.history);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('core.assignments.title')} description={t('core.assignments.description')} />}>
            <Head title={t('core.assignments.title')} />

            <div className="space-y-4">
                <FilterPanel label={t('core.assignments.title')} onSubmit={submit} onReset={() => router.get(route('core.assignments'), {}, { preserveState: false })} hasActiveFilters={hasFilters}>
                    <FilterField label={t('core.assignments.search')} htmlFor="assignment-search" wide>
                        <Input id="assignment-search" type="search" placeholder={t('core.assignments.searchPlaceholder')} value={form.data.search} onChange={(e) => form.setData('search', e.target.value)} />
                    </FilterField>
                    <FilterField label={t('core.assignments.organizationCode')} htmlFor="assignment-organization">
                        <Input id="assignment-organization" value={form.data.organization} onChange={(e) => form.setData('organization', e.target.value)} />
                    </FilterField>
                    <FilterField label={t('core.assignments.status')} htmlFor="assignment-status">
                        <select id="assignment-status" className={selectClassName} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            <option value="">{t('core.assignments.anyStatus')}</option>
                            {statuses.map((status) => <option key={status} value={status}>{t(`core.assignments.statuses.${status}`)}</option>)}
                        </select>
                    </FilterField>
                    <FilterField label={t('core.assignments.scope')} htmlFor="assignment-history">
                        <select id="assignment-history" className={selectClassName} value={form.data.history} onChange={(e) => form.setData('history', e.target.value)}>
                            <option value="">{t('core.assignments.currentOnly')}</option>
                            <option value="1">{t('core.assignments.withHistory')}</option>
                        </select>
                    </FilterField>
                </FilterPanel>

                <Card className="overflow-hidden p-0">
                    {assignments.length === 0 ? (
                        <EmptyState
                            icon={<ClipboardListIcon className="h-8 w-8" />}
                            title={hasFilters ? t('core.assignments.noMatch') : t('core.assignments.empty')}
                            description={hasFilters ? t('core.assignments.noMatchHint') : t('core.assignments.emptyHint')}
                        />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('core.assignments.employee')}</Th>
                                    <Th>{t('core.assignments.organization')}</Th>
                                    <Th>{t('core.assignments.unit')}</Th>
                                    <Th>{t('core.assignments.position')}</Th>
                                    <Th>{t('core.assignments.from')}</Th>
                                    <Th>{t('core.assignments.to')}</Th>
                                    <Th>{t('core.assignments.status')}</Th>
                                    <Th>{t('core.assignments.provenance')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {assignments.map((row) => (
                                    <Tr key={row.id}>
                                        <Td>
                                            {row.employee ? (
                                                <Link href={route('employees.show', row.employee.id)} className="font-medium text-(--app-foreground) hover:text-primary">{row.employee.full_name ?? row.employee.employee_number}</Link>
                                            ) : '—'}
                                            <div className="font-mono text-xs text-(--app-muted-foreground)">{row.employee?.employee_number}</div>
                                        </Td>
                                        <Td>{name(row.organization)}</Td>
                                        <Td muted>{name(row.unit)}</Td>
                                        <Td>
                                            {row.position ? (locale === 'am' ? row.position.title_am || row.position.title_en : row.position.title_en || row.position.title_am) : '—'}
                                            <div className="font-mono text-xs text-(--app-muted-foreground)">{row.position?.code}</div>
                                        </Td>
                                        <Td><LocalizedDateDisplay value={row.effective_from} /></Td>
                                        <Td muted><LocalizedDateDisplay value={row.effective_to} /></Td>
                                        <Td><StatusBadge status={row.status} label={t(`core.assignments.statuses.${row.status}`)} /></Td>
                                        <Td muted>
                                            {row.assignment_type ? t(`core.assignments.types.${row.assignment_type}`) : '—'}
                                            {row.reason_code && <div className="text-xs">{t(`core.assignments.reasonCodes.${row.reason_code}`)}</div>}
                                            {row.source_system && <div className="text-xs">{t(`core.assignments.sources.${row.source_system}`)}</div>}
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>

                <ListPagination meta={meta} onPageChange={(page) => router.get(route('core.assignments'), { ...form.data, page }, { preserveState: true, preserveScroll: true })} />
            </div>
        </AuthenticatedLayout>
    );
}
