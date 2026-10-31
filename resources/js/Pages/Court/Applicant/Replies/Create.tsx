import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Card } from '@/Components/ui';
import { useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantReplyCreateProps } from '@/court/types/applicantCases';
import { ReplyContext, ReplyForm } from './Partials/ReplyParts';

/** A new reply ("response of response") to a respondent's accepted response: text and PDF. */
export default function ApplicantReplyCreate({ case: kase, response, applicantName }: ApplicantReplyCreateProps) {
    const { t } = useApplicantCaseText();
    const title = t('cm.respondent.give_response_of_response');
    const back = route('court.applicant.cases.respondentResponses.show', [kase.id, response.id]);

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('cm.respondent.response_of_response')} backHref={back} />}>
            <Head title={title} />
            <div className="mx-auto w-full max-w-5xl space-y-5">
                <Card><ReplyContext kase={kase} response={response} applicantName={applicantName} /></Card>
                <ReplyForm action={route('court.applicant.cases.respondentResponses.replies.store', [kase.id, response.id])} editing={false} initialDescription="" cancelHref={back} />
            </div>
        </AuthenticatedLayout>
    );
}
