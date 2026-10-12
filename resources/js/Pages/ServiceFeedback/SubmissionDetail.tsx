import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { FeedbackStatusBadge } from '@/Components/ServiceFeedback/feedbackUi';
import { useLocale } from '@/hooks/useLocale';
import { controlClassName, buttonClassName } from '@euisis/ui';
import { Head, Link, useForm } from '@inertiajs/react';

type Entry = {
    id: string; reference: string | null; status: string; message: string;
    created_at: string | null; employee: string | null; employee_number: string | null;
    organization: string | null; organization_unit: string | null; position: string | null;
    client_name: string | null; client_contact: string | null;
    incident_date: string | null; incident_location: string | null;
    review_note: string | null;
    attachments: { id: string; name: string; size: number }[];
};

type Props = { type: 'suggestion' | 'complaint'; entry: Entry; canReview: boolean };

export default function SubmissionDetail({ type, entry, canReview }: Props) {
    const { t } = useLocale();
    const title = t(type === 'complaint' ? 'serviceFeedback.typeComplaint' : 'serviceFeedback.typeSuggestion');
    const { data, setData, post, processing, errors } = useForm({
        status: entry.status === 'resolved' ? 'resolved' : 'reviewed',
        review_note: entry.review_note ?? '',
    });

    return <AuthenticatedLayout>
        <Head title={title} />
        <div className="mx-auto max-w-3xl space-y-5">
            <Link href={route(type === 'complaint' ? 'service-submissions.complaints' : 'service-submissions.suggestions')} className="inline-flex min-h-11 items-center text-sm font-semibold text-[color:var(--color-primary)] underline underline-offset-4">{t('serviceFeedback.backToInbox')}</Link>
            <header className="flex flex-wrap items-center justify-between gap-3">
                <div><h1 className="text-2xl font-semibold">{title}</h1>{entry.reference && <p className="mt-1 font-mono text-sm">{entry.reference}</p>}</div>
                <FeedbackStatusBadge status={entry.status} />
            </header>
            <section className="space-y-4 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                <h2 className="text-lg font-semibold">{t('serviceFeedback.submissionDetails')}</h2>
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.filterEmployee')}</dt><dd>{entry.employee ?? entry.employee_number ?? '—'}</dd></div>
                    <div><dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.submittedDate')}</dt><dd><LocalizedDateDisplay value={entry.created_at} withTime /></dd></div>
                    <div><dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.filterOrganization')}</dt><dd>{entry.organization ?? '—'}</dd></div>
                    <div><dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.serviceUnit')}</dt><dd>{entry.organization_unit ?? '—'}</dd></div>
                    <div><dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.clientName')}</dt><dd>{entry.client_name ?? t('serviceFeedback.anonymousClient')}</dd></div>
                    <div><dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.clientContact')}</dt><dd>{entry.client_contact ?? '—'}</dd></div>
                    {type === 'complaint' && <><div><dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.incidentDate')}</dt><dd><LocalizedDateDisplay value={entry.incident_date} /></dd></div>
                    <div><dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.incidentLocation')}</dt><dd>{entry.incident_location ?? '—'}</dd></div></>}
                </dl>
                <div className="border-t border-[color:var(--app-border)] pt-4"><h3 className="text-sm font-semibold">{t(type === 'complaint' ? 'serviceFeedback.complaintDescription' : 'serviceFeedback.suggestionText')}</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm">{entry.message}</p></div>
            </section>
            {entry.attachments.length > 0 && <section className="rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5"><h2 className="font-semibold">{t('serviceFeedback.attachments')}</h2>
                <ul className="mt-3 space-y-2">{entry.attachments.map((file) => <li key={file.id}><a href={route('service-submissions.attachments.download', { type, submission: entry.id, attachment: file.id })} className="text-sm font-medium text-[color:var(--color-primary)] underline underline-offset-4">{file.name} ({Math.ceil(file.size / 1024)} KB)</a></li>)}</ul>
            </section>}
            {canReview && <form onSubmit={(event) => { event.preventDefault(); post(route('service-submissions.review', { type, submission: entry.id })); }} className="space-y-4 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                <h2 className="font-semibold">{t('serviceFeedback.reviewSubmission')}</h2>
                <div><label htmlFor="review-status" className="block text-sm font-medium">{t('serviceFeedback.filterStatus')}</label><select id="review-status" value={data.status} onChange={(event) => setData('status', event.target.value)} className={`${controlClassName} mt-1.5`}><option value="reviewed">{t('serviceFeedback.statusReviewed')}</option><option value="resolved">{t('serviceFeedback.statusResolved')}</option></select>{errors.status && <p role="alert" className="text-sm text-red-600">{errors.status}</p>}</div>
                <div><label htmlFor="review-note" className="block text-sm font-medium">{t('serviceFeedback.reviewNote')}</label><textarea id="review-note" rows={4} maxLength={2000} value={data.review_note} onChange={(event) => setData('review_note', event.target.value)} className={`${controlClassName} mt-1.5`} />{errors.review_note && <p role="alert" className="text-sm text-red-600">{errors.review_note}</p>}</div>
                <button type="submit" disabled={processing} className={buttonClassName({ variant: 'primary' })}>{t('serviceFeedback.saveReview')}</button>
            </form>}
        </div>
    </AuthenticatedLayout>;
}
