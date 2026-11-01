import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import {
    FilterField, FilterPanel, Input, ListPagination, Select, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { CaseListFilters, CaseListProps } from '@/court/types/cases';

const EMPTY: CaseListFilters = { q: '', status: '', case_type_id: '', assignee_id: '', from: '', to: '' };

/** The tone of each review state, as the Blade list coloured it. */
const REVIEW_TONES: Record<string, 'warning' | 'success' | 'danger' | 'neutral'> = {
    awaiting_review: 'warning', accepted: 'success', returned: 'warning', rejected: 'danger',
};

/**
 * The cases register: search and filters, one row per case with its
 * applicant, type, team, member, status, filing date and review state, and
 * the CSV export of the same selection.
 */
export default function CasesIndex({ cases, filters, statuses, types, users, canExport }: CaseListProps) {
    const { t } = useLocale();
    const [form, setForm] = useState<CaseListFilters>({ ...EMPTY, ...filters });
    const set = (key: keyof CaseListFilters) => (value: string) => setForm((current) => ({ ...current, [key]: value }));
    const active = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '')) as Partial<CaseListFilters>;
    const hasActiveFilters = Object.values(filters).some((value) => value !== '');
    const title = t('cm.cases.case_details');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.cases.index'), active, { preserveState: true });
    };
    const label = (key: string, fallback: string) => {
        const text = t(key);
        return text === key ? fallback : text;
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} />}>
            <Head title={title} />

            <div className="space-y-4">
                <FilterPanel
                    onSubmit={submit}
                    onReset={() => router.get(route('court.cases.index'))}
                    hasActiveFilters={hasActiveFilters}
                    label={title}
                    submitLabel={t('cm.cases.filters.apply_filters')}
                    resetLabel={t('cm.cases.reset')}
                    actions={canExport ? (
                        <a href={route('court.cases.export', active)} className={buttonClassName({ variant: 'outline' })}>{t('cm.cases.export_csv')}</a>
                    ) : undefined}
                >
                    <FilterField label={t('cm.cases.search')} className="sm:col-span-2">
                        <Input value={form.q} onChange={(event) => set('q')(event.target.value)} placeholder={t('cm.cases.search_placeholder')} />
                    </FilterField>
                    <FilterField label={t('cm.cases.table.status')}>
                        <Select value={form.status} onChange={(event) => set('status')(event.target.value)}>
                            <option value="">{t('cm.cases.filters.all_statuses')}</option>
                            {statuses.map((status) => <option key={status} value={status}>{t(`cm.cases.status.${status}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('cm.cases.table.type')}>
                        <Select value={form.case_type_id} onChange={(event) => set('case_type_id')(event.target.value)}>
                            <option value="">{t('cm.cases.filters.all_types')}</option>
                            {types.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('cm.cases.table.assignee')}>
                        <Select value={form.assignee_id} onChange={(event) => set('assignee_id')(event.target.value)}>
                            <option value="">{t('cm.cases.filters.all_assignees')}</option>
                            {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('cm.cases.filters.from')}>
                        <LocalizedDatePicker value={form.from} onChange={set('from')} />
                    </FilterField>
                    <FilterField label={t('cm.cases.filters.to')}>
                        <LocalizedDatePicker value={form.to} onChange={set('to')} min={form.from || undefined} />
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        t('cm.cases.table.number'), t('cm.cases.table.case_number'), t('cm.cases.applicant_name'), t('cm.cases.table.type'),
                                        t('cm.cases.table.team'), t('cm.cases.table.team_member'), t('cm.cases.table.status'), t('cm.cases.table.filing_date'),
                                        t('cm.cases.show.review_actions'), t('cm.cases.table.actions'),
                                    ].map((heading) => (
                                        <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {cases.data.map((row, index) => (
                                    <tr key={row.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'tabular-nums text-(--app-muted-foreground)')}>{(cases.from ?? 1) + index}</td>
                                        <td className={tableCellClassName}>
                                            <Link href={route('court.cases.show', row.id)} className="font-mono font-semibold text-primary hover:underline">{row.case_number}</Link>
                                        </td>
                                        <td className={cx(tableCellClassName, 'max-w-xs')}>
                                            {row.applicant_is_lawyer && row.applicant_name && (
                                                <div className="text-xs text-indigo-700 dark:text-indigo-300">{t('cm.dashboard.submitted_by_lawyer').replace(':name', row.applicant_name)}</div>
                                            )}
                                            <div className="truncate text-(--app-foreground)">{row.title || '-'}</div>
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{row.case_type ?? '-'}</td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{row.team_name ?? '-'}</td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{row.assignee_name ?? '-'}</td>
                                        <td className={tableCellClassName}><StatusBadge status={row.status} label={label(`cm.cases.status.${row.status}`, row.status)} /></td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={row.filing_date} /></td>
                                        <td className={tableCellClassName}>
                                            <span className={badgeClassName(REVIEW_TONES[row.review_status] ?? 'neutral')}>{label(`cm.cases.review_status.${row.review_status}`, row.review_status)}</span>
                                            {row.reviewer_name && <div className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.cases.reviewed_by').replace(':name', row.reviewer_name)}</div>}
                                        </td>
                                        <td className={tableCellClassName}>
                                            <Link href={route('court.cases.show', row.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.cases.table.view')}</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {cases.data.length === 0 && <EmptyState title={t('cm.cases.table.no_cases_found')} />}
                    <ListPagination meta={cases} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
