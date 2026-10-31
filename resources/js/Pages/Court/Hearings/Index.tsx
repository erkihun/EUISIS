import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import {
    FilterField, FilterPanel, Input, ListPagination, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import type { HearingsIndexProps } from '@/court/types/hearings';

/** Every scheduled hearing, newest first, with its case; a row opens to its location and notes. */
export default function HearingsIndex({ hearings, filters }: HearingsIndexProps) {
    const { t } = useLocale();
    const [caseNumber, setCaseNumber] = useState(filters.case_number);
    const [hearingDate, setHearingDate] = useState(filters.hearing_date);
    const [open, setOpen] = useState<number | null>(null);
    const title = t('cm.app.Hearings');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const params: Record<string, string> = {};
        if (caseNumber) params.case_number = caseNumber;
        if (hearingDate) params.hearing_date = hearingDate;
        router.get(route('court.admin.hearings.index'), params, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={`${formatNumber(hearings.total)} ${t('cm.cases.hearings.total')}`} />}>
            <Head title={title} />

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.admin.hearings.index'))}
                    hasActiveFilters={Boolean(filters.case_number || filters.hearing_date)} label={title}>
                    <FilterField label={t('cm.cases.table.case_number')}>
                        <Input value={caseNumber} onChange={(event) => setCaseNumber(event.target.value)} />
                    </FilterField>
                    <FilterField label={t('court.hearings.date')}>
                        <LocalizedDatePicker value={hearingDate} onChange={setHearingDate} />
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[t('cm.cases.table.number'), t('cm.cases.table.case_number'), t('court.hearings.dateTime'), t('court.hearings.type'), t('court.hearings.createdBy'), t('cm.cases.table.actions')].map((heading) => (
                                        <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {hearings.data.map((hearing, index) => (
                                    <Fragment key={hearing.id}>
                                        <tr className={tableRowClassName}>
                                            <td className={cx(tableCellClassName, 'tabular-nums text-(--app-muted-foreground)')}>{(hearings.from ?? 1) + index}</td>
                                            <td className={tableCellClassName}>
                                                {hearing.case_id
                                                    ? <Link href={route('court.cases.show', hearing.case_id)} className="font-mono font-semibold text-primary hover:underline">{hearing.case_number}</Link>
                                                    : <span className="text-(--app-muted-foreground)">—</span>}
                                                {hearing.case_title && <div className="text-xs text-(--app-muted-foreground)">{hearing.case_title}</div>}
                                            </td>
                                            <td className={cx(tableCellClassName, 'whitespace-nowrap')}><LocalizedDateDisplay value={hearing.hearing_at} withTime /></td>
                                            <td className={tableCellClassName}>{hearing.type ?? t('court.hearings.hearing')}</td>
                                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{hearing.creator_name ?? '—'}</td>
                                            <td className={tableCellClassName}>
                                                <button type="button" aria-expanded={open === hearing.id} onClick={() => setOpen(open === hearing.id ? null : hearing.id)}
                                                    className={buttonClassName({ variant: open === hearing.id ? 'primary' : 'outline', size: 'sm' })}>
                                                    {t('cm.cases.table.view')}
                                                </button>
                                            </td>
                                        </tr>
                                        {open === hearing.id && (
                                            <tr className="bg-(--app-surface-muted)">
                                                <td colSpan={6} className="px-4 py-3">
                                                    <dl className="grid gap-3 text-sm sm:grid-cols-3">
                                                        <div><dt className="text-xs text-(--app-muted-foreground)">{t('court.hearings.location')}</dt><dd className="text-(--app-foreground)">{hearing.location ?? '—'}</dd></div>
                                                        <div className="sm:col-span-2"><dt className="text-xs text-(--app-muted-foreground)">{t('court.hearings.notes')}</dt><dd className="whitespace-pre-line text-(--app-foreground)">{hearing.notes ?? '—'}</dd></div>
                                                    </dl>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {hearings.data.length === 0 && <EmptyState title={t('cm.cases.no_hearings_scheduled')} />}
                    <ListPagination meta={hearings} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
