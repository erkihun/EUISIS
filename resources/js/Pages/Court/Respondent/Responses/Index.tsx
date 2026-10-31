import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { EyeIcon, PencilIcon, Plus, ScrollText, TrashIcon } from '@/Components/Icons';
import {
    badgeClassName, buttonClassName, cx,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useRespondentText } from '@/court/i18n/useRespondentText';
import type { RespondentResponseItem, RespondentResponsesIndexProps } from '@/court/types/respondent';
import { useStatusLabels } from '../Partials/status';

/**
 * The respondent's responses, newest first: title, review state (with the reviewer's note when it was
 * returned), case and date. Responses not yet accepted can be edited or deleted.
 */
export default function RespondentResponsesIndex({ responses }: RespondentResponsesIndexProps) {
    const { t, text } = useRespondentText();
    const { reviewStatus, reviewTone } = useStatusLabels();
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState<number | null>(null);
    const title = t('cm.respondent.responses');

    const remove = async (response: RespondentResponseItem) => {
        const { confirmed } = await confirm({
            title: t('cm.respondent.delete'),
            description: text('delete_response_confirm'),
            confirmLabel: t('cm.respondent.delete'),
            cancelLabel: t('common.cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;

        router.delete(route('court.respondent.responses.destroy', response.id), {
            preserveScroll: true,
            onStart: () => setBusy(response.id),
            onFinish: () => setBusy(null),
        });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} actions={(
                <Link href={route('court.respondent.responses.create')} className={buttonClassName({})}><Plus className="h-4 w-4" />{t('cm.respondent.create_response')}</Link>
            )} />
        )}>
            <Head title={title} />

            {responses.length === 0 ? (
                <EmptyState title={t('cm.respondent.no_responses')} description={t('cm.respondent.start_first_response')} />
            ) : (
                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cm.respondent.responses')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cm.respondent.review_status_label')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cm.respondent.case_number_label')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cm.respondent.uploaded')}</th>
                                    <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cm.app.Actions')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {responses.map((response) => (
                                    <tr key={response.id} className={tableRowClassName}>
                                        <td className={tableCellClassName}>
                                            <div className="font-semibold text-(--app-foreground)">{response.title}</div>
                                            {response.review_note && response.review_status === 'returned' && (
                                                <div className="mt-1 text-xs text-(--app-muted-foreground)">
                                                    <span className="font-semibold text-(--app-foreground)">{t('cm.cases.reviewer_note')}</span> {response.review_note}
                                                </div>
                                            )}
                                            {response.has_pdf && (
                                                <span className={badgeClassName('info', 'mt-1 inline-flex items-center gap-1')}>
                                                    <ScrollText className="h-3 w-3" aria-hidden="true" />{t('cm.respondent.pdf_submitted')}
                                                </span>
                                            )}
                                        </td>
                                        <td className={tableCellClassName}><span className={badgeClassName(reviewTone(response.review_status))}>{reviewStatus(response.review_status)}</span></td>
                                        <td className={cx(tableCellClassName, 'font-mono text-(--app-muted-foreground)')}>{response.case_number || t('cm.cases.labels.not_available')}</td>
                                        <td className={cx(tableCellClassName, 'whitespace-nowrap text-(--app-muted-foreground)')}><LocalizedDateDisplay value={response.created_at} /></td>
                                        <td className={tableCellClassName}>
                                            <div className="flex items-center justify-end gap-2">
                                                <Link href={route('court.respondent.responses.show', response.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                    <EyeIcon className="h-4 w-4" />{t('cm.respondent.view_details')}
                                                </Link>
                                                {!response.locked && (
                                                    <>
                                                        <Link href={route('court.respondent.responses.edit', response.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                            <PencilIcon className="h-4 w-4" />{t('cm.respondent.edit_response')}
                                                        </Link>
                                                        <button type="button" disabled={busy === response.id} onClick={() => void remove(response)} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>
                                                            <TrashIcon className="h-4 w-4" />{t('cm.respondent.delete')}
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
