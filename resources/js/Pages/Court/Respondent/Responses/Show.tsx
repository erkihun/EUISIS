import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import { DownloadIcon } from '@/Components/Icons';
import { Card, badgeClassName, buttonClassName } from '@/Components/ui';
import { useRespondentText } from '@/court/i18n/useRespondentText';
import type { RespondentResponseShowProps } from '@/court/types/respondent';
import { useStatusLabels } from '../Partials/status';

/** One response: its review state, numbers, main content, the reviewer's note while not accepted, and the PDF. */
export default function RespondentResponseShow({ response }: RespondentResponseShowProps) {
    const { t, fill } = useRespondentText();
    const { reviewStatus, reviewTone } = useStatusLabels();
    const pageTitle = fill(t('cm.respondent.response_title'), { title: response.title });

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={response.title} actions={(
                <Link href={route('court.respondent.responses.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.respondent.back_to_responses')}</Link>
            )} />
        )}>
            <Head title={pageTitle} />

            <div className="space-y-4">
                <Card className="space-y-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className={badgeClassName(reviewTone(response.review_status))}>{reviewStatus(response.review_status)}</span>
                        <LocalizedDateDisplay value={response.created_at} withTime className="text-sm text-(--app-muted-foreground)" />
                    </div>
                    {response.response_number && (
                        <p className="text-xs font-semibold text-(--app-foreground)">{t('cm.respondent.response_number_label')}: {response.response_number}</p>
                    )}
                    {response.case_number && (
                        <p className="text-xs text-(--app-muted-foreground)">{t('cm.respondent.case_number_label')}: <span className="font-mono">{response.case_number}</span></p>
                    )}
                    {response.review_note && response.review_status !== 'accepted' && (
                        <p className="text-sm text-(--app-foreground)"><span className="font-semibold">{t('cm.cases.reviewer_note')}</span> {response.review_note}</p>
                    )}
                </Card>

                {response.description && (
                    <Card className="space-y-2">
                        <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.respondent.description_label')}</h2>
                        <RichTextViewer html={response.description} className="text-sm text-(--app-foreground)" />
                    </Card>
                )}

                {response.has_pdf && (
                    <Card className="space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold text-(--app-foreground)">{t('cm.respondent.response_pdf')}</h2>
                            <a href={route('court.applicant.respondent.responses.download', response.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                <DownloadIcon className="h-4 w-4" />{t('cm.respondent.download')}
                            </a>
                        </div>
                        <iframe
                            src={`${route('court.applicant.respondent.responses.download', { response: response.id, inline: 1 })}#toolbar=0&navpanes=0&scrollbar=0`}
                            loading="lazy"
                            title={response.title}
                            className="min-h-[420px] w-full rounded-lg border border-(--app-border) bg-white"
                        />
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
