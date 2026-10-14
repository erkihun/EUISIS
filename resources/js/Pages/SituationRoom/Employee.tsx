import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Breadcrumb, fmt, useDateLabel, useNamer, viewQuery, type Filters, type Name, type ScopeProps } from '@/Components/SituationRoom/communityVoice/shared';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Card, EmptyState, StatusBadge, buttonClassName } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';

type Submission = {
    id: string;
    type: 'feedback' | 'suggestion' | 'complaint';
    status: string;
    resolved: boolean;
    submitted_at: string | null;
    resolved_at: string | null;
    reference: string | null;
    rating: number | null;
    category_en: string | null;
    category_am: string | null;
    organization_en: string | null;
    organization_am: string | null;
    service: string | null;
    subject: string | null;
    message: string | null;
};

type Props = {
    scope: ScopeProps;
    filters: Filters;
    employee: { id: string } & Name;
    submissions: { rows: Submission[]; total: number; page: number; per_page: number };
    can: { viewDetail: boolean };
};

const STATUS_BUCKET: Record<string, string> = { reviewed: 'under_review', accepted: 'resolved', not_adopted: 'resolved' };

/** The submission's own page, which applies identity and attachment grants itself. */
function submissionHref(row: Submission): string {
    return row.type === 'feedback'
        ? route('service-feedback.admin.show', row.id)
        : route('service-submissions.show', { type: row.type, submission: row.id });
}

/**
 * One employee's submissions recorded in the leader's scope. Submission text
 * appears only with situation_room.view_detail (and that view is audited);
 * submitter identity and attachments never appear here.
 */
export default function SituationRoomEmployee({ scope, filters, employee, submissions, can }: Props) {
    const { t, locale } = useLocale();
    const name = useNamer();
    const { date } = useDateLabel();
    const query = viewQuery(scope, filters);
    const pages = Math.max(1, Math.ceil(submissions.total / submissions.per_page));
    const local = (en: string | null, am: string | null) => (locale === 'am' ? am || en : en || am) ?? '—';
    const title = fmt(t('situationRoom.employeeSubmissionsTitle'), { name: name(employee) });
    const go = (page: number) => router.get(route('situation-room.employee', { employee: employee.id, ...query, page }), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('situationRoom.employeesIntro')} backHref={route('situation-room.employees', query)} />}>
            <Head title={title} />

            <div className="space-y-5">
                <Breadcrumb scope={scope} filters={filters} />
                {!can.viewDetail && <Alert tone="info">{t('situationRoom.narrativeHidden')}</Alert>}

                {submissions.rows.length === 0 ? <Card><EmptyState title={t('situationRoom.employeesEmpty')} /></Card> : (
                    <ul className="space-y-3">
                        {submissions.rows.map((row) => (
                            <li key={row.id}>
                                <Card className="p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge tone={row.type === 'complaint' ? 'warning' : row.type === 'suggestion' ? 'info' : 'neutral'}>{t(`situationRoom.type_${row.type}`)}</StatusBadge>
                                            <StatusBadge tone={row.resolved ? 'success' : 'neutral'}>{t(`situationRoom.status_${STATUS_BUCKET[row.status] ?? row.status}`)}</StatusBadge>
                                            {row.reference && <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{row.reference}</span>}
                                        </div>
                                        <Link href={submissionHref(row)} className="text-sm font-medium text-[color:var(--color-primary)] underline-offset-4 hover:underline">{t('situationRoom.openSubmission')}</Link>
                                    </div>
                                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div><dt className="text-[color:var(--app-muted-foreground)]">{t('situationRoom.submittedColumn')}</dt><dd>{date(row.submitted_at)}</dd></div>
                                        <div><dt className="text-[color:var(--app-muted-foreground)]">{t('situationRoom.recordedAtColumn')}</dt><dd>{local(row.organization_en, row.organization_am)}</dd></div>
                                        <div><dt className="text-[color:var(--app-muted-foreground)]">{t('situationRoom.categoryLabel')}</dt><dd>{row.category_en || row.category_am ? local(row.category_en, row.category_am) : t('situationRoom.uncategorized')}</dd></div>
                                        <div><dt className="text-[color:var(--app-muted-foreground)]">{t('situationRoom.serviceColumn')}</dt><dd>{row.service ?? '—'}</dd></div>
                                        {row.rating !== null && <div><dt className="text-[color:var(--app-muted-foreground)]">{t('situationRoom.ratingColumn')}</dt><dd>{fmt(t('situationRoom.starsLabel'), { stars: row.rating })}</dd></div>}
                                        {row.resolved_at && <div><dt className="text-[color:var(--app-muted-foreground)]">{t('situationRoom.resolvedColumn')}</dt><dd>{date(row.resolved_at)}</dd></div>}
                                    </dl>
                                    {can.viewDetail && (row.subject || row.message) && (
                                        <div className="mt-3 rounded-[var(--radius-card)] bg-[color:var(--app-surface-muted)] p-3 text-sm">
                                            {row.subject && <p className="font-medium">{row.subject}</p>}
                                            {row.message && <p className="mt-1 whitespace-pre-line break-words">{row.message}</p>}
                                        </div>
                                    )}
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}

                {pages > 1 && (
                    <nav className="flex items-center justify-between gap-3 text-sm" aria-label={fmt(t('situationRoom.pageOf'), { page: submissions.page, pages })}>
                        <button type="button" className={buttonClassName({ variant: 'outline', size: 'sm' })} disabled={submissions.page <= 1} onClick={() => go(submissions.page - 1)}>{t('situationRoom.previousPage')}</button>
                        <span>{fmt(t('situationRoom.pageOf'), { page: submissions.page, pages })}</span>
                        <button type="button" className={buttonClassName({ variant: 'outline', size: 'sm' })} disabled={submissions.page >= pages} onClick={() => go(submissions.page + 1)}>{t('situationRoom.nextPage')}</button>
                    </nav>
                )}

                <Alert tone="info">{t('situationRoom.neutralNote')}</Alert>
            </div>
        </AuthenticatedLayout>
    );
}
