import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ListPagination, TableCard, badgeClassName, buttonClassName, type LaravelPageMeta } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import type { ApplicantNotificationsProps } from '@/court/types/applicant';

type Kind = 'hearing' | 'message' | 'respondent_view' | 'status';
const PAGE_PARAM: Record<Kind, string> = { hearing: 'hearings_page', message: 'messages_page', respondent_view: 'respondent_views_page', status: 'status_page' };

function currentQuery(): Record<string, string> {
    return Object.fromEntries(new URLSearchParams(window.location.search));
}

/**
 * What changed on the applicant's cases and was not seen yet (the Blade
 * applicant/notifications/index), in four lists each paged on its own:
 * upcoming hearings, court messages, respondents who opened a case, and
 * status changes. An item is marked seen on its own; the header marks all.
 * Case links are plain links, so they open whichever kind of page the case is.
 */
export default function ApplicantNotifications({ hearings, messages, respondentViews, statusChanges }: ApplicantNotificationsProps) {
    const { t } = useLocale();
    const [busy, setBusy] = useState<string | null>(null);

    const seen = (type: Kind, sourceId: number) => {
        const key = `${type}:${sourceId}`;
        router.post(route('court.applicant.notifications.markOne'), { type, sourceId }, { preserveScroll: true, onStart: () => setBusy(key), onFinish: () => setBusy(null) });
    };
    const markAll = () => router.post(route('court.applicant.notifications.markAll'), {}, { preserveScroll: true, onStart: () => setBusy('all'), onFinish: () => setBusy(null) });
    const page = (type: Kind) => (number: number) => router.get(route('court.applicant.notifications.index'), { ...currentQuery(), [PAGE_PARAM[type]]: number }, { preserveScroll: true, preserveState: true });

    const section = (type: Kind, title: string, meta: LaravelPageMeta, empty: string, rows: { id: number; caseId: number; body: ReactNode }[]) => (
        <TableCard title={<span className="flex items-center gap-2">{title} <span className={badgeClassName('neutral')}>{formatNumber(meta.total)}</span></span>}>
            {rows.length === 0 ? <p className="px-4 py-6 text-center text-sm text-(--app-muted-foreground)">{empty}</p> : (
                <ul className="divide-y divide-(--app-border)">
                    {rows.map((row) => (
                        <li key={row.id} className="flex items-center justify-between gap-4 px-4 py-3">
                            <div className="min-w-0 flex-1 text-sm">{row.body}</div>
                            <button type="button" disabled={busy !== null} onClick={() => seen(type, row.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.app.Seen')}</button>
                        </li>
                    ))}
                </ul>
            )}
            {meta.last_page > 1 && <div className="px-4 py-3"><ListPagination meta={meta} onPageChange={page(type)} bare /></div>}
        </TableCard>
    );
    const caseLink = (caseId: number, label: string) => <a href={route('court.applicant.cases.show', caseId)} className="font-medium text-(--app-foreground) hover:underline">{label}</a>;

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={t('cm.app.Notifications')}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('court.applicant.notifications.settings')} className={buttonClassName({ variant: 'ghost' })}>{t('cm.app.Settings')}</Link>
                        <button type="button" onClick={markAll} disabled={busy !== null} className={buttonClassName({ variant: 'outline' })}>{t('cm.app.Mark all as seen')}</button>
                    </div>
                )}
            />
        )}>
            <Head title={t('cm.app.Notifications')} />

            <div className="grid gap-4 xl:grid-cols-2">
                {section('hearing', t('cm.cases.hearings.title'), hearings, t('cm.app.admin_notifications.no_upcoming_hearings'), hearings.data.map((row) => ({
                    id: row.id, caseId: row.case_id,
                    body: <>
                        <div>{caseLink(row.case_id, row.case_number)} — <LocalizedDateDisplay value={row.at} withTime /></div>
                        <div className="text-xs text-(--app-muted-foreground)">{row.type ?? t('cm.app.Hearing')}{row.location && <> · {row.location}</>}</div>
                    </>,
                })))}
                {section('message', t('cm.app.admin_notifications.messages'), messages, t('cm.app.admin_notifications.no_new_messages'), messages.data.map((row) => ({
                    id: row.id, caseId: row.case_id,
                    body: <>
                        <div>{caseLink(row.case_id, row.case_number)}</div>
                        <div className="mt-0.5 text-xs text-(--app-muted-foreground)">
                            {row.url
                                ? <a href={row.url} target="_blank" rel="noreferrer" className="text-primary hover:underline">{t('cm.app.admin_notifications.view_letter_preview')}</a>
                                : row.excerpt}
                            {' · '}<time dateTime={row.at ?? undefined}>{row.relative}</time>
                        </div>
                    </>,
                })))}
                {section('respondent_view', t('cm.app.admin_notifications.respondent_views'), respondentViews, t('cm.app.admin_notifications.no_respondent_views'), respondentViews.data.map((row) => ({
                    id: row.id, caseId: row.case_id,
                    body: <>
                        <div>{caseLink(row.case_id, row.case_number)}</div>
                        <div className="text-xs text-(--app-muted-foreground)">
                            {t('cm.app.admin_notifications.respondent_viewed_case').replace(':name', row.respondent_name ?? t('cm.app.admin_notifications.respondent_default'))}
                            {' · '}<time dateTime={row.at ?? undefined}>{row.relative}</time>
                        </div>
                    </>,
                })))}
                {section('status', t('cm.app.admin_notifications.status_updates'), statusChanges, t('cm.app.admin_notifications.no_new_status_updates'), statusChanges.data.map((row) => ({
                    id: row.id, caseId: row.case_id,
                    body: <>
                        <div>{caseLink(row.case_id, row.case_number)}</div>
                        <div className="text-xs text-(--app-muted-foreground)">
                            {t('cm.app.admin_notifications.status_changed').replace(':from', row.from).replace(':to', row.to)}
                            {' · '}<time dateTime={row.at ?? undefined}>{row.relative}</time>
                        </div>
                    </>,
                })))}
            </div>
        </AuthenticatedLayout>
    );
}
