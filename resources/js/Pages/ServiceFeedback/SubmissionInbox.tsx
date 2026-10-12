import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PaginatorLinks, { type PaginatorLink } from '@/Components/PaginatorLinks';
import { FeedbackStatusBadge } from '@/Components/ServiceFeedback/feedbackUi';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link } from '@inertiajs/react';

type Entry = {
    id: string; reference: string | null; type: string; status: string;
    message: string; created_at: string | null; employee: string | null;
    employee_number: string | null; organization: string | null;
};

type Props = {
    type: 'suggestion' | 'complaint';
    entries: { data: Entry[]; links: PaginatorLink[]; total: number };
    counts: Record<string, number>;
};

export default function SubmissionInbox({ type, entries, counts }: Props) {
    const { t } = useLocale();
    const title = t(type === 'complaint' ? 'serviceFeedback.typeComplaint' : 'serviceFeedback.typeSuggestion');

    return <AuthenticatedLayout>
        <Head title={title} />
        <div className="space-y-5">
            <header>
                <h1 className="text-2xl font-semibold text-[color:var(--app-foreground)]">{title}</h1>
                <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.submissionInboxIntro')}</p>
            </header>
            <div className="grid gap-3 sm:grid-cols-3" aria-label={t('serviceFeedback.submissionCounts')}>
                {(['pending', 'reviewed', 'resolved'] as const).map((status) => <div key={status} className="rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4">
                    <p className="text-sm text-[color:var(--app-muted-foreground)]">{t(`serviceFeedback.status${status[0].toUpperCase()}${status.slice(1)}`)}</p>
                    <p className="mt-2 text-2xl font-semibold tabular-nums">{Number(counts[status] ?? 0).toLocaleString()}</p>
                </div>)}
            </div>
            <section className="overflow-hidden rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <h2 className="sr-only">{title}</h2>
                {entries.data.length === 0 ? <p className="p-8 text-center text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.noSubmissions')}</p>
                    : <ul className="divide-y divide-[color:var(--app-border)]">{entries.data.map((entry) => <li key={entry.id}>
                        <Link href={route('service-submissions.show', { type, submission: entry.id })} className="block p-4 hover:bg-[color:var(--app-surface-muted)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[color:var(--color-primary)]">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-semibold">{entry.employee ?? entry.employee_number ?? '—'}</span>
                                <FeedbackStatusBadge status={entry.status} />
                            </div>
                            <p className="mt-1 line-clamp-2 text-sm text-[color:var(--app-muted-foreground)]">{entry.message}</p>
                            <p className="mt-2 text-xs text-[color:var(--app-muted-foreground)]">{entry.reference && <span className="mr-3 font-mono">{entry.reference}</span>}{entry.organization} · <LocalizedDateDisplay value={entry.created_at} withTime /></p>
                        </Link>
                    </li>)}</ul>}
            </section>
            <PaginatorLinks links={entries.links} label={t('serviceFeedback.pagination')} />
        </div>
    </AuthenticatedLayout>;
}
