import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { ListPagination, TableCard, badgeClassName, buttonClassName, type LaravelPageMeta } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/utils/displayFormat';
import type { NotificationsIndexProps } from '@/court/types/misc';

type Kind = 'message' | 'case' | 'hearing' | 'respondent_view';
const PAGE_PARAM: Record<Kind, string> = { message: 'msgs_page', case: 'cases_page', hearing: 'hearings_page', respondent_view: 'respondent_views_page' };

/**
 * Everything waiting for the person's attention, in four lists: applicant
 * messages, new unassigned cases, upcoming hearings on their cases, and
 * respondents who opened a case. Each item is marked seen on its own; the
 * header marks them all.
 */
export default function NotificationsIndex({ messages, cases, hearings, respondentViews }: NotificationsIndexProps) {
    const { t } = useLocale();
    const [busy, setBusy] = useState<string | null>(null);

    const seen = (type: Kind, sourceId: number) => {
        const key = `${type}:${sourceId}`;
        router.post(route('court.admin.notifications.markOne'), { type, sourceId }, { preserveScroll: true, onStart: () => setBusy(key), onFinish: () => setBusy(null) });
    };
    const page = (type: Kind) => (number: number) => router.get(route('court.admin.notifications.index'), { ...currentQuery(), [PAGE_PARAM[type]]: number }, { preserveScroll: true, preserveState: true });

    const section = (type: Kind, title: string, meta: LaravelPageMeta, empty: string, rows: { id: number; href: string; body: ReactNode }[]) => (
        <TableCard title={<span className="flex items-center gap-2">{title} <span className={badgeClassName('neutral')}>{formatNumber(meta.total)}</span></span>}>
            {rows.length === 0 ? <p className="px-4 py-6 text-center text-sm text-(--app-muted-foreground)">{empty}</p> : (
                <ul className="divide-y divide-(--app-border)">
                    {rows.map((row) => (
                        <li key={row.id} className="flex items-center justify-between gap-4 px-4 py-3">
                            <Link href={row.href} className="min-w-0 flex-1 text-sm">{row.body}</Link>
                            <button type="button" disabled={busy === `${type}:${row.id}`} onClick={() => seen(type, row.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.app.Seen')}</button>
                        </li>
                    ))}
                </ul>
            )}
            {meta.last_page > 1 && <div className="px-4 py-3"><ListPagination meta={meta} onPageChange={page(type)} bare /></div>}
        </TableCard>
    );

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={t('cm.app.admin_notifications.your_notifications')} description={t('cm.app.Notifications')}
                actions={<button type="button" onClick={() => router.post(route('court.admin.notifications.markAll'), {}, { preserveScroll: true })} className={buttonClassName({ variant: 'outline' })}>{t('cm.app.Mark all as seen')}</button>} />
        )}>
            <Head title={t('cm.app.Notifications')} />

            <div className="grid gap-4 xl:grid-cols-2">
                {section('message', t('cm.app.Applicant messages'), messages, t('cm.app.admin_notifications.no_new_messages'), messages.data.map((row) => ({
                    id: row.id, href: route('court.cases.show', row.case_id),
                    body: <><div className="font-medium text-(--app-foreground)">{row.case_number}</div><div className="truncate text-xs text-(--app-muted-foreground)">{row.body} · <LocalizedDateDisplay value={row.at} withTime /></div></>,
                })))}
                {section('case', t('cm.app.New cases'), cases, t('cm.app.admin_notifications.no_new_cases'), cases.data.map((row) => ({
                    id: row.id, href: route('court.cases.show', row.id),
                    body: <><div className="font-medium text-(--app-foreground)">{row.case_number}</div><div className="truncate text-xs text-(--app-muted-foreground)">{row.title} · <LocalizedDateDisplay value={row.at} withTime /></div></>,
                })))}
                {section('hearing', t('cm.app.Upcoming hearings'), hearings, t('cm.app.admin_notifications.no_upcoming_hearings'), hearings.data.map((row) => ({
                    id: row.id, href: route('court.cases.show', row.case_id),
                    body: <><div className="font-medium text-(--app-foreground)">{row.case_number} · <LocalizedDateDisplay value={row.at} withTime /></div><div className="text-xs text-(--app-muted-foreground)">{row.type ?? t('cm.app.Hearing')} · {row.location ?? '-'}</div></>,
                })))}
                {section('respondent_view', t('cm.app.admin_notifications.respondent_views'), respondentViews, t('cm.app.admin_notifications.no_respondent_views'), respondentViews.data.map((row) => ({
                    id: row.id, href: route('court.cases.show', row.case_id),
                    body: <><div className="font-medium text-(--app-foreground)">{row.case_number}</div><div className="truncate text-xs text-(--app-muted-foreground)">{t('cm.app.admin_notifications.respondent_viewed_case').replace(':name', row.respondent_name ?? t('cm.app.admin_notifications.respondent_default'))} · <LocalizedDateDisplay value={row.at} withTime /></div></>,
                })))}
            </div>
        </AuthenticatedLayout>
    );
}

function currentQuery(): Record<string, string> {
    return Object.fromEntries(new URLSearchParams(window.location.search));
}
