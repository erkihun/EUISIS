import { useState, type JSX, type ReactNode, type SVGProps } from 'react';
import type { Page } from '../api';
import { useAuth } from '../auth';
import ListPagination from '../components/ListPagination';
import { Briefcase, Building2, ClipboardClockIcon, ClipboardListIcon, GitBranchIcon, UserPlusIcon, Users } from '../components/NavIcons';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useLocale } from '../i18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout';
import { auditLabel } from '../lib/auditLabel';
import { formatDate, formatDateTime, localized } from '../lib/format';
import { useList } from '../lib/useList';
import { Link } from '../router';
import type { Assignment, AuditEntry, Employee, Organization, Position, Unit } from '../types';
import { Alert, Card, EmptyState, FilterField, FilterPanel, Input, Table, TBody, THead, Td, Th, Tr, buttonClassName, selectClassName } from '../ui';

/**
 * The read-only Core lists, each built like EUISIS Pages/Core/Assignments.tsx:
 * a heading band, a FilterPanel applied on submit, a Card with the one table
 * style, an EmptyState and the pagination bar.
 */

type Icon = (p: SVGProps<SVGSVGElement>) => JSX.Element;

function ListFrame<T>({ result, loading, error, hasFilters, icon: IconComponent, empty, noMatch, onPage, children }: {
    result: Page<T> | null;
    loading: boolean;
    error: boolean;
    hasFilters: boolean;
    icon: Icon;
    empty: string;
    noMatch: string;
    onPage: (page: number) => void;
    children: ReactNode;
}) {
    const { t } = useLocale();

    return (
        <>
            {error && <Alert tone="danger">{t('platform.error')}</Alert>}
            <Card className={`overflow-hidden p-0 ${loading ? 'opacity-70' : ''}`} aria-busy={loading}>
                {result && result.data.length === 0 ? (
                    <EmptyState
                        icon={<IconComponent className="h-8 w-8" />}
                        title={hasFilters ? noMatch : empty}
                        description={hasFilters ? t('core.assignments.noMatchHint') : undefined}
                    />
                ) : result ? (
                    children
                ) : (
                    <EmptyState title={t('common.loading')} />
                )}
                <ListPagination meta={result} onPageChange={onPage} disabled={loading} />
            </Card>
        </>
    );
}

/** Search box + optional selects, applied together on Filter. */
function useFilterForm<F extends Record<string, string>>(initial: F) {
    const [form, setForm] = useState<F>(initial);
    const set = (name: keyof F) => (event: { target: { value: string } }) => setForm((current) => ({ ...current, [name]: event.target.value }));
    return { form, setForm, set };
}

export function OrganizationsPage() {
    const { t, locale } = useLocale();
    const list = useList<Organization>('/v1/organizations');
    const { form, setForm, set } = useFilterForm({ search: '' });
    const hasFilters = Boolean(list.filters.search);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.organizations.title')} description={t('platform.organizations.description')} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.organizations.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ search: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('platform.filters.search')} htmlFor="organization-search" wide>
                        <Input id="organization-search" type="search" placeholder={t('platform.organizations.searchPlaceholder')} value={form.search} onChange={set('search')} />
                    </FilterField>
                </FilterPanel>
                <ListFrame {...list} onPage={list.setPage} hasFilters={hasFilters} icon={Building2} empty={t('platform.organizations.empty')} noMatch={t('platform.organizations.noMatch')}>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('common.name')}</Th>
                                <Th>{t('common.code')}</Th>
                                <Th>{t('platform.organizations.type')}</Th>
                                <Th>{t('common.status')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {list.result?.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="font-medium">{localized(locale, row.name_en, row.name_am)}</Td>
                                    <Td muted className="font-mono text-xs">{row.code}</Td>
                                    <Td muted>{row.type ? localized(locale, row.type.name_en, row.type.name_am) : '—'}</Td>
                                    <Td><StatusBadge status={row.status} /></Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </ListFrame>
            </div>
        </AuthenticatedLayout>
    );
}

