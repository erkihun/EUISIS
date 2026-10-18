import { Head, Link } from '@inertiajs/react';
import { useEffect, useState, type JSX, type ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { communicationApi } from '@/lib/communication/api';
import { listTime, tr } from '@/lib/communication/format';
import Avatar from '@/Components/Communication/Avatar';
import { ChatIcon, ChevronRight, HashIcon, MegaphoneIcon, PlusIcon, Users } from '@/Components/NavIcons';
import type { ConversationSummary, Person, Totals } from '@/types/communication';
import type { PageProps } from '@/types';

type AnnouncementRow = {
    id: string;
    title: string;
    priority: 'NORMAL' | 'IMPORTANT' | 'URGENT' | 'EMERGENCY';
    published_at: string | null;
    acknowledgement_required?: boolean;
    viewed?: boolean;
    acknowledged?: boolean;
};

type Props = PageProps<{
    totals: Totals;
    conversations: ConversationSummary[];
    channels: ConversationSummary[];
    contacts: (Person & { conversation_id: string | null })[];
    announcements: AnnouncementRow[];
    pendingAcknowledgements: AnnouncementRow[];
    can: { message: boolean; createGroup: boolean; browseChannels: boolean; createAnnouncement: boolean; tasks: boolean; moderate: boolean; investigations: boolean; settings: boolean };
}>;

const has = (name: string) => (route() as unknown as { has: (n: string) => boolean }).has(name);
const messagesUrl = (id?: string | null, type?: string) => (id ? route('communication.messages.show', id) : route('communication.messages.index') + (type ? `?type=${type}` : ''));

const PRIORITY_TONE: Record<AnnouncementRow['priority'], string> = {
    NORMAL: 'text-(--app-muted-foreground)',
    IMPORTANT: 'text-primary',
    URGENT: 'text-amber-700 dark:text-amber-300',
    EMERGENCY: 'text-(--color-danger,#b42318)',
};

export default function CommunicationIndex(props: Props): JSX.Element {
    const { conversations, channels, contacts, announcements, pendingAcknowledgements, can } = props;
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const [totals, setTotals] = useState<Totals>(props.totals);

    useEffect(() => {
        const refresh = () => communicationApi.summary().then(setTotals).catch(() => undefined);
        window.addEventListener('communication:totals-changed', refresh);
        return () => window.removeEventListener('communication:totals-changed', refresh);
    }, []);

    const unreadConversations = conversations.filter((c) => c.unread_count > 0 && !c.muted);
    const attention = totals.total + pendingAcknowledgements.length;
    const counts: { key: keyof Totals; label: string; href: string | null }[] = [
        { key: 'direct', label: t('communication.totals.direct'), href: messagesUrl(null, 'DIRECT') },
        { key: 'group', label: t('communication.totals.group'), href: messagesUrl(null, 'GROUP') },
        { key: 'channel', label: t('communication.totals.channel'), href: messagesUrl(null, 'CHANNEL') },
        { key: 'mentions', label: t('communication.totals.mentions'), href: messagesUrl() },
        { key: 'announcements', label: t('communication.totals.announcements'), href: has('communication.announcements.index') ? route('communication.announcements.index') : null },
    ];

    const actions = (
        <div className="flex flex-wrap gap-2">
            {can.message && (
                <Link href={`${route('communication.messages.index')}?compose=1`} className={buttonClassName({ variant: 'primary', className: 'gap-1.5' })}>
                    <PlusIcon className="h-4 w-4" aria-hidden="true" />{t('communication.dashboard.newMessage')}
                </Link>
            )}
            {can.createGroup && (
                <Link href={`${route('communication.messages.index')}?compose=group`} className={buttonClassName({ variant: 'secondary', className: 'gap-1.5' })}>
                    <Users className="h-4 w-4" aria-hidden="true" />{t('communication.dashboard.createGroup')}
                </Link>
            )}
            {can.browseChannels && has('communication.channels.index') && (
                <Link href={route('communication.channels.index')} className={buttonClassName({ variant: 'secondary', className: 'gap-1.5' })}>
                    <HashIcon className="h-4 w-4" aria-hidden="true" />{t('communication.dashboard.browseChannels')}
                </Link>
            )}
            {can.createAnnouncement && has('communication.announcements.create') && (
                <Link href={route('communication.announcements.create')} className={buttonClassName({ variant: 'secondary', className: 'gap-1.5' })}>
                    <MegaphoneIcon className="h-4 w-4" aria-hidden="true" />{t('communication.dashboard.createAnnouncement')}
                </Link>
            )}
        </div>
    );

    return (
        <AuthenticatedLayout mode="communication" header={<PageHeader title={t('communication.title')} description={t('communication.description')} actions={actions} />}>
            <Head title={t('communication.title')} />

            <div className="space-y-6">
                {/* What is unread, as one quiet line of counts that each open the right list. */}
                <nav aria-label={t('communication.totals.unread').replace(':n', '')} className="flex flex-wrap items-center gap-x-1 gap-y-2 rounded-panel border border-(--app-border) bg-(--app-surface) px-2 py-2">
                    {counts.map(({ key, label, href }) => {
                        const value = totals[key];
                        const content = (
                            <>
                                <span className={`tabular-nums text-sm font-semibold ${value > 0 ? 'text-(--app-foreground)' : 'text-(--app-muted-foreground)'}`}>{value}</span>
                                <span className="text-xs text-(--app-muted-foreground)">{label}</span>
                                {value > 0 && <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-primary" />}
                            </>
                        );
                        const cls = 'inline-flex h-9 items-center gap-2 rounded-control px-3';
                        return href
                            ? <Link key={key} href={href} className={`${cls} hover:bg-(--app-surface-muted)`}>{content}</Link>
                            : <span key={key} className={cls}>{content}</span>;
                    })}
                </nav>


                {pendingAcknowledgements.length > 0 && (
                    <Section title={t('communication.dashboard.pendingAcknowledgements')} count={pendingAcknowledgements.length} tone="warning">
                        <ul className="divide-y divide-(--app-border)">
                            {pendingAcknowledgements.map((item) => <AnnouncementLine key={item.id} item={item} locale={locale} system={system} />)}
                        </ul>
                    </Section>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                    <Section
                        title={t('communication.dashboard.activeConversations')}
                        count={conversations.length || undefined}
                        action={<Link href={messagesUrl()} className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">{t('communication.dashboard.openMessages')}<ChevronRight className="h-3.5 w-3.5" aria-hidden="true" /></Link>}
                    >
                        {conversations.length === 0 ? (
                            <Empty icon={<ChatIcon className="h-5 w-5" />} text={t('communication.dashboard.noConversations')}>
                                {can.message && <Link href={`${route('communication.messages.index')}?compose=1`} className="text-sm font-medium text-primary hover:underline">{t('communication.dashboard.newMessage')}</Link>}
                            </Empty>
                        ) : (
                            <ul className="divide-y divide-(--app-border)">
                                {[...unreadConversations, ...conversations.filter((c) => !unreadConversations.includes(c))].map((item) => (
                                    <li key={item.id}>
                                        <Link href={messagesUrl(item.id)} className="flex items-center gap-3 px-4 py-3 hover:bg-(--app-surface-muted)">
                                            <Avatar name={item.title} kind={item.type} userId={item.other_user?.id} hasPhoto={item.has_avatar} online={item.type === 'DIRECT' ? (item.other_user?.online ?? null) : null} />
                                            <span className="min-w-0 flex-1">
                                                <span className={`block truncate text-sm ${item.unread_count > 0 ? 'font-semibold' : 'font-medium'} text-(--app-foreground)`}>{item.title}</span>
                                                <span className="block truncate text-xs text-(--app-muted-foreground)">
                                                    {item.last_message?.deleted ? t('communication.list.deleted') : (item.last_message?.excerpt ?? (item.last_message?.has_attachments ? t('communication.list.attachment') : ''))}
                                                </span>
                                            </span>
                                            <span className="flex shrink-0 flex-col items-end gap-1">
                                                <span className="text-[11px] tabular-nums text-(--app-muted-foreground)">{listTime(item.last_message_at, locale, system)}</span>
                                                {item.unread_count > 0 && (
                                                    <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold tabular-nums text-white" aria-label={tr(t('communication.list.unread'), { n: item.unread_count })}>
                                                        {item.unread_count > 99 ? '99+' : item.unread_count}
                                                    </span>
                                                )}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <div className="space-y-6">
                        <Section title={t('communication.dashboard.recentContacts')}>
                            {contacts.length === 0 ? <Empty text={t('communication.dashboard.noContacts')} /> : (
                                <ul className="flex flex-wrap gap-x-2 gap-y-3 px-3 py-3">
                                    {contacts.map((person) => (
                                        <li key={person.id} className="w-18">
                                            <Link href={messagesUrl(person.conversation_id)} className="flex flex-col items-center gap-1.5 rounded-control px-1 py-1.5 text-center hover:bg-(--app-surface-muted)">
                                                <Avatar name={person.name} userId={person.id} hasPhoto={person.has_photo} online={person.online} />
                                                <span className="line-clamp-2 text-[11px] leading-tight text-(--app-foreground)">{person.name}</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>

                        <Section title={t('communication.dashboard.recentChannels')}>
                            {channels.length === 0 ? <Empty text={t('communication.dashboard.noChannels')} /> : (
                                <ul className="py-1">
                                    {channels.map((item) => (
                                        <li key={item.id}>
                                            <Link href={messagesUrl(item.id)} className="flex items-center gap-2.5 px-4 py-2 hover:bg-(--app-surface-muted)">
                                                <HashIcon className="h-4 w-4 shrink-0 text-(--app-muted-foreground)" aria-hidden="true" />
                                                <span className={`min-w-0 flex-1 truncate text-sm ${item.unread_count > 0 ? 'font-semibold' : ''} text-(--app-foreground)`}>{item.title}</span>
                                                {item.unread_count > 0 && <span className="text-[11px] font-semibold tabular-nums text-primary">{item.unread_count}</span>}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>

                        <Section title={t('communication.dashboard.recentAnnouncements')}>
                            {announcements.length === 0 ? <Empty text={t('communication.dashboard.noAnnouncements')} /> : (
                                <ul className="divide-y divide-(--app-border)">
                                    {announcements.map((item) => <AnnouncementLine key={item.id} item={item} locale={locale} system={system} />)}
                                </ul>
                            )}
                        </Section>
                    </div>
                </div>
                {attention === 0 && <p className="sr-only" role="status">{t('communication.dashboard.noPending')}</p>}
            </div>
        </AuthenticatedLayout>
    );
}

function Section({ title, count, action, tone, children }: { title: string; count?: number; action?: ReactNode; tone?: 'warning'; children: ReactNode }): JSX.Element {
    return (
        <section className={`overflow-hidden rounded-panel border bg-(--app-surface) ${tone === 'warning' ? 'border-(--tone-warning-border,#fedf89)' : 'border-(--app-border)'}`}>
            <div className={`flex items-center gap-2 border-b px-4 py-2.5 ${tone === 'warning' ? 'border-(--tone-warning-border,#fedf89) bg-(--tone-warning-bg,#fffaeb)' : 'border-(--app-border)'}`}>
                <h2 className="flex-1 text-sm font-semibold text-(--app-foreground)">
                    {title}
                    {count !== undefined && <span className="ms-2 tabular-nums font-normal text-(--app-muted-foreground)">{count}</span>}
                </h2>
                {action}
            </div>
            {children}
        </section>
    );
}

function Empty({ text, icon, children }: { text: string; icon?: ReactNode; children?: ReactNode }): JSX.Element {
    return (
        <div className="flex flex-col items-center gap-2 px-6 py-8 text-center">
            {icon && <span className="text-(--app-muted-foreground)" aria-hidden="true">{icon}</span>}
            <p className="text-sm text-(--app-muted-foreground)">{text}</p>
            {children}
        </div>
    );
}

function AnnouncementLine({ item, locale, system }: { item: AnnouncementRow; locale: string; system: ReturnType<typeof useCalendarSystem> }): JSX.Element {
    const { t } = useLocale();
    const href = has('communication.announcements.show') ? route('communication.announcements.show', item.id) : null;
    const body = (
        <>
            <MegaphoneIcon className={`mt-0.5 h-4 w-4 shrink-0 ${PRIORITY_TONE[item.priority]}`} aria-hidden="true" />
            <span className="min-w-0 flex-1">
                <span className={`block truncate text-sm ${item.viewed === false ? 'font-semibold' : 'font-medium'} text-(--app-foreground)`}>{item.title}</span>
                <span className="block text-xs text-(--app-muted-foreground)">
                    {item.priority !== 'NORMAL' && <span className={`me-1.5 font-medium ${PRIORITY_TONE[item.priority]}`}>{t(`communication.priorities.${item.priority}`)}</span>}
                    {listTime(item.published_at, locale, system)}
                </span>
            </span>
        </>
    );
    return (
        <li>
            {href
                ? <Link href={href} className="flex items-start gap-3 px-4 py-3 hover:bg-(--app-surface-muted)">{body}</Link>
                : <div className="flex items-start gap-3 px-4 py-3">{body}</div>}
        </li>
    );
}
