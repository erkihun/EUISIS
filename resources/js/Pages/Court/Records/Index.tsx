import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import {
    FilterField, FilterPanel, Input, ListPagination, Select, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
    type Tone,
} from '@/Components/ui';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { RecordListFilters, RecordsIndexProps } from '@/court/types/records';

const EMPTY: RecordListFilters = { q: '', status: '', case_type_id: '', assignee_id: '', from: '', to: '', appeal: '' };

/** The tone of the appellate court's outcome. */
const OUTCOME_TONES: Record<string, Tone> = { confirmed: 'success', modified: 'warning', reversed: 'danger' };

/** The tone of each review state, as the Blade list coloured it. */
const REVIEW_TONES: Record<string, Tone> = { awaiting_review: 'warning', accepted: 'success', returned: 'warning', rejected: 'danger' };

/**
 * The case records register: search and filters, one row per case with its
 * applicant, type, team, member, status, filing date and review state, whether
 * it went to the appellate court and with what outcome (EUISIS ADR 0013 #14),
 * and the record, PDF and appeal-record actions.
 */
export default function RecordsIndex({ cases, filters, statuses, types, users }: RecordsIndexProps) {
    const { t, tx, fill } = useInspectionPagesText();
    const [form, setForm] = useState<RecordListFilters>({ ...EMPTY, ...filters });
    const set = (key: keyof RecordListFilters) => (value: string) => setForm((current) => ({ ...current, [key]: value }));
    const active = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '')) as Partial<RecordListFilters>;
    const hasActiveFilters = Object.values(filters).some((value) => value !== '');
    const title = t('cm.recordes.titles.index');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('court.recordes.index'), active, { preserveState: true });
    };

    const shorten = (value: string | null, length: number): string => (value ? (value.length > length ? `${value.slice(0, length).trimEnd()}...` : value) : '-');

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} actions={<Link href={route('court.cases.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.recordes.buttons.back_to_cases')}</Link>} />
        )}>
            <Head title={title} />

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.recordes.index'))} hasActiveFilters={hasActiveFilters} label={title}
                    submitLabel={t('cm.cases.filters.apply_filters')} resetLabel={t('cm.cases.reset')}
                    footer={(
                        <span className="text-sm text-(--app-muted-foreground)">
                            {cases.total > 0
                                ? fill(t('cm.cases.showing_results'), { first: cases.from ?? 0, last: cases.to ?? 0, total: cases.total })
                                : t('cm.recordes.messages.no_cases')}
                        </span>
                    )}>
                    <FilterField label={t('cm.cases.search')} htmlFor="filter-q" wide>
                        <Input id="filter-q" type="search" value={form.q} onChange={(event) => set('q')(event.target.value)} placeholder={t('cm.cases.search_placeholder')} />
                    </FilterField>
                    <FilterField label={t('cm.cases.table.status')} htmlFor="filter-status">
                        <Select id="filter-status" value={form.status} onChange={(event) => set('status')(event.target.value)}>
                            <option value="">{t('cm.cases.filters.all_statuses')}</option>
                            {statuses.map((status) => <option key={status} value={status}>{tx(`cm.cases.status.${status}`, status)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('cm.cases.table.type')} htmlFor="filter-type">
                        <Select id="filter-type" value={form.case_type_id} onChange={(event) => set('case_type_id')(event.target.value)}>
                            <option value="">{t('cm.cases.filters.all_types')}</option>
                            {types.map((type) => <option key={type.id} value={String(type.id)}>{type.name}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('cm.cases.table.assignee')} htmlFor="filter-assignee">
                        <Select id="filter-assignee" value={form.assignee_id} onChange={(event) => set('assignee_id')(event.target.value)}>
                            <option value="">{t('cm.cases.filters.all_assignees')}</option>
                            {users.map((user) => <option key={user.id} value={String(user.id)}>{user.name}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('cm.appeal_records.register.filter')} htmlFor="filter-appeal">
                        <Select id="filter-appeal" value={form.appeal} onChange={(event) => set('appeal')(event.target.value)}>
                            <option value="">{t('cm.appeal_records.register.all')}</option>
                            {(['appealed', 'requested', 'none'] as const).map((value) => <option key={value} value={value}>{t(`cm.appeal_records.register.${value}`)}</option>)}
                        </Select>
                    </FilterField>
                    <FilterField label={t('cm.cases.filters.from')} htmlFor="filter-from">
                        <LocalizedDatePicker id="filter-from" value={form.from} onChange={set('from')} />
                    </FilterField>
                    <FilterField label={t('cm.cases.filters.to')} htmlFor="filter-to">
                        <LocalizedDatePicker id="filter-to" value={form.to} onChange={set('to')} />
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[
                                        t('cm.cases.table.number'), t('cm.cases.table.case_number'), t('cm.cases.applicant_name'), t('cm.cases.table.type'), t('cm.cases.table.team'),
                                        t('cm.cases.table.team_member'), t('cm.cases.table.status'), t('cm.cases.table.filing_date'), t('cm.cases.show.review_actions'), t('cm.appeal_records.register.column'), t('cm.recordes.labels.title'),
                                    ].map((heading) => <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>)}
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.cases.table.actions')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {cases.data.map((row, index) => (
                                    <tr key={row.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'tabular-nums text-(--app-muted-foreground)')}>{(cases.from ?? 1) + index}</td>
                                        <td className={tableCellClassName}>
                                            <Link href={route('court.recordes.show', row.id)} className="font-mono font-semibold text-primary hover:underline">{row.case_number}</Link>
                                        </td>
                                        <td className={tableCellClassName}>{row.applicant_name ?? '-'}</td>
                                        <td className={tableCellClassName}>{row.case_type ?? '-'}</td>
                                        <td className={tableCellClassName}>{row.team_name ?? '-'}</td>
                                        <td className={tableCellClassName}>{row.assignee_name ?? '-'}</td>
                                        <td className={tableCellClassName}><StatusBadge status={row.status} label={tx(`cm.cases.status.${row.status}`, row.status)} /></td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap')}><LocalizedDateDisplay value={row.filing_date} /></td>
                                        <td className={tableCellClassName}>
                                            <span className={badgeClassName(REVIEW_TONES[row.review_status] ?? 'neutral')}>{tx(`cm.cases.review_status.${row.review_status}`, row.review_status)}</span>
                                            {row.reviewer_name && <div className="mt-1 text-xs text-(--app-muted-foreground)">{fill(t('cm.cases.reviewed_by'), { name: row.reviewer_name })}</div>}
                                        </td>
                                        <td className={tableCellClassName}>
                                            {row.appealed_at ? (
                                                <div className="flex flex-wrap gap-1">
                                                    <span className={badgeClassName('warning')}>{t('cm.appeal_records.register.appealed')}</span>
                                                    {row.appeal_outcome && <span className={badgeClassName(OUTCOME_TONES[row.appeal_outcome] ?? 'neutral')}>{t(`cm.appeal_records.outcomes.${row.appeal_outcome}`)}</span>}
                                                </div>
                                            ) : row.appeal_requests > 0
                                                ? <span className={badgeClassName('info')}>{t('cm.appeal_records.register.requested')}</span>
                                                : <span className="text-(--app-muted-foreground)">-</span>}
                                        </td>
                                        <td className={tableCellClassName}>{shorten(row.title, 48)}</td>
                                        <td className={cx(tableCellClassName, 'text-right whitespace-nowrap')}>
                                            <div className="inline-flex flex-wrap items-center justify-end gap-1.5">
                                                <Link href={route('court.recordes.show', row.id)} className={buttonClassName({ variant: 'outline', size: 'xs' })}>{t('cm.recordes.buttons.view')}</Link>
                                                <a href={route('court.recordes.pdf', row.id)} target="_blank" rel="noopener" className={buttonClassName({ variant: 'outline', size: 'xs' })}>{t('cm.recordes.buttons.pdf')}</a>
                                                {row.has_appeal_record && (
                                                    <a href={route('court.recordes.appeal-pdf', row.id)} className={buttonClassName({ size: 'xs' })}>{t('cm.recordes.buttons.appeal_pdf')}</a>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {cases.data.length === 0 && <EmptyState title={t('cm.recordes.messages.no_cases')} />}
                    <ListPagination meta={cases} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
