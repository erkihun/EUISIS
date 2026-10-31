import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    ListPagination, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useRespondentText } from '@/court/i18n/useRespondentText';
import type { RespondentRepliesIndexProps } from '@/court/types/respondent';

/** The applicants' accepted replies to this respondent's responses, newest first, fifteen to a page. */
export default function RespondentResponseRepliesIndex({ replies }: RespondentRepliesIndexProps) {
    const { t } = useRespondentText();
    const title = t('cm.respondent.response_of_response');
    const notAvailable = t('cm.cases.labels.not_available');

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} actions={(
                <Link href={route('court.respondent.dashboard')} className={buttonClassName({ variant: 'outline' })}>{t('cm.respondent.dashboard')}</Link>
            )} />
        )}>
            <Head title={title} />

            {replies.data.length === 0 ? (
                <EmptyState title={t('cm.respondent.no_applicant_response_replies')} />
            ) : (
                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    {[t('cm.cases.case_number'), t('cm.respondent.response_number_label'), t('cm.cases.applicant_full_name'), t('cm.respondent.uploaded')].map((heading) => (
                                        <th key={heading} className={cx(tableHeaderCellClassName, 'text-left')}>{heading}</th>
                                    ))}
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.cases.labels.actions')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {replies.data.map((reply) => (
                                    <tr key={reply.id} className={tableRowClassName}>
                                        <td className={cx(tableCellClassName, 'font-mono font-semibold text-(--app-foreground)')}>{reply.case_number}</td>
                                        <td className={tableCellClassName}>{reply.response_number || notAvailable}</td>
                                        <td className={tableCellClassName}>{reply.applicant_name || notAvailable}</td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={reply.created_at} withTime /></td>
                                        <td className={cx(tableCellClassName, 'text-right')}>
                                            <Link href={route('court.respondent.response-replies.show', reply.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.respondent.view_details')}</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <ListPagination meta={replies} />
                </div>
            )}
        </AuthenticatedLayout>
    );
}
