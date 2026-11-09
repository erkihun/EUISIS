import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { BellIcon, Briefcase, ClockIcon, MailIcon, MessageSquareIcon, ScrollText, SearchIcon } from '@/Components/Icons';
import { Card, badgeClassName, buttonClassName, cx } from '@/Components/ui';
import { useRespondentText } from '@/court/i18n/useRespondentText';
import type { RespondentDashboardProps } from '@/court/types/respondent';
import { formatRelative } from '@/lib/relativeTime';
import { useStatusLabels } from './Partials/status';

type Panel = 'recent-cases' | 'letters' | 'response-replies' | 'notifications';

/**
 * The respondent portal's home: counts of the cases opened, responses given and unseen notifications, the
 * quick links, and one panel at a time of the recent cases, the approved letters of those cases, the
 * applicants' accepted replies to this person's responses, and the notifications the Blade header bell listed
 * (the EUISIS shell shows no bell on the portals), each of which can be marked read.
 */
export default function RespondentDashboard({ stats, recentCases, letters, responseReplies, notifications }: RespondentDashboardProps) {
    const { t, locale } = useRespondentText();
    const { caseStatus } = useStatusLabels();
    const [panel, setPanel] = useState<Panel>('recent-cases');
    const [busy, setBusy] = useState<string | null>(null);
    const notAvailable = t('cm.cases.labels.not_available');

    const toggle = (id: Panel, icon: ReactNode, label: string, count?: number) => (
        <button
            type="button"
            onClick={() => setPanel(id)}
            aria-pressed={panel === id}
            className={cx(
                'flex w-full items-center gap-3 rounded-lg border px-3 py-2.5 text-left text-sm font-medium transition-colors',
                panel === id
                    ? 'border-primary bg-(--app-surface-muted) text-(--app-foreground)'
                    : 'border-(--app-border) text-(--app-foreground) hover:bg-(--app-surface-muted)',
            )}
        >
            {icon}
            <span className="min-w-0 flex-1">{label}</span>
            {count !== undefined && <span className={badgeClassName('info')}>{count}</span>}
        </button>
    );

    const panelHeader = (eyebrow: string, heading: string, action: ReactNode) => (
        <div className="mb-4 flex items-center justify-between gap-3">
            <div>
                <div className="text-xs font-semibold uppercase tracking-wider text-(--app-muted-foreground)">{eyebrow}</div>
                <h2 className="text-base font-semibold text-(--app-foreground)">{heading}</h2>
            </div>
            {action}
        </div>
    );

    // Marking read returns to this page (back()) with a flash, as the Blade header bell's forms did.
    const markRead = (type: string, sourceId: number) => {
        setBusy(`${type}-${sourceId}`);
        router.post(route('court.respondent.notifications.markOne'), { type, sourceId }, { preserveScroll: true, onFinish: () => setBusy(null) });
    };
    const markAllRead = () => {
        setBusy('all');
        router.post(route('court.respondent.notifications.markAll'), {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    const empty = (text: string) => (
        <p className="rounded-lg border border-dashed border-(--app-border) px-4 py-8 text-center text-sm text-(--app-muted-foreground)">{text}</p>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={t('cm.respondent.dashboard_title')} description={t('cm.respondent.welcome_back')} />}>
            <Head title={t('cm.respondent.dashboard')} />

            <div className="space-y-6">
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label={t('cm.respondent.my_cases')} value={stats.cases} tone="primary" icon={<Briefcase className="h-4 w-4" />} />
                    <StatCard label={t('cm.respondent.my_responses')} value={stats.responses} tone="success" icon={<MessageSquareIcon className="h-4 w-4" />} />
                    <StatCard label={t('cm.respondent.notifications')} value={stats.notifications} tone="warning" icon={<BellIcon className="h-4 w-4" />} />
                </div>

                <div className="grid gap-6 lg:grid-cols-[3fr_9fr]">
                    <Card className="space-y-3 self-start">
                        <div>
                            <div className="text-xs font-semibold uppercase tracking-wider text-(--app-muted-foreground)">{t('cm.respondent.quick_actions')}</div>
                            <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.respondent.dashboard')}</h2>
                        </div>
                        <div className="space-y-2">
                            <Link href={route('court.respondent.case.search')} className={buttonClassName({ className: 'w-full justify-start' })}>
                                <SearchIcon className="h-4 w-4" />{t('cm.respondent.find_case')}
                            </Link>
                            <Link href={route('court.respondent.responses.index')} className={buttonClassName({ variant: 'outline', className: 'w-full justify-start' })}>
                                <MessageSquareIcon className="h-4 w-4" />{t('cm.respondent.my_responses')}
                            </Link>
                            <Link href={route('court.respondent.cases.my')} className={buttonClassName({ variant: 'outline', className: 'w-full justify-start' })}>
                                <Briefcase className="h-4 w-4" />{t('cm.respondent.my_cases')}
                            </Link>
                        </div>
                        <div className="space-y-2 border-t border-(--app-border) pt-3">
                            {toggle('recent-cases', <ClockIcon className="h-4 w-4 shrink-0" />, t('cm.respondent.recent_cases'))}
                            {toggle('letters', <MailIcon className="h-4 w-4 shrink-0" />, t('cm.respondent.letters'))}
                            {toggle('response-replies', <ScrollText className="h-4 w-4 shrink-0" />, t('cm.respondent.response_of_response'), stats.response_replies)}
                            {toggle('notifications', <BellIcon className="h-4 w-4 shrink-0" />, t('cm.respondent.notifications'), notifications.count)}
                        </div>
                    </Card>

                    <Card>
                        {panel === 'recent-cases' && (
                            <section aria-label={t('cm.respondent.recent_cases')}>
                                {panelHeader(t('cm.respondent.my_cases'), t('cm.respondent.case_found'), (
                                    <Link href={route('court.respondent.cases.my')} className="text-sm font-medium text-primary hover:underline">{t('cm.respondent.view_case_details')}</Link>
                                ))}
                                {recentCases.length === 0 ? empty(t('cm.respondent.no_notifications')) : (
                                    <ul className="space-y-2.5">
                                        {recentCases.map((item) => (
                                            <li key={item.case_number} className="flex items-start justify-between gap-3 rounded-lg border border-(--app-border) p-3">
                                                <div className="min-w-0">
                                                    <div className="font-mono text-sm font-semibold text-(--app-foreground)">{item.case_number}</div>
                                                    <div className="truncate text-xs text-(--app-muted-foreground)">{item.title || '-'}</div>
                                                    <div className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.respondent.status_label')}: {caseStatus(item.status)}</div>
                                                </div>
                                                <Link href={item.show_url} className="shrink-0 text-sm font-medium text-primary hover:underline">{t('cm.respondent.view_case_details')}</Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        )}

                        {panel === 'letters' && (
                            <section aria-label={t('cm.respondent.letters')}>
                                {panelHeader(t('cm.respondent.letters'), t('cm.respondent.recent_approved_letters'), (
                                    <button type="button" onClick={() => setPanel('recent-cases')} className="text-sm font-medium text-primary hover:underline">{t('cm.respondent.view_case_details')}</button>
                                ))}
                                {letters.length === 0 ? empty(t('cm.respondent.no_letters_available')) : (
                                    <ul className="space-y-2.5">
                                        {letters.map((letter) => (
                                            <li key={letter.id} className="flex items-start justify-between gap-3 rounded-lg border border-(--app-border) p-3">
                                                <div className="min-w-0 space-y-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="text-sm font-semibold text-(--app-foreground)">{letter.subject || letter.template_title || t('cm.respondent.letter')}</span>
                                                        <span className={badgeClassName('success')}>{t('cm.respondent.approved')}</span>
                                                    </div>
                                                    <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-(--app-muted-foreground)">
                                                        <span>{t('cm.respondent.case_label')}: {letter.case_number || '-'}</span>
                                                        <span>{t('cm.respondent.reference_label')}: {letter.reference_number || '-'}</span>
                                                        <span>{t('cm.respondent.template_label')}: {letter.template_title || '-'}</span>
                                                        <span>{t('cm.respondent.author_label')}: {letter.author_name || '-'}</span>
                                                    </div>
                                                    <LocalizedDateDisplay value={letter.created_at} withTime className="text-xs text-(--app-muted-foreground)" />
                                                </div>
                                                <a href={letter.preview_url} target="_blank" rel="noopener noreferrer" className="shrink-0 text-sm font-medium text-primary hover:underline">{t('cm.respondent.view_details')}</a>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        )}

                        {panel === 'response-replies' && (
                            <section aria-label={t('cm.respondent.response_of_response')}>
                                {panelHeader(t('cm.respondent.response_of_response'), t('cm.respondent.applicant_response_replies'), (
                                    <Link href={route('court.respondent.response-replies.index')} className="text-sm font-medium text-primary hover:underline">{t('cm.respondent.view_all')}</Link>
                                ))}
                                {responseReplies.length === 0 ? empty(t('cm.respondent.no_applicant_response_replies')) : (
                                    <ul className="space-y-2.5">
                                        {responseReplies.map((reply) => (
                                            <li key={reply.id} className="flex items-start justify-between gap-3 rounded-lg border border-(--app-border) p-3">
                                                <div className="min-w-0 space-y-1">
                                                    <div className="font-mono text-sm font-semibold text-(--app-foreground)">{reply.case_number || '-'}</div>
                                                    <div className="text-xs text-(--app-muted-foreground)">{t('cm.respondent.response_number_label')}: {reply.response_number || notAvailable}</div>
                                                    <div className="text-xs text-(--app-muted-foreground)">{t('cm.cases.applicant_full_name')}: {reply.applicant_name || notAvailable}</div>
                                                </div>
                                                <Link href={route('court.respondent.response-replies.show', reply.id)} className="shrink-0 text-sm font-medium text-primary hover:underline">{t('cm.respondent.view_details')}</Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        )}

                        {panel === 'notifications' && (
                            <section aria-label={t('cm.respondent.notifications')}>
                                {panelHeader(t('cm.respondent.notifications'), `${notifications.count} ${t('cm.respondent.notifications')}`, (
                                    <button type="button" onClick={markAllRead} disabled={busy !== null} className="text-sm font-medium text-primary hover:underline disabled:opacity-60">
                                        {t('cm.respondent.mark_all_as_read')}
                                    </button>
                                ))}
                                {notifications.items.length === 0 ? empty(t('cm.respondent.no_notifications')) : (
                                    <ul className="space-y-2.5">
                                        {notifications.items.map((item) => (
                                            <li key={`${item.type}-${item.source_id}`} className="flex items-start justify-between gap-3 rounded-lg border border-(--app-border) p-3">
                                                <div className="min-w-0 space-y-1">
                                                    <span className={badgeClassName('neutral')}>{t(`cm.app.admin_notifications.types.${item.type}`)}</span>
                                                    <div className="font-mono text-sm font-semibold text-(--app-foreground)">{item.case_number || '—'}</div>
                                                    <div className="truncate text-xs text-(--app-muted-foreground)">{item.title || t('cm.respondent.view_case_details')}</div>
                                                    <div className="text-xs text-(--app-muted-foreground)">{formatRelative(item.at, locale) ?? '—'}</div>
                                                </div>
                                                <div className="flex shrink-0 flex-col items-end gap-2">
                                                    {item.show_url && <Link href={item.show_url} className="text-sm font-medium text-primary hover:underline">{t('cm.respondent.view_case_details')}</Link>}
                                                    <button type="button" onClick={() => markRead(item.type, item.source_id)} disabled={busy !== null}
                                                        className="text-xs text-(--app-muted-foreground) hover:text-primary disabled:opacity-60">
                                                        {t('cm.respondent.mark_as_read')}
                                                    </button>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        )}
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
