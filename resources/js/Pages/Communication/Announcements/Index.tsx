import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { announceTotalsChanged, communicationApi } from '@/lib/communication/api';
import { channels, connectRealtime, listen } from '@/lib/communication/realtime';
import { dateTime, tr } from '@/lib/communication/format';
import PriorityMark from '@/Components/Communication/PriorityMark';
import { CheckReadIcon, MegaphoneIcon, PaperclipIcon, PlusIcon } from '@/Components/NavIcons';
import type { PageProps } from '@/types';
import type { AnnouncementPage, AnnouncementStatus, AnnouncementSummary } from '@/types/communication';

type Props = PageProps<{
    feed: AnnouncementPage;
    managed: AnnouncementPage | null;
    can: { create: boolean; manage: boolean };
}>;

type FeedFilter = 'all' | 'unread' | 'acknowledge';
const STATUSES: (AnnouncementStatus | null)[] = [null, 'DRAFT', 'SCHEDULED', 'PUBLISHED', 'EXPIRED', 'CANCELLED'];

export default function AnnouncementsIndex({ feed: initialFeed, managed: initialManaged, can }: Props): JSX.Element {
    const { t } = useLocale();
    const [tab, setTab] = useState<'feed' | 'manage'>(() => (typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tab') === 'manage' && can.manage ? 'manage' : 'feed'));
    const [filter, setFilter] = useState<FeedFilter>('all');
    const [feed, setFeed] = useState(initialFeed);
    const [status, setStatus] = useState<AnnouncementStatus | null>(null);
    const [managed, setManaged] = useState<AnnouncementPage | null>(initialManaged);
    const [loading, setLoading] = useState(false);
    const firstFeed = useRef(true);
    const firstManaged = useRef(true);

    const reloadFeed = useCallback(() => {
        setLoading(true);
        communicationApi.announcementFeed(filter).then(setFeed).catch(() => undefined).finally(() => setLoading(false));
    }, [filter]);

    useEffect(() => {
        if (firstFeed.current) { firstFeed.current = false; return; }
        reloadFeed();
    }, [reloadFeed]);

    useEffect(() => {
        if (firstManaged.current) { firstManaged.current = false; return; }
        setLoading(true);
        communicationApi.managedAnnouncements(status).then(setManaged).catch(() => undefined).finally(() => setLoading(false));
    }, [status]);

    // New announcements arrive live when the socket is up; the totals badge follows.
    useEffect(() => {
        let stop = () => {};
        void connectRealtime().then((ok) => {
            if (ok) stop = listen(channels.announcements, { 'announcement.published': () => { reloadFeed(); announceTotalsChanged(); } });
        });
        return () => stop();
    }, [reloadFeed]);

    const more = async () => {
        setLoading(true);
        try {
            if (tab === 'feed' && feed.next_cursor) {
                const next = await communicationApi.announcementFeed(filter, feed.next_cursor);
                setFeed((current) => ({ data: [...current.data, ...next.data], next_cursor: next.next_cursor }));
            } else if (tab === 'manage' && managed?.next_cursor) {
                const next = await communicationApi.managedAnnouncements(status, managed.next_cursor);
                setManaged((current) => current && { data: [...current.data, ...next.data], next_cursor: next.next_cursor });
            }
        } finally {
            setLoading(false);
        }
    };

    const actions = can.create ? (
        <Link href={route('communication.announcements.create')} className={buttonClassName({ variant: 'primary', className: 'gap-1.5' })}>
            <PlusIcon className="h-4 w-4" aria-hidden="true" />{t('communication.announcements.create')}
        </Link>
    ) : null;

    const rows = tab === 'feed' ? feed : managed;
    const emptyText = tab === 'manage' ? t('communication.announcements.emptyManage')
        : filter === 'unread' ? t('communication.announcements.emptyUnread')
            : filter === 'acknowledge' ? t('communication.announcements.emptyAcknowledge') : t('communication.announcements.empty');

    return (
        <AuthenticatedLayout mode="communication" header={<PageHeader title={t('communication.announcements.title')} description={t('communication.announcements.description')} actions={actions} />}>
            <Head title={t('communication.announcements.title')} />

            <section className="overflow-hidden rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <div className="flex flex-col gap-3 border-b border-[color:var(--app-border)] p-3 md:flex-row md:items-center">
                    {can.manage && (
                        <div role="tablist" aria-label={t('communication.announcements.title')} className="flex rounded-control bg-[color:var(--app-surface-muted)] p-0.5">
                            {(['feed', 'manage'] as const).map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === value}
                                    onClick={() => setTab(value)}
                                    className={`h-8 rounded-[calc(var(--radius-control)-2px)] px-3 text-xs font-medium ${tab === value ? 'bg-[color:var(--app-surface)] text-[color:var(--app-foreground)] shadow-sm' : 'text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]'}`}
                                >
                                    {t(`communication.announcements.tabs.${value}`)}
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="flex flex-wrap gap-1 md:ms-auto">
                        {tab === 'feed'
                            ? (['all', 'unread', 'acknowledge'] as const).map((value) => (
                                <Chip key={value} active={filter === value} onClick={() => setFilter(value)}>{t(`communication.announcements.filters.${value}`)}</Chip>
                            ))
                            : STATUSES.map((value) => (
                                <Chip key={value ?? 'all'} active={status === value} onClick={() => setStatus(value)}>
                                    {value ? t(`communication.announcements.statuses.${value}`) : t('communication.announcements.statusFilter.all')}
                                </Chip>
                            ))}
                    </div>
                </div>

                <div aria-busy={loading}>
                    {!rows || rows.data.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-6 py-14 text-center">
                            <MegaphoneIcon className="h-6 w-6 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                            <p className="max-w-sm text-sm text-[color:var(--app-muted-foreground)]">{emptyText}</p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-[color:var(--app-border)]">
                            {rows.data.map((item) => <Row key={item.id} item={item} manage={tab === 'manage'} />)}
                        </ul>
                    )}
                    {rows?.next_cursor && (
                        <div className="border-t border-[color:var(--app-border)] p-3">
                            <button type="button" disabled={loading} onClick={() => void more()} className="h-8 w-full rounded-control text-xs font-medium text-[color:var(--color-primary)] hover:bg-[color:var(--app-surface-muted)] disabled:opacity-50">
                                {t('communication.announcements.loadMore')}
                            </button>
                        </div>
                    )}
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }): JSX.Element {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={`h-7 rounded-full px-3 text-xs font-medium transition-colors ${active ? 'bg-[color:var(--app-foreground)] text-[color:var(--app-background)]' : 'text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)]'}`}
        >
            {children}
        </button>
    );
}

function Row({ item, manage }: { item: AnnouncementSummary; manage: boolean }): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const unread = !manage && !item.viewed;
    const when = manage && item.status === 'SCHEDULED' && item.publish_at
        ? tr(t('communication.announcements.scheduledFor'), { date: dateTime(item.publish_at, locale, system) })
        : item.published_at ? dateTime(item.published_at, locale, system) : item.updated_at ? dateTime(item.updated_at, locale, system) : '';

    return (
        <li>
            <Link href={route('communication.announcements.show', item.id)} className="flex gap-3 px-4 py-3.5 hover:bg-[color:var(--app-surface-muted)]">
                <span className="mt-0.5 shrink-0"><PriorityMark priority={item.priority} withLabel={false} /></span>
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <span className={`text-sm ${unread ? 'font-semibold' : 'font-medium'} text-[color:var(--app-foreground)]`}>{item.title}</span>
                        {item.priority !== 'NORMAL' && <PriorityMark priority={item.priority} withLabel />}
                        {manage && <StatusPill status={item.status} />}
                    </span>
                    {item.excerpt && <span className="mt-0.5 line-clamp-2 block text-xs text-[color:var(--app-muted-foreground)]">{item.excerpt}</span>}
                    <span className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-[color:var(--app-muted-foreground)]">
                        <span className="tabular-nums">{when}</span>
                        {item.has_attachments && <span className="inline-flex items-center gap-1"><PaperclipIcon className="h-3 w-3" aria-hidden="true" />{t('communication.announcements.files')}</span>}
                        {!manage && item.acknowledgement_required && (item.acknowledged
                            ? <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-300"><CheckReadIcon className="h-3.5 w-3.5" aria-hidden="true" />{t('communication.announcements.acknowledged')}</span>
                            : <span className="font-medium text-amber-700 dark:text-amber-300">{t('communication.announcements.needsAck')}</span>)}
                        {manage && item.target_count != null && <span>{tr(t('communication.announcements.reached'), { n: item.target_count })}</span>}
                        {manage && item.acknowledgement_required && item.acknowledged_count != null && item.target_count != null && (
                            <span>{tr(t('communication.announcements.acknowledgedCount'), { n: item.acknowledged_count, total: item.target_count })}</span>
                        )}
                    </span>
                </span>
                {unread && <span aria-label={t('communication.announcements.unread')} className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-[color:var(--color-primary)]" />}
            </Link>
        </li>
    );
}

export function StatusPill({ status }: { status: AnnouncementStatus }): JSX.Element {
    const { t } = useLocale();
    const tone: Record<AnnouncementStatus, string> = {
        DRAFT: 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]',
        SCHEDULED: 'bg-[color:var(--tone-info-bg,#eff8ff)] text-[color:var(--color-primary)]',
        PUBLISHED: 'bg-[color:var(--tone-success-bg,#ecfdf3)] text-emerald-800 dark:text-emerald-200',
        EXPIRED: 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]',
        CANCELLED: 'bg-[color:var(--tone-danger-bg,#fef3f2)] text-[color:var(--color-danger,#b42318)]',
    };
    return <span className={`inline-flex h-5 items-center rounded-full px-2 text-[10px] font-semibold uppercase tracking-wide ${tone[status]}`}>{t(`communication.announcements.statuses.${status}`)}</span>;
}
