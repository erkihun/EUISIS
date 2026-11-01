import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { DownloadIcon } from '@/Components/Icons';
import { Card, buttonClassName } from '@/Components/ui';
import { useRespondentText } from '@/court/i18n/useRespondentText';
import type { RespondentReplyShowProps } from '@/court/types/respondent';

/** One accepted reply of an applicant to this respondent's response: case, response number, applicant, text and the attached PDF. */
export default function RespondentResponseReplyShow({ reply }: RespondentReplyShowProps) {
    const { t } = useRespondentText();
    const title = t('cm.respondent.response_of_response');
    const notAvailable = t('cm.cases.labels.not_available');
    const downloadUrl = route('court.respondent.response-replies.download', reply.id);

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} actions={(
                <Link href={route('court.respondent.response-replies.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.respondent.back_to_responses')}</Link>
            )} />
        )}>
            <Head title={title} />

            <div className="space-y-4">
                <Card>
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <div className="rounded-lg border border-(--app-border) bg-(--app-surface-muted) p-4">
                            <dt className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.case_number')}</dt>
                            <dd className="mt-1 font-mono text-sm font-semibold text-(--app-foreground)">{reply.case_number}</dd>
                        </div>
                        <div className="rounded-lg border border-(--app-border) bg-(--app-surface-muted) p-4">
                            <dt className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.respondent.response_number_label')}</dt>
                            <dd className="mt-1 text-sm font-semibold text-(--app-foreground)">{reply.response_number || notAvailable}</dd>
                        </div>
                        <div className="rounded-lg border border-(--app-border) bg-(--app-surface-muted) p-4 sm:col-span-2">
                            <dt className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.cases.applicant_full_name')}</dt>
                            <dd className="mt-1 text-sm font-semibold text-(--app-foreground)">{reply.applicant_name || notAvailable}</dd>
                        </div>
                    </dl>
                </Card>

                <Card>
                    <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.respondent.response_of_response_description')}</h2>
                    <div className="mt-3 whitespace-pre-line text-sm text-(--app-foreground)">{reply.description}</div>
                </Card>

                <Card className="space-y-4">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.cases.attachment')}</h2>
                        <a href={downloadUrl} className={buttonClassName({ variant: 'outline', size: 'sm' })}><DownloadIcon className="h-4 w-4" />{t('cm.respondent.download')}</a>
                    </div>
                    <iframe src={route('court.respondent.response-replies.download', { reply: reply.id, inline: 1 })} title={title}
                        className="min-h-[80vh] w-full rounded-lg border border-(--app-border) bg-white" />
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
