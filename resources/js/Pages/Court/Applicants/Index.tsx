import { Head, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    FilterField, FilterPanel, Input, ListPagination, Select, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { ApplicantRow, ApplicantsIndexProps } from '@/court/types/people';

/** Everyone who files cases: who they are, how to reach them, whether their account is active and verified. */
export default function ApplicantsIndex({ applicants, filters, canManage }: ApplicantsIndexProps) {
    const { t } = useLocale();
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState<string>(filters.status);
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.applicants.page_header');
    const unknown = t('cm.applicants.meta.unknown');

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const params: Record<string, string> = {};
        if (q) params.q = q;
        if (status) params.status = status;
        router.get(route('court.applicants.index'), params, { preserveState: true });
    };
    const setActive = (applicant: ApplicantRow, active: boolean) =>
        router.patch(route('court.applicants.status.update', applicant.id), { is_active: active ? 1 : 0 }, { preserveScroll: true, onStart: () => setBusy(applicant.id), onFinish: () => setBusy(null) });

    return (
        <AuthenticatedLayout header={<PageHeader title={title} />}>
            <Head title={t('cm.applicants.title')} />

            <div className="space-y-4">
                <FilterPanel onSubmit={submit} onReset={() => router.get(route('court.applicants.index'))} hasActiveFilters={Boolean(filters.q || filters.status)} label={title}
                    submitLabel={t('cm.applicants.filters.filter')} resetLabel={t('cm.applicants.filters.reset')}>
                    <FilterField label={t('cm.app.Search')} className="sm:col-span-2">
                        <Input value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('cm.applicants.search_placeholder')} />
                    </FilterField>
                    <FilterField label={t('cm.applicants.table.status')}>
                        <Select value={status} onChange={(event) => setStatus(event.target.value)}>
                            <option value="">{t('cm.applicants.filters.all_statuses')}</option>
                            <option value="active">{t('cm.app.Active')}</option>
                            <option value="inactive">{t('cm.app.Inactive')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {['applicant', 'email', 'phone', 'organization', 'status', 'verified', 'created', 'actions'].map((key) => (
                                        <th key={key} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{t(`cm.applicants.table.${key}`)}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {applicants.data.map((applicant) => (
                                    <tr key={applicant.id} className={cx(tableRowClassName, busy === applicant.id && 'opacity-60')}>
                                        <td className={tableCellClassName}>
                                            <div className="font-medium text-(--app-foreground)">{applicant.full_name}</div>
                                            {applicant.national_id_number && <div className="font-mono text-xs text-(--app-muted-foreground)">{applicant.national_id_number}</div>}
                                        </td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{applicant.email ?? unknown}</td>
                                        <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{applicant.phone ?? unknown}</td>
                                        <td className={tableCellClassName}>
                                            <div>{applicant.position ?? unknown}</div>
                                            <div className="text-xs text-(--app-muted-foreground)">{applicant.organization_name ?? unknown}</div>
                                        </td>
                                        <td className={tableCellClassName}><span className={badgeClassName(applicant.is_active ? 'success' : 'warning')}>{applicant.is_active ? t('cm.app.Active') : t('cm.app.Inactive')}</span></td>
                                        <td className={tableCellClassName}><span className={badgeClassName(applicant.verified ? 'success' : 'neutral')}>{t(`cm.applicants.verified.${applicant.verified ? 'verified' : 'unverified'}`)}</span></td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={applicant.created_at} /></td>
                                        <td className={tableCellClassName}>
                                            {canManage ? (
                                                <button type="button" disabled={busy === applicant.id} onClick={() => setActive(applicant, !applicant.is_active)}
                                                    className={buttonClassName({ variant: applicant.is_active ? 'destructiveOutline' : 'outline', size: 'sm' })}>
                                                    {applicant.is_active ? t('cm.app.Deactivate') : t('cm.app.Activate')}
                                                </button>
                                            ) : <span className="text-xs text-(--app-muted-foreground)">{unknown}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {applicants.data.length === 0 && <EmptyState title={t('cm.applicants.empty')} />}
                    <ListPagination meta={applicants} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
