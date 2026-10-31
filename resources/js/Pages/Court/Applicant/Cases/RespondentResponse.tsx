import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import { Alert, Card, actionLinkClassName, badgeClassName, buttonClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { REVIEW_STATUS_TONES, useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantRespondentResponseProps, ResponseReplyListItem } from '@/court/types/applicantCases';
import { EmptyBox } from '../../Cases/Show/SectionCard';
import { PlusIcon } from '../../Cases/Show/icons';

/**
 * A respondent's accepted response to the applicant's case: who answered,
 * the summary, the response text and its PDF (shown inline), then the
 * applicant's replies to it ("response of response") with view, edit and
 * delete while a reply is not yet accepted, and the button to write one.
 */
export default function ApplicantRespondentResponse({ case: kase, response, replies }: ApplicantRespondentResponseProps) {
    const { t, reviewLabel } = useApplicantCaseText();
    const { confirm } = useConfirm();
    const [deleting, setDeleting] = useState<number | null>(null);
    const title = t('cm.cases.respondent_response');

    const remove = async (reply: ResponseReplyListItem) => {
        const result = await confirm({ title: t('cm.cases.confirm.remove_document'), variant: 'danger', confirmLabel: t('cm.cases.general.delete'), cancelLabel: t('cm.cases.cancel') });
        if (!result.confirmed) return;
        router.delete(route('court.applicant.cases.respondentResponses.replies.destroy', [kase.id, response.id, reply.id]), {
            preserveScroll: true, onStart: () => setDeleting(reply.id), onFinish: () => setDeleting(null),
        });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={title}
                description={`${t('cm.cases.case_number')}: ${kase.case_number}`}
                backHref={route('court.applicant.cases.show', kase.id)}
                actions={(
                    <>
                        <Link href={route('court.applicant.cases.respondentResponses.replies.create', [kase.id, response.id])} className={buttonClassName({ variant: 'primary' })}>
                            <PlusIcon className="mr-1.5 h-4 w-4" />{t('cm.respondent.give_response_of_response')}
                        </Link>
                        <Link href={route('court.applicant.cases.show', kase.id)} className={buttonClassName({ variant: 'outline' })}>{t('cm.cases.back_to_case')}</Link>
                    </>
                )}
            />
        )}>
            <Head title={`${title} · ${kase.case_number}`} />

            <div className="space-y-5">
                <Card className="space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h2 className="text-lg font-semibold text-(--app-foreground)">{response.title ?? title}</h2>
                            <p className="text-xs text-(--app-muted-foreground)">{t('cm.cases.case_number')}: {response.case_number || kase.case_number}</p>
                            {response.response_number && (
                                <p className="text-xs font-semibold text-(--app-muted-foreground)">{t('cm.respondent.response_number_label')}: {response.response_number}</p>
                            )}
                            {(response.respondent_name || response.respondent_email || response.respondent_phone) && (
                                <dl className="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-xs sm:grid-cols-2">
                                    {response.respondent_name && (
                                        <div>
                                            <dt className="uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.respondent_defendant')}</dt>
                                            <dd className="font-semibold text-(--app-foreground)">{response.respondent_name}</dd>
                                        </div>
                                    )}
                                    {response.respondent_email && (
                                        <div>
                                            <dt className="uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.applicant_email')}</dt>
                                            <dd className="text-(--app-foreground)">{response.respondent_email}</dd>
                                        </div>
                                    )}
                                    {response.respondent_phone && (
                                        <div>
                                            <dt className="uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.labels.phone')}</dt>
                                            <dd className="text-(--app-foreground)">{response.respondent_phone}</dd>
                                        </div>
                                    )}
                                    {response.respondent_org && (
                                        <div>
                                            <dt className="uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.respondent_name')}</dt>
                                            <dd className="text-(--app-foreground)">{response.respondent_org}</dd>
                                        </div>
                                    )}
                                </dl>
                            )}
                        </div>
                        <div className="text-sm text-(--app-muted-foreground)"><LocalizedDateDisplay value={response.created_at} withTime /></div>
                    </div>

                    {response.summary && <Alert tone="info">{response.summary}</Alert>}

                    <RichTextViewer html={response.description_html} empty={<p className="text-sm text-(--app-muted-foreground)">{t('cm.cases.no_description')}</p>} />

                    {response.has_pdf && (
                        <div className="border-t border-(--app-border) pt-4">
                            <div className="mb-2 text-sm text-(--app-muted-foreground)">
                                {t('cm.cases.attachment')}: <span className="font-medium text-(--app-foreground)">{response.pdf_name}</span>
                            </div>
                            <iframe
                                src={`${route('court.applicant.respondent.responses.download', response.id)}?inline=1`}
                                className="min-h-[70vh] w-full rounded-control border border-(--app-border) lg:min-h-[85vh]"
                                title={response.title ?? t('cm.cases.download_pdf')}
                            />
                        </div>
                    )}
                </Card>

                <Card className="space-y-4">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-lg font-semibold text-(--app-foreground)">{t('cm.respondent.applicant_response_replies')}</h2>
                            <p className="text-sm text-(--app-muted-foreground)">{t('cm.respondent.response_of_response')}</p>
                        </div>
                        <span className="rounded-full border border-(--app-border) bg-(--app-surface-muted) px-3 py-1 text-xs font-semibold text-(--app-muted-foreground)">{replies.length}</span>
                    </div>

                    {replies.length === 0 ? <EmptyBox>{t('cm.respondent.no_applicant_response_replies')}</EmptyBox> : (
                        <div className="space-y-3">
                            {replies.map((reply) => (
                                <div key={reply.id} className="rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0 space-y-2">
                                            <p className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.respondent.response_of_response')}</p>
                                            <span className={badgeClassName(REVIEW_STATUS_TONES[reply.review_status] ?? 'warning')}>{reviewLabel(reply.review_status)}</span>
                                            <p className="text-sm text-(--app-foreground)">{reply.excerpt}</p>
                                            {reply.review_note && (
                                                <p className="text-xs text-(--app-muted-foreground)"><span className="font-semibold text-(--app-foreground)">{t('cm.cases.reviewer_note')}</span> {reply.review_note}</p>
                                            )}
                                        </div>
                                        <p className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={reply.created_at} withTime /></p>
                                    </div>
                                    <div className="mt-4 flex flex-wrap items-center gap-2">
                                        <Link href={route('court.applicant.cases.respondentResponses.replies.show', [kase.id, response.id, reply.id])} className={buttonClassName({ variant: 'outline', size: 'xs' })}>{t('cm.cases.view')}</Link>
                                        {reply.editable && (
                                            <>
                                                <Link href={route('court.applicant.cases.respondentResponses.replies.edit', [kase.id, response.id, reply.id])} className={buttonClassName({ variant: 'ghost', size: 'xs' })}>{t('cm.cases.general.edit')}</Link>
                                                <button type="button" onClick={() => remove(reply)} disabled={deleting === reply.id} className={actionLinkClassName({ tone: 'danger' })}>{t('cm.cases.general.delete')}</button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
