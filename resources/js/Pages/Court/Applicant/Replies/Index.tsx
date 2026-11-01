import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    ListPagination, badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { REVIEW_STATUS_TONES, useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantReplyIndexProps } from '@/court/types/applicantCases';

/** The applicant's replies to respondent responses: case, response number, review state, date; view, and edit while not accepted. */
export default function ApplicantRepliesIndex({ replies }: ApplicantReplyIndexProps) {
    const { t, reviewLabel } = useApplicantCaseText();
    const title = t('cm.respondent.applicant_response_replies');

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={title}
                description={t('cm.respondent.response_of_response')}
                backHref={route('court.applicant.dashboard')}
                actions={<span className="rounded-full border border-(--app-border) bg-(--app-surface-muted) px-3 py-1 text-xs font-semibold text-(--app-muted-foreground)">{replies.total}</span>}
            />
        )}>
            <Head title={title} />

            <div className={tableContainerClassName}>
                {replies.data.length === 0 ? <EmptyState title={t('cm.respondent.no_applicant_response_replies')} /> : (
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    <th className={tableHeaderCellClassName}>{t('cm.cases.case_number')}</th>
                                    <th className={tableHeaderCellClassName}>{t('cm.respondent.response_number_label')}</th>
                                    <th className={tableHeaderCellClassName}>{t('cm.cases.status.new_status')}</th>
                                    <th className={tableHeaderCellClassName}>{t('cm.cases.filed')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.cases.labels.actions')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {replies.data.map((reply) => (
                                    <tr key={reply.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'font-semibold text-(--app-foreground)')}>{reply.case_number}</td>
                                        <td className={tableCellClassName}>{reply.response_number ?? t('cm.cases.labels.not_available')}</td>
                                        <td className={tableCellClassName}><span className={badgeClassName(REVIEW_STATUS_TONES[reply.review_status] ?? 'warning')}>{reviewLabel(reply.review_status)}</span></td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={reply.created_at} withTime /></td>
                                        <td className={tableCellClassName}>
                                            <div className="flex justify-end gap-2">
                                                <Link href={route('court.applicant.cases.respondentResponses.replies.show', [reply.case_id, reply.respondent_response_id, reply.id])} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.cases.view')}</Link>
                                                {reply.editable && (
                                                    <Link href={route('court.applicant.cases.respondentResponses.replies.edit', [reply.case_id, reply.respondent_response_id, reply.id])} className={buttonClassName({ variant: 'ghost', size: 'sm' })}>{t('cm.cases.general.edit')}</Link>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <ListPagination meta={replies} />
            </div>
        </AuthenticatedLayout>
    );
}
