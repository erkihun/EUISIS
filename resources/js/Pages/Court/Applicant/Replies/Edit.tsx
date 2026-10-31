import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Card } from '@/Components/ui';
import { useApplicantCaseText } from '@/court/i18n/useApplicantCaseText';
import type { ApplicantReplyEditProps } from '@/court/types/applicantCases';
import { ReplyContext, ReplyForm } from './Partials/ReplyParts';

/** Changing a reply that is not yet accepted: the text, and optionally a new PDF. Saving sends it back for review. */
export default function ApplicantReplyEdit({ case: kase, response, reply }: ApplicantReplyEditProps) {
    const { t } = useApplicantCaseText();
    const title = t('cm.respondent.edit_response_of_response');
    const showHref = route('court.applicant.cases.respondentResponses.replies.show', [kase.id, response.id, reply.id]);

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('cm.respondent.response_of_response')} backHref={showHref} />}>
            <Head title={title} />
            <div className="mx-auto w-full max-w-5xl space-y-5">
                <Card><ReplyContext kase={kase} response={response} applicantName={reply.applicant_name} /></Card>
                <ReplyForm action={route('court.applicant.cases.respondentResponses.replies.update', [kase.id, response.id, reply.id])} editing initialDescription={reply.description} cancelHref={showHref} />
            </div>
        </AuthenticatedLayout>
    );
}
