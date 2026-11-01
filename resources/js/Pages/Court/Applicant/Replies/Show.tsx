import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Card, badgeClassName, buttonClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { REVIEW_STATUS_TONES, useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantReplyShowProps } from '@/court/types/applicantCases';
import { ReplyContext } from './Partials/ReplyParts';

/** One reply to a respondent response: its review state and note, the text, and the PDF shown inline; edit or delete while not accepted. */
export default function ApplicantReplyShow({ case: kase, response, reply }: ApplicantReplyShowProps) {
    const { t, reviewLabel } = useApplicantCaseText();
    const { confirm } = useConfirm();
    const [deleting, setDeleting] = useState(false);
    const title = t('cm.respondent.response_of_response');
    const download = route('court.applicant.cases.respondentResponses.replies.download', [kase.id, response.id, reply.id]);

    const remove = async () => {
        const result = await confirm({ title: t('cm.cases.confirm.remove_document'), variant: 'danger', confirmLabel: t('cm.cases.general.delete'), cancelLabel: t('cm.cases.cancel') });
        if (!result.confirmed) return;
        router.delete(route('court.applicant.cases.respondentResponses.replies.destroy', [kase.id, response.id, reply.id]), {
            onStart: () => setDeleting(true), onFinish: () => setDeleting(false),
        });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={title}
                backHref={route('court.applicant.cases.respondentResponses.show', [kase.id, response.id])}
                actions={(
                    <>
                        <span className={badgeClassName(REVIEW_STATUS_TONES[reply.review_status] ?? 'warning')}>{reviewLabel(reply.review_status)}</span>
                        {reply.editable && (
                            <>
                                <Link href={route('court.applicant.cases.respondentResponses.replies.edit', [kase.id, response.id, reply.id])} className={buttonClassName({ variant: 'outline' })}>{t('cm.cases.buttons.update')}</Link>
                                <button type="button" onClick={remove} disabled={deleting} className={buttonClassName({ variant: 'destructiveOutline' })}>{t('cm.cases.general.delete')}</button>
                            </>
                        )}
                    </>
                )}
            />
        )}>
            <Head title={`${title} · ${kase.case_number}`} />

            <div className="mx-auto w-full max-w-6xl space-y-5">
                <Card className="space-y-4">
                    <p className="text-sm text-(--app-muted-foreground)"><LocalizedDateDisplay value={reply.created_at} withTime /></p>
                    <ReplyContext kase={kase} response={response} applicantName={reply.applicant_name} />
                </Card>

                <Card className="space-y-3">
                    <h2 className="text-lg font-semibold text-(--app-foreground)">{t('cm.respondent.response_of_response_description')}</h2>
                    <div className="whitespace-pre-line text-sm text-(--app-foreground)">{reply.description}</div>
                    {reply.review_note && (
                        <div className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-3 text-sm text-(--app-foreground)">
                            <span className="font-semibold">{t('cm.cases.reviewer_note')}</span> {reply.review_note}
                        </div>
                    )}
                </Card>

                <Card className="space-y-4">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold text-(--app-foreground)">{t('cm.cases.attachment')}</h2>
                        <a href={download} className="text-sm font-semibold text-primary hover:underline">{t('cm.respondent.download')}</a>
                    </div>
                    <iframe src={`${download}?inline=1`}
                        className="min-h-[80vh] w-full rounded-control border border-(--app-border)" title={title} />
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
