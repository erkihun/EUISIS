import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    ListPagination, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { CASE_STATUS_TONES, REVIEW_STATUS_TONES, useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantCaseIndexProps, ApplicantCaseRow } from '@/court/types/applicantCases';
import { EyeIcon, PlusIcon, TrashIcon } from '../../Cases/Show/icons';

/**
 * The applicant's own cases: number, title, type, status, review state (with
 * the reviewer's note when the case was returned or rejected), filing date,
 * and the view and delete actions (delete only while pending and unapproved).
 */
export default function ApplicantCasesIndex({ cases }: ApplicantCaseIndexProps) {
    const { t, text, statusLabel, reviewLabel } = useApplicantCaseText();
    // Only EUISIS employees and institutions are parties: new cases are filed from the EUISIS employee portal.
    const shared = usePage().props as { portal?: { filesInEuisis?: boolean } | null; auth?: { user?: { can_file_for_client?: boolean } | null } };
    const filesInEuisis = Boolean(shared.portal?.filesInEuisis);
    // A verified lawyer files for a client whatever the filing mode (EUISIS ADR 0013 #13).
    const filesForClients = Boolean(shared.auth?.user?.can_file_for_client);
    const { confirm } = useConfirm();
    const [deleting, setDeleting] = useState<number | null>(null);
    const title = t('cm.cases.my_cases');

    const remove = async (row: ApplicantCaseRow) => {
        const result = await confirm({
            title: t('cm.cases.table.delete_confirm'),
            description: row.case_number,
            variant: 'danger',
            confirmLabel: t('cm.cases.general.delete'),
            cancelLabel: t('cm.cases.cancel'),
        });
        if (!result.confirmed) return;
        router.delete(route('court.applicant.cases.destroy', row.id), {
            preserveScroll: true,
            onStart: () => setDeleting(row.id),
            onFinish: () => setDeleting(null),
        });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={title}
                actions={(
                    <>
                        <Link href={route('court.applicant.response-replies.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.respondent.applicant_response_replies')}</Link>
                        {!filesInEuisis && <Link href={route('court.applicant.cases.create')} className={buttonClassName({ variant: filesForClients ? 'outline' : 'primary' })}><PlusIcon className="mr-1.5 h-4 w-4" />{t('cm.cases.new_case')}</Link>}
                        {filesForClients && <Link href={route('court.applicant.lawyer.file')} className={buttonClassName({ variant: 'primary' })}><PlusIcon className="mr-1.5 h-4 w-4" />{t('cm.lawyer.file.nav')}</Link>}
                    </>
                )}
            />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                <div className="overflow-x-auto">
                    <table className={cx(tableClassName, 'min-w-full text-left')}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                {[
                                    t('cm.cases.table.case_number'), t('cm.cases.table.title'), t('cm.cases.table.type'), t('cm.cases.table.status'),
                                    text('review'), t('cm.cases.table.filed'), t('cm.cases.table.actions'),
                                ].map((heading) => (
                                    <th key={heading} className={cx(tableHeaderCellClassName, 'whitespace-nowrap text-left')}>{heading}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {cases.data.map((row) => (
                                <tr key={row.id} className={tableRowClassName}>
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap')}>
                                        <Link href={route('court.applicant.cases.show', row.id)} className="font-mono text-xs font-semibold text-primary hover:underline">{row.case_number}</Link>
                                    </td>
                                    <td className={cx(tableCellClassName, 'max-w-xs')}>
                                        <div className="line-clamp-2 text-(--app-foreground)">{row.title ?? '-'}</div>
                                    </td>
                                    <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{row.case_type ?? '-'}</td>
                                    <td className={tableCellClassName}>
                                        <span className={badgeClassName(CASE_STATUS_TONES[row.status] ?? 'neutral')}>{statusLabel(row.status)}</span>
                                    </td>
                                    <td className={tableCellClassName}>
                                        <span className={badgeClassName(REVIEW_STATUS_TONES[row.review_status] ?? 'success')}>{reviewLabel(row.review_status)}</span>
                                        {row.review_note && ['returned', 'rejected'].includes(row.review_status) && (
                                            <div className="mt-1 line-clamp-2 max-w-xs text-[11px] leading-snug text-(--app-muted-foreground)">{row.review_note}</div>
                                        )}
                                    </td>
                                    <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={row.filing_date} /></td>
                                    <td className={tableCellClassName}>
                                        <div className="flex items-center gap-2">
                                            <Link href={route('court.applicant.cases.show', row.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                <EyeIcon className="mr-1 h-3.5 w-3.5" />{t('cm.cases.table.view')}
                                            </Link>
                                            {row.can_delete && (
                                                <button type="button" onClick={() => remove(row)} disabled={deleting === row.id} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                    <TrashIcon className="mr-1 h-3.5 w-3.5" />{t('cm.cases.general.delete')}
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {cases.data.length === 0 && <EmptyState title={t('cm.cases.table.no_cases_yet')} />}
                <ListPagination meta={cases} />
            </div>
        </AuthenticatedLayout>
    );
}