export function UnitsPage() {
    const { t, locale } = useLocale();
    const list = useList<Unit>('/v1/organization-units');
    const { form, setForm, set } = useFilterForm({ search: '' });
    const hasFilters = Boolean(list.filters.search);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.units.title')} description={t('platform.units.description')} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.units.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ search: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('platform.filters.search')} htmlFor="unit-search" wide>
                        <Input id="unit-search" type="search" placeholder={t('platform.units.searchPlaceholder')} value={form.search} onChange={set('search')} />
                    </FilterField>
                </FilterPanel>
                <ListFrame {...list} onPage={list.setPage} hasFilters={hasFilters} icon={GitBranchIcon} empty={t('platform.units.empty')} noMatch={t('platform.units.noMatch')}>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('common.name')}</Th>
                                <Th>{t('common.code')}</Th>
                                <Th>{t('platform.units.organization')}</Th>
                                <Th>{t('common.status')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {list.result?.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="font-medium">{localized(locale, row.name_en, row.name_am)}</Td>
                                    <Td muted className="font-mono text-xs">{row.code}</Td>
                                    <Td muted>{row.organization?.name_en ?? '—'}</Td>
                                    <Td><StatusBadge status={row.status} /></Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </ListFrame>
            </div>
        </AuthenticatedLayout>
    );
}

export function PositionsPage() {
    const { t, locale } = useLocale();
    const list = useList<Position>('/v1/positions');
    const { form, setForm, set } = useFilterForm({ search: '', occupancy: '' });
    const hasFilters = Boolean(list.filters.search || list.filters.occupancy);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.positions.title')} description={t('platform.positions.description')} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.positions.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ search: '', occupancy: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('platform.filters.search')} htmlFor="position-search" wide>
                        <Input id="position-search" type="search" placeholder={t('platform.positions.searchPlaceholder')} value={form.search} onChange={set('search')} />
                    </FilterField>
                    <FilterField label={t('platform.positions.occupancy')} htmlFor="position-occupancy">
                        <select id="position-occupancy" className={selectClassName} value={form.occupancy} onChange={set('occupancy')}>
                            <option value="">{t('platform.positions.anyOccupancy')}</option>
                            <option value="vacant">{t('platform.positions.vacant')}</option>
                            <option value="held">{t('platform.positions.held')}</option>
                        </select>
                    </FilterField>
                </FilterPanel>
                <ListFrame {...list} onPage={list.setPage} hasFilters={hasFilters} icon={Briefcase} empty={t('platform.positions.empty')} noMatch={t('platform.positions.noMatch')}>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('core.assignments.position')}</Th>
                                <Th>{t('platform.positions.organization')}</Th>
                                <Th align="right">{t('platform.positions.holders')}</Th>
                                <Th>{t('common.status')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {list.result?.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td>
                                        <span className="font-medium">{localized(locale, row.title_en, row.title_am)}</span>
                                        <div className="font-mono text-xs text-(--app-muted-foreground)">{row.job_position_code}</div>
                                    </Td>
                                    <Td muted>{row.organization?.name_en ?? '—'}</Td>
                                    <Td numeric>{row.holders_count}</Td>
                                    <Td><StatusBadge status={row.is_active ? 'active' : 'inactive'} /></Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </ListFrame>
            </div>
        </AuthenticatedLayout>
    );
}

export function EmployeesPage() {
    const { t } = useLocale();
    const { user } = useAuth();
    const list = useList<Employee>('/v1/employees');
    const { form, setForm, set } = useFilterForm({ search: '' });
    const hasFilters = Boolean(list.filters.search);
    const register = user?.can_change_master_data
        ? <Link href="/employees/new" className={buttonClassName({ variant: 'primary' })}><UserPlusIcon className="h-4 w-4" aria-hidden="true" />{t('nav.registerEmployee')}</Link>
        : undefined;

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.employees.title')} description={t('platform.employees.description')} actions={register} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.employees.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ search: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('platform.filters.search')} htmlFor="employee-search" wide>
                        <Input id="employee-search" type="search" placeholder={t('platform.employees.searchPlaceholder')} value={form.search} onChange={set('search')} />
                    </FilterField>
                </FilterPanel>
                <ListFrame {...list} onPage={list.setPage} hasFilters={hasFilters} icon={Users} empty={t('platform.employees.empty')} noMatch={t('platform.employees.noMatch')}>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('core.assignments.employee')}</Th>
                                <Th>{t('platform.employees.position')}</Th>
                                <Th>{t('platform.employees.organization')}</Th>
                                <Th>{t('platform.employees.activeCard')}</Th>
                                <Th>{t('common.status')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {list.result?.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td>
                                        <Link href={`/employees/${row.id}`} className="font-medium text-(--app-foreground) hover:text-primary">{row.full_name}</Link>
                                        <div className="font-mono text-xs text-(--app-muted-foreground)">{row.employee_number}</div>
                                    </Td>
                                    <Td>{row.current_assignment?.position?.title_en ?? '—'}</Td>
                                    <Td muted>{row.current_assignment?.organization?.name_en ?? '—'}</Td>
                                    <Td muted>{row.has_active_card ? t('common.yes') : t('common.no')}</Td>
                                    <Td><StatusBadge status={row.status} /></Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </ListFrame>
            </div>
        </AuthenticatedLayout>
    );
}

/** EUISIS Pages/Core/Assignments.tsx. */
export function AssignmentsPage() {
    const { t } = useLocale();
    const list = useList<Assignment>('/v1/assignments');
    const { form, setForm, set } = useFilterForm({ search: '', history: '' });
    const hasFilters = Boolean(list.filters.search || list.filters.history);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('core.assignments.title')} description={t('core.assignments.description')} />}>
            <div className="space-y-4">
                <FilterPanel label={t('core.assignments.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ search: '', history: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('core.assignments.search')} htmlFor="assignment-search" wide>
                        <Input id="assignment-search" type="search" placeholder={t('core.assignments.searchPlaceholder')} value={form.search} onChange={set('search')} />
                    </FilterField>
                    <FilterField label={t('core.assignments.scope')} htmlFor="assignment-history">
                        <select id="assignment-history" className={selectClassName} value={form.history} onChange={set('history')}>
                            <option value="">{t('core.assignments.currentOnly')}</option>
                            <option value="1">{t('core.assignments.withHistory')}</option>
                        </select>
                    </FilterField>
                </FilterPanel>
                <ListFrame {...list} onPage={list.setPage} hasFilters={hasFilters} icon={ClipboardListIcon} empty={t('core.assignments.empty')} noMatch={t('core.assignments.noMatch')}>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('core.assignments.employee')}</Th>
                                <Th>{t('core.assignments.organization')}</Th>
                                <Th>{t('core.assignments.position')}</Th>
                                <Th>{t('core.assignments.from')}</Th>
                                <Th>{t('core.assignments.to')}</Th>
                                <Th>{t('core.assignments.status')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {list.result?.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td>
                                        {row.employee ? (
                                            <Link href={`/employees/${row.employee.id}`} className="font-medium text-(--app-foreground) hover:text-primary">{row.employee.full_name}</Link>
                                        ) : '—'}
                                        <div className="font-mono text-xs text-(--app-muted-foreground)">{row.employee?.employee_number}</div>
                                    </Td>
                                    <Td>{row.organization?.name_en ?? '—'}</Td>
                                    <Td>
                                        {row.position?.title_en ?? '—'}
                                        <div className="font-mono text-xs text-(--app-muted-foreground)">{row.position?.job_position_code}</div>
                                    </Td>
                                    <Td>{formatDate(row.effective_from)}</Td>
                                    <Td muted>{formatDate(row.effective_to)}</Td>
                                    <Td><StatusBadge status={row.assignment_status} label={t(`core.assignments.statuses.${row.assignment_status}`)} /></Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </ListFrame>
            </div>
        </AuthenticatedLayout>
    );
}

const AUDIT_ACTIONS = ['employee.registered', 'assignment.transferred', 'card.suspend', 'card.reinstate', 'card.revoke', 'settings.updated'];

export function AuditPage() {
    const { t } = useLocale();
    const list = useList<AuditEntry>('/v1/audit');
    const { form, setForm, set } = useFilterForm({ action: '' });
    const hasFilters = Boolean(list.filters.action);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.audit.title')} description={t('platform.audit.description')} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.audit.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ action: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('platform.audit.action')} htmlFor="audit-action">
                        <select id="audit-action" className={selectClassName} value={form.action} onChange={set('action')}>
                            <option value="">{t('platform.audit.anyAction')}</option>
                            {AUDIT_ACTIONS.map((action) => <option key={action} value={action}>{auditLabel(t, action)}</option>)}
                        </select>
                    </FilterField>
                </FilterPanel>
                <ListFrame {...list} onPage={list.setPage} hasFilters={hasFilters} icon={ClipboardClockIcon} empty={t('platform.audit.empty')} noMatch={t('platform.audit.empty')}>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('platform.audit.when')}</Th>
                                <Th>{t('platform.audit.action')}</Th>
                                <Th>{t('platform.audit.subject')}</Th>
                                <Th>{t('platform.audit.reason')}</Th>
                                <Th>{t('platform.audit.request')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {list.result?.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="whitespace-nowrap tabular-nums">{formatDateTime(row.created_at)}</Td>
                                    <Td className="font-medium">{auditLabel(t, row.action)}</Td>
                                    <Td muted>
                                        {row.subject_type.split('\\').pop()}
                                        <div className="font-mono text-xs">{row.subject_id.slice(0, 8)}</div>
                                    </Td>
                                    <Td muted>{row.reason ?? '—'}</Td>
                                    <Td muted className="font-mono text-xs">{row.request_id?.slice(0, 12) ?? '—'}</Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </ListFrame>
            </div>
        </AuthenticatedLayout>
    );
}
