import { useCallback, useEffect, useState, type FormEvent, type JSX } from 'react';
import { Button } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/Components/ConfirmProvider';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { toast } from '@/lib/toast';
import { communicationApi, firstError } from '@/lib/communication/api';
import { fileSize, listTime, tr } from '@/lib/communication/format';
import { formatRelative } from '@/lib/relativeTime';
import Avatar from '@/Components/Communication/Avatar';
import PeoplePicker from '@/Components/Communication/PeoplePicker';
import { ActionMenu, MenuItem, fileTone } from '@/Components/Communication/MessageBubble';
import { bodyForEditing } from '@/Components/Communication/MessageText';
import { BellOffIcon, BellIcon, Briefcase, Building2, DownloadIcon, FileTextIcon, FlagIcon, IdCardIcon, LogOut, MenuDotsIcon, PinIcon, SearchIcon, UserBlockIcon, UserPlusIcon, UsersTwoIcon, XMarkIcon } from '@/Components/NavIcons';
import type { Attachment, ConversationDetail, ConversationMemberRow, MemberRole, MessageItem, Person, PostingPolicy } from '@/types/communication';

export type DetailsTab = 'members' | 'search' | 'files' | 'pinned' | 'settings';
type Tab = DetailsTab;
type FileRow = Attachment & { uploaded_by: string | null; created_at: string | null; message_id: string };
type PinRow = { pinned_at: string | null; pinned_by: string | null; message: MessageItem };

type Props = {
    detail: ConversationDetail;
    currentUserId: number;
    pinsVersion: number;
    onClose: () => void;
    onMute: (mute: boolean) => void;
    onJump: (messageId: string) => void;
    onChanged: () => void;
    onGone: () => void;
    onReport: () => void;
    /** Opens a given tab from outside (the chat header's Search and Pinned buttons); the nonce re-applies the same tab. */
    requestedTab?: { tab: DetailsTab; nonce: number } | null;
};

export default function DetailsPanel({ detail, currentUserId, pinsVersion, onClose, onMute, onJump, onChanged, onGone, onReport, requestedTab }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const system = useCalendarSystem();
    const direct = detail.type === 'DIRECT';
    const [tab, setTab] = useState<Tab>(direct ? 'files' : 'members');
    const [files, setFiles] = useState<FileRow[] | null>(null);
    const [filesNext, setFilesNext] = useState<string | null>(null);
    const [pins, setPins] = useState<PinRow[] | null>(null);

    useEffect(() => {
        setFiles(null);
        setPins(null);
        setTab(detail.type === 'DIRECT' ? 'files' : 'members');
    }, [detail.id, detail.type]);

    useEffect(() => {
        if (requestedTab) setTab(requestedTab.tab);
    }, [requestedTab]);

    useEffect(() => {
        if (tab === 'files' && files === null) {
            communicationApi.files(detail.id).then((page) => { setFiles(page.data); setFilesNext(page.next_before); }).catch(() => setFiles([]));
        }
    }, [tab, files, detail.id]);

    useEffect(() => {
        communicationApi.pins(detail.id).then(setPins).catch(() => setPins([]));
    }, [detail.id, pinsVersion]);

    const other = direct ? detail.members.find((m) => m.id === detail.other_user?.id) : null;
    const tabs: Tab[] = direct ? ['files', 'pinned', 'search'] : ['members', 'files', 'pinned', 'search', ...(detail.can.update ? ['settings' as const] : [])];
    const tabLabel = (value: Tab) => (value === 'settings' ? t('communication.manage.settings') : t(`communication.details.tabs.${value}`));

    const leave = async () => {
        const answer = await confirm({
            title: t('communication.manage.leaveTitle'),
            description: detail.role === 'owner' ? t('communication.manage.leaveOwnerMessage') : t('communication.manage.leaveMessage'),
            confirmLabel: t('communication.manage.leave'),
            variant: 'warning',
        });
        if (!answer.confirmed) return;
        try {
            await communicationApi.leave(detail.id);
            toast.success(t('communication.manage.left'));
            onGone();
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
        }
    };

    const archive = async () => {
        const answer = await confirm({
            title: t('communication.manage.archiveTitle'),
            description: t('communication.manage.archiveMessage'),
            confirmLabel: t('communication.manage.archive'),
            variant: 'danger',
        });
        if (!answer.confirmed) return;
        try {
            await communicationApi.archive(detail.id);
            toast.success(t('communication.manage.archivedDone'));
            onChanged();
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
        }
    };

    const toggleBlock = async () => {
        if (!other) return;
        if (!detail.blocked_by_me) {
            const answer = await confirm({
                title: tr(t('communication.block.title'), { name: other.name }),
                description: t('communication.block.message'),
                confirmLabel: t('communication.block.block'),
                variant: 'warning',
            });
            if (!answer.confirmed) return;
        }
        try {
            if (detail.blocked_by_me) await communicationApi.unblock(other.id);
            else await communicationApi.block(other.id);
            toast.success(tr(t(detail.blocked_by_me ? 'communication.block.undone' : 'communication.block.done'), { name: other.name }));
            onChanged();
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
        }
    };

    const presence = other ? (other.online ? t('communication.list.online') : other.last_seen_at ? tr(t('communication.list.lastSeen'), { time: formatRelative(other.last_seen_at, locale) ?? '' }) : null) : null;
    const headline = other
        ? [other.position, other.unit ?? other.organization].filter(Boolean).join(' · ')
        : `${detail.channel_info ? t(`communication.channels.types.${detail.channel_info.type}`) : t(`communication.types.${detail.type}`)} · ${tr(t('communication.details.memberCount'), { n: detail.member_count })}`;
    const channel = detail.channel_info;
    const scope = channel && (channel.organization || channel.auto_membership)
        ? `${[channel.unit, channel.organization].filter(Boolean).join(' · ') || t('communication.channels.cityWide')}${channel.type === 'SYSTEM_MANAGED' ? ` — ${t('communication.manage.mandatory')}` : channel.auto_membership ? ` — ${t('communication.manage.automatic')}` : ''}`
        : null;
    const about: { icon: JSX.Element; label: string; value: string | null | undefined }[] = (other
        ? [
            { icon: <Briefcase className="h-[17px] w-[17px]" />, label: t('communication.details.position'), value: other.position },
            { icon: <UsersTwoIcon className="h-[17px] w-[17px]" />, label: t('communication.details.unit'), value: other.unit },
            { icon: <Building2 className="h-[17px] w-[17px]" />, label: t('communication.details.organization'), value: other.organization },
        ]
        : [
            { icon: <FileTextIcon className="h-[17px] w-[17px]" />, label: t('communication.details.description'), value: detail.description },
            detail.type === 'GROUP'
                ? { icon: <IdCardIcon className="h-[17px] w-[17px]" />, label: t('communication.details.yourRole'), value: t(`communication.details.roles.${detail.role}`) }
                : { icon: <Building2 className="h-[17px] w-[17px]" />, label: t('communication.details.scope'), value: scope },
        ]).filter((row) => !!row.value);
    const counts: Partial<Record<Tab, number>> = { members: detail.member_count, files: files?.length, pinned: pins?.length };
    const tile = 'flex h-[60px] flex-col items-center justify-center gap-1 rounded-[10px] bg-(--app-background) text-xs font-medium text-(--app-foreground) hover:bg-[color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))] focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary';
    const danger = 'flex h-[38px] w-full items-center gap-2.5 rounded-lg px-2 text-start text-[13px] font-medium text-[#b91c1c] hover:bg-[#fbe4e1] dark:text-red-400 dark:hover:bg-red-950/40';
    const kindNoun = detail.type === 'CHANNEL' ? 'Channel' : 'Group';

    return (
        <aside aria-label={t('communication.chat.details')} className="flex h-full min-h-0 flex-col bg-(--app-surface)">
            <div className="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-(--app-border) pe-3 ps-5">
                <h2 className="text-[15px] font-semibold text-(--app-foreground)">{t('communication.details.title')}</h2>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('communication.chat.hideDetails')}
                    title={t('communication.chat.hideDetails')}
                    className="flex h-8 w-8 items-center justify-center rounded-lg text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-(--app-foreground)"
                >
                    <XMarkIcon className="h-[18px] w-[18px]" />
                </button>
            </div>

            <div className="comm-scroll min-h-0 flex-1 overflow-y-auto overflow-x-hidden">
                <section className="flex flex-col items-center px-5 pb-4 pt-6 text-center">
                    <Avatar name={detail.title} kind={detail.type} userId={detail.other_user?.id} hasPhoto={detail.has_avatar} online={detail.other_user?.online ?? null} size="xl" />
                    <p className="mt-3 text-base font-semibold text-(--app-foreground)">{detail.title}</p>
                    {headline && <p className="mt-0.5 text-[13px] text-(--app-muted-foreground)">{headline}</p>}
                    {presence && <p className={`mt-0.5 text-[12.5px] ${other?.online ? 'text-[#0f6b45] dark:text-emerald-400' : 'text-(--app-muted-foreground)'}`}>{presence}</p>}
                    <div className="mt-4 grid w-full grid-cols-3 gap-2">
                        <button type="button" onClick={() => onMute(!detail.muted)} aria-pressed={detail.muted} className={tile}>
                            {detail.muted ? <BellIcon className="h-5 w-5 text-primary" aria-hidden="true" /> : <BellOffIcon className="h-5 w-5 text-primary" aria-hidden="true" />}
                            {detail.muted ? t('communication.actions.unmute') : t('communication.actions.mute')}
                        </button>
                        <button type="button" onClick={() => setTab('search')} className={tile}>
                            <SearchIcon className="h-5 w-5 text-primary" aria-hidden="true" />
                            {t('communication.details.tabs.search')}
                        </button>
                        <button type="button" onClick={() => setTab('pinned')} className={tile}>
                            <PinIcon className="h-5 w-5 text-primary" aria-hidden="true" />
                            {t('communication.details.tabs.pinned')}
                        </button>
                    </div>
                </section>

                {about.length > 0 && (
                    <dl className="mx-5 flex flex-col gap-3 border-t border-(--app-border) py-3">
                        {about.map((row) => (
                            <div key={row.label} className="flex items-start gap-3">
                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-(--app-background) text-(--app-muted-foreground)" aria-hidden="true">{row.icon}</span>
                                <div className="min-w-0">
                                    <dt className="text-[11.5px] text-(--app-muted-foreground)">{row.label}</dt>
                                    <dd className="text-[13px] font-medium leading-snug text-(--app-foreground)">{row.value}</dd>
                                </div>
                            </div>
                        ))}
                    </dl>
                )}

                <div role="tablist" aria-label={t('communication.chat.details')} className="comm-tabs sticky top-0 z-10 flex gap-1 overflow-x-auto border-y border-(--app-border) bg-(--app-surface) px-4">
                    {tabs.map((value) => (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            aria-selected={tab === value}
                            onClick={() => setTab(value)}
                            className={`-mb-px flex h-[42px] flex-auto shrink-0 items-center justify-center gap-[5px] whitespace-nowrap border-b-2 px-1.5 text-[12.5px] font-semibold transition-colors ${tab === value ? 'border-primary text-(--app-foreground)' : 'border-transparent text-(--app-muted-foreground) hover:text-(--app-foreground)'}`}
                        >
                            {tabLabel(value)}
                            {!!counts[value] && <span className="text-[11px] font-semibold tabular-nums text-(--app-muted-foreground)">{counts[value]}</span>}
                        </button>
                    ))}
                </div>

                <div role="tabpanel" className="px-3 pb-3 pt-2">
                    {tab === 'members' && <MembersTab detail={detail} currentUserId={currentUserId} onChanged={onChanged} />}

                    {tab === 'files' && (
                        files === null ? <PanelSkeleton /> : files.length === 0 ? (
                            <p className="px-2 py-5 text-center text-[12.5px] text-(--app-muted-foreground)">{t('communication.details.noFiles')}</p>
                        ) : (
                            <>
                                <ul className="flex flex-col gap-0.5">
                                    {files.map((file) => (
                                        <li key={file.id}>
                                            <a
                                                href={file.downloadable ? communicationApi.downloadUrl(file.id) : undefined}
                                                aria-disabled={!file.downloadable}
                                                className="flex items-center gap-2.5 rounded-lg p-2 hover:bg-(--app-background)"
                                            >
                                                <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[10px] font-bold ${fileTone(file.extension)} bg-(--comm-tone-bg) text-(--comm-tone-fg)`}>
                                                    {file.extension.toUpperCase().slice(0, 4)}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-[13px] font-medium text-(--app-foreground)">{file.name}</span>
                                                    <span className="block truncate text-[11.5px] text-(--app-muted-foreground)">
                                                        {[fileSize(file.size), file.uploaded_by, listTime(file.created_at, locale, system)].filter(Boolean).join(' · ')}
                                                    </span>
                                                </span>
                                                {file.downloadable && <DownloadIcon className="h-[17px] w-[17px] shrink-0 text-(--app-muted-foreground)" aria-hidden="true" />}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                                {filesNext && (
                                    <button
                                        type="button"
                                        onClick={() => communicationApi.files(detail.id, filesNext).then((page) => { setFiles((current) => [...(current ?? []), ...page.data]); setFilesNext(page.next_before); })}
                                        className="mt-1 h-8 w-full rounded-lg text-xs font-medium text-primary hover:bg-(--app-surface-muted)"
                                    >
                                        {t('communication.list.loadMore')}
                                    </button>
                                )}
                            </>
                        )
                    )}

                    {tab === 'pinned' && (
                        pins === null ? <PanelSkeleton /> : pins.length === 0 ? (
                            <p className="px-2 py-5 text-center text-[12.5px] text-(--app-muted-foreground)">{t('communication.details.noPins')}</p>
                        ) : (
                            <ul className="flex flex-col gap-1.5 pt-1">
                                {pins.map((pin) => (
                                    <li key={pin.message.id}>
                                        <button type="button" onClick={() => onJump(pin.message.id)} className="block w-full rounded-[10px] bg-(--app-background) px-3 py-2.5 text-start hover:bg-[color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))]">
                                            {pin.message.sender?.name && <span className="block text-xs font-semibold text-(--app-foreground)">{pin.message.sender.name}</span>}
                                            <span className="line-clamp-3 text-[13px] leading-[1.45] text-(--app-foreground)">
                                                {pin.message.deleted ? t('communication.chat.deleted') : (pin.message.body ? bodyForEditing(pin.message).text : t('communication.list.attachment'))}
                                            </span>
                                            {pin.pinned_by && (
                                                <span className="mt-1.5 flex items-center gap-1 text-[11.5px] text-(--app-muted-foreground)">
                                                    <PinIcon className="h-3 w-3" aria-hidden="true" />
                                                    {[tr(t('communication.details.pinnedBy'), { name: pin.pinned_by }), listTime(pin.pinned_at, locale, system)].filter(Boolean).join(' · ')}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )
                    )}

                    {tab === 'search' && <SearchTab key={detail.id} conversationId={detail.id} onJump={onJump} />}

                    {tab === 'settings' && <SettingsTab detail={detail} onChanged={onChanged} onArchive={() => void archive()} />}
                </div>

                {(direct ? !!other : true) && (
                    <div className="mx-5 flex flex-col gap-0.5 border-t border-(--app-border) pb-5 pt-3">
                        {direct && other && (
                            <button type="button" onClick={() => void toggleBlock()} className={danger}>
                                <UserBlockIcon className="h-[17px] w-[17px]" aria-hidden="true" />
                                {detail.blocked_by_me ? t('communication.block.unblock') : tr(t('communication.details.blockName'), { name: other.name.split(' ')[0] })}
                            </button>
                        )}
                        {!direct && (
                            <button type="button" onClick={onReport} className={danger}>
                                <FlagIcon className="h-[17px] w-[17px]" aria-hidden="true" />
                                {t(`communication.details.report${kindNoun}`)}
                            </button>
                        )}
                        {detail.can.leave && !detail.archived && (
                            <button type="button" onClick={() => void leave()} className={danger}>
                                <LogOut className="h-[17px] w-[17px]" aria-hidden="true" />
                                {direct ? t('communication.manage.leave') : t(`communication.details.leave${kindNoun}`)}
                            </button>
                        )}
                    </div>
                )}
            </div>
        </aside>
    );
}

function MembersTab({ detail, currentUserId, onChanged }: { detail: ConversationDetail; currentUserId: number; onChanged: () => void }): JSX.Element {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const [adding, setAdding] = useState(false);
    const [picked, setPicked] = useState<Person[]>([]);
    const [busy, setBusy] = useState(false);
    const [q, setQ] = useState('');
    const [paged, setPaged] = useState<{ rows: ConversationMemberRow[]; next: string | null } | null>(null);

    const complete = detail.members_complete;
    const load = useCallback((query: string, cursor: string | null) => {
        communicationApi.members(detail.id, { q: query.trim().length >= 2 ? query.trim() : null, cursor })
            .then((page) => setPaged((current) => ({ rows: cursor && current ? [...current.rows, ...page.data] : page.data, next: page.next_cursor })))
            .catch(() => setPaged({ rows: [], next: null }));
    }, [detail.id]);

    useEffect(() => {
        if (complete) return;
        const timer = window.setTimeout(() => load(q, null), q ? 250 : 0);
        return () => window.clearTimeout(timer);
    }, [complete, q, load, detail.member_count]);

    const rows = complete ? detail.members : (paged?.rows ?? []);
    const myRole = detail.role;

    const act = async (fn: () => Promise<unknown>) => {
        setBusy(true);
        try {
            await fn();
            onChanged();
            if (!complete) load(q, null);
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
        } finally {
            setBusy(false);
        }
    };

    const addPeople = () => act(async () => {
        const added = await communicationApi.addMembers(detail.id, picked.map((p) => p.id));
        toast.success(tr(t('communication.members.added'), { n: added }));
        setPicked([]);
        setAdding(false);
    });

    const remove = async (member: ConversationMemberRow) => {
        const answer = await confirm({
            title: tr(t('communication.manage.removeTitle'), { name: member.name }),
            description: t('communication.manage.removeMessage'),
            confirmLabel: t('communication.members.remove'),
            variant: 'danger',
        });
        if (answer.confirmed) void act(() => communicationApi.removeMember(detail.id, member.id));
    };

    const setRole = async (member: ConversationMemberRow, role: MemberRole) => {
        if (role === 'owner') {
            const answer = await confirm({
                title: tr(t('communication.manage.ownerTitle'), { name: member.name }),
                description: t('communication.manage.ownerMessage'),
                confirmLabel: t('communication.members.makeOwner'),
                variant: 'warning',
            });
            if (!answer.confirmed) return;
        }
        void act(() => communicationApi.changeRole(detail.id, member.id, role));
    };

    return (
        <div className="space-y-2">
            {detail.can.addMembers && !detail.archived && (
                adding ? (
                    <div className="space-y-2 rounded-control border border-(--app-border) p-2">
                        <PeoplePicker selected={picked} onChange={setPicked} excludeIds={detail.members.map((m) => m.id)} autoFocus />
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => { setAdding(false); setPicked([]); }} className="h-8 rounded-control px-3 text-xs font-medium text-(--app-foreground) hover:bg-(--app-surface-muted)">
                                {t('communication.channels.cancel')}
                            </button>
                            <button type="button" disabled={busy || picked.length === 0} onClick={() => void addPeople()} className="h-8 rounded-control bg-primary px-3 text-xs font-medium text-white hover:opacity-90 disabled:opacity-40">
                                {busy ? t('communication.members.adding') : tr(t('communication.members.addSelected'), { n: picked.length })}
                            </button>
                        </div>
                    </div>
                ) : (
                    <button type="button" onClick={() => setAdding(true)} className="flex w-full items-center gap-2.5 rounded-lg p-2 text-[13px] font-semibold text-primary hover:bg-(--app-background)">
                        <span className="flex h-[34px] w-[34px] items-center justify-center rounded-full bg-[color-mix(in_srgb,var(--color-primary)_12%,var(--app-surface))]"><UserPlusIcon className="h-[18px] w-[18px]" aria-hidden="true" /></span>
                        {t('communication.members.add')}
                    </button>
                )
            )}

            {!complete && (
                <label className="relative block px-1">
                    <span className="sr-only">{t('communication.members.search')}</span>
                    <SearchIcon className="pointer-events-none absolute inset-s-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-(--app-muted-foreground)" aria-hidden="true" />
                    <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('communication.members.search')}
                        className="h-8 w-full rounded-control border border-(--app-border) bg-(--app-background) ps-8 pe-2 text-xs text-(--app-foreground) focus:border-primary focus:outline-hidden" />
                </label>
            )}

            {!complete && paged === null ? <PanelSkeleton /> : (
                <ul>
                    {rows.map((member) => (
                        <MemberLine
                            key={member.id}
                            member={member}
                            me={member.id === currentUserId}
                            myRole={myRole}
                            can={detail.can}
                            mandatory={detail.channel_info?.type === 'SYSTEM_MANAGED'}
                            busy={busy}
                            onRemove={() => void remove(member)}
                            onRole={(role) => void setRole(member, role)}
                        />
                    ))}
                </ul>
            )}
            {!complete && paged?.next && (
                <button type="button" onClick={() => load(q, paged.next)} className="h-8 w-full rounded-control text-xs font-medium text-primary hover:bg-(--app-surface-muted)">
                    {t('communication.list.loadMore')}
                </button>
            )}
        </div>
    );
}

function MemberLine({ member, me, myRole, can, mandatory, busy, onRemove, onRole }: {
    member: ConversationMemberRow; me: boolean; myRole: MemberRole; can: ConversationDetail['can']; mandatory: boolean; busy: boolean;
    onRemove: () => void; onRole: (role: MemberRole) => void;
}): JSX.Element {
    const { t } = useLocale();
    const [menu, setMenu] = useState(false);
    const roleActions = can.changeRoles && !me && member.role !== 'owner';
    const removable = can.manageMembers && !me && member.role !== 'owner' && !(member.role === 'admin' && myRole === 'admin')
        && !(mandatory && member.source === 'AUTO');
    const hasActions = roleActions || removable;

    return (
        <li className="flex items-center gap-2.5 rounded-control px-2 py-1.5">
            <Avatar name={member.name} userId={member.id} hasPhoto={member.has_photo} online={member.online} size="sm" />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm text-(--app-foreground)">
                    {member.name}{me && <span className="text-(--app-muted-foreground)"> ({t('communication.members.you')})</span>}
                </span>
                <span className="block truncate text-[11px] text-(--app-muted-foreground)">
                    {[member.position ?? member.unit, member.source === 'AUTO' ? t('communication.details.sources.AUTO') : null].filter(Boolean).join(' · ')}
                </span>
            </span>
            {member.role !== 'member' && (
                <span className="shrink-0 rounded-full bg-(--app-surface-muted) px-2 py-0.5 text-[10px] font-medium text-(--app-muted-foreground)">
                    {t(`communication.details.roles.${member.role}`)}
                </span>
            )}
            {hasActions && (
                <div className="relative shrink-0">
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => setMenu((v) => !v)}
                        aria-expanded={menu}
                        aria-label={tr(t('communication.members.actions'), { name: member.name })}
                        className="flex h-7 w-7 items-center justify-center rounded-md text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-(--app-foreground)"
                    >
                        <MenuDotsIcon className="h-4 w-4" />
                    </button>
                    {menu && (
                        <ActionMenu onClose={() => setMenu(false)}>
                            {roleActions && member.role === 'member' && <MenuItem onClick={() => { setMenu(false); onRole('admin'); }}>{t('communication.members.makeAdmin')}</MenuItem>}
                            {roleActions && member.role === 'admin' && <MenuItem onClick={() => { setMenu(false); onRole('member'); }}>{t('communication.members.removeAdmin')}</MenuItem>}
                            {roleActions && <MenuItem onClick={() => { setMenu(false); onRole('owner'); }}>{t('communication.members.makeOwner')}</MenuItem>}
                            {removable && <MenuItem danger onClick={() => { setMenu(false); onRemove(); }}>{t('communication.members.remove')}</MenuItem>}
                        </ActionMenu>
                    )}
                </div>
            )}
        </li>
    );
}

function SettingsTab({ detail, onChanged, onArchive }: { detail: ConversationDetail; onChanged: () => void; onArchive: () => void }): JSX.Element {
    const { t } = useLocale();
    const channel = detail.channel_info;
    const [title, setTitle] = useState(channel?.name ?? detail.title);
    const [nameAm, setNameAm] = useState(channel?.name_am ?? '');
    const [description, setDescription] = useState(detail.description ?? '');
    const [policy, setPolicy] = useState<PostingPolicy>(channel?.posting_policy ?? 'ALL_MEMBERS');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const field = 'w-full rounded-control border border-(--app-border) bg-(--app-background) px-2.5 text-sm text-(--app-foreground) focus:border-primary focus:outline-hidden focus:ring-2 focus:ring-primary/15';

    const save = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setError(null);
        try {
            await communicationApi.updateConversation(detail.id, {
                title: title.trim(),
                description: description.trim() || null,
                ...(channel ? { name_am: nameAm.trim() || null, posting_policy: policy } : {}),
            });
            toast.success(t('communication.manage.saved'));
            onChanged();
        } catch (reason) {
            setError(firstError(reason) ?? t('communication.errors.generic'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-6 px-2 py-2">
            <form onSubmit={save} className="space-y-3">
                <label className="block space-y-1">
                    <span className="text-xs font-medium text-(--app-foreground)">{t('communication.manage.name')}</span>
                    <input value={title} onChange={(e) => setTitle(e.target.value)} required maxLength={channel ? 80 : 120} className={`${field} h-9`} />
                </label>
                {channel && (
                    <label className="block space-y-1">
                        <span className="text-xs font-medium text-(--app-foreground)">{t('communication.manage.nameAm')}</span>
                        <input value={nameAm} onChange={(e) => setNameAm(e.target.value)} maxLength={80} lang="am" className={`${field} h-9`} />
                    </label>
                )}
                <label className="block space-y-1">
                    <span className="text-xs font-medium text-(--app-foreground)">{t('communication.manage.description')}</span>
                    <textarea value={description} onChange={(e) => setDescription(e.target.value)} maxLength={1000} rows={3} className={`${field} resize-none py-2`} />
                </label>
                {channel && (
                    <fieldset className="space-y-1.5">
                        <legend className="text-xs font-medium text-(--app-foreground)">{t('communication.manage.postingPolicy')}</legend>
                        {(['ALL_MEMBERS', 'ADMINS_ONLY'] as const).map((value) => (
                            <label key={value} className="flex items-center gap-2 text-sm text-(--app-foreground)">
                                <input type="radio" name="posting_policy" value={value} checked={policy === value} onChange={() => setPolicy(value)} className="h-4 w-4 text-primary" />
                                {t(`communication.manage.postingPolicies.${value}`)}
                            </label>
                        ))}
                    </fieldset>
                )}
                {error && <p role="alert" className="text-xs text-(--color-danger,#b42318)">{error}</p>}
                <Button type="submit" variant="primary" disabled={saving || !title.trim()} loading={saving}>
                    {t('communication.manage.save')}
                </Button>
            </form>

            {detail.can.archive && !detail.archived && (
                <div className="space-y-1.5 border-t border-(--app-border) pt-4">
                    <p className="text-xs text-(--app-muted-foreground)">{t('communication.manage.archiveMessage')}</p>
                    <button type="button" onClick={onArchive} className="h-8 rounded-control border border-(--tone-danger-border,#f2b8b5) px-3 text-xs font-medium text-(--color-danger,#b42318) hover:bg-(--tone-danger-bg,#fef3f2)">
                        {t('communication.manage.archive')}
                    </button>
                </div>
            )}
        </div>
    );
}

function PanelSkeleton(): JSX.Element {
    return (
        <div aria-hidden="true" className="animate-pulse space-y-2 px-2 py-2">
            {[0, 1, 2].map((key) => <div key={key} className="h-9 rounded-control bg-(--app-surface-muted)" />)}
        </div>
    );
}

/** Text search inside this conversation; a result jumps to the message in the chat. */
function SearchTab({ conversationId, onJump }: { conversationId: string; onJump: (messageId: string) => void }): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const [q, setQ] = useState('');
    const [results, setResults] = useState<MessageItem[] | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) { setResults(null); return; }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            communicationApi.searchMessages(conversationId, term, controller.signal)
                .then(setResults)
                .catch(() => { if (!controller.signal.aborted) setResults([]); })
                .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        }, 300);
        return () => { controller.abort(); window.clearTimeout(timer); };
    }, [q, conversationId]);

    const term = q.trim().toLowerCase();
    const highlight = (text: string) => {
        const at = term ? text.toLowerCase().indexOf(term) : -1;
        if (at < 0) return text;
        return <>{text.slice(0, at)}<mark className="rounded-sm bg-[color-mix(in_srgb,var(--color-primary)_25%,transparent)] px-0.5 text-(--app-foreground)">{text.slice(at, at + term.length)}</mark>{text.slice(at + term.length)}</>;
    };

    return (
        <div className="space-y-2">
            <label className="relative block px-1">
                <span className="sr-only">{t('communication.search.label')}</span>
                <SearchIcon className="pointer-events-none absolute inset-s-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-(--app-muted-foreground)" aria-hidden="true" />
                {/* eslint-disable-next-line jsx-a11y/no-autofocus -- the tab exists to type a search */}
                <input
                    type="search"
                    autoFocus
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder={t('communication.search.placeholder')}
                    className="h-9 w-full rounded-control border border-(--app-border) bg-(--app-background) ps-8 pe-2 text-sm text-(--app-foreground) placeholder:text-(--app-muted-foreground) focus:border-primary focus:outline-hidden focus:ring-2 focus:ring-primary/15"
                />
            </label>
            {results === null ? (
                <p className="px-2 py-6 text-center text-xs text-(--app-muted-foreground)">{loading ? t('communication.search.searching') : t('communication.search.hint')}</p>
            ) : results.length === 0 ? (
                <p className="px-2 py-6 text-center text-xs text-(--app-muted-foreground)">{t('communication.search.empty')}</p>
            ) : (
                <ul className="space-y-1" aria-live="polite">
                    {results.map((message) => (
                        <li key={message.id}>
                            <button type="button" onClick={() => onJump(message.id)} className="block w-full rounded-control px-2 py-2 text-start hover:bg-(--app-surface-muted) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary">
                                <span className="flex items-baseline justify-between gap-2">
                                    <span className="truncate text-xs font-semibold text-(--app-foreground)">{message.sender?.name}</span>
                                    <span className="shrink-0 text-[11px] text-(--app-muted-foreground)">{listTime(message.created_at, locale, system)}</span>
                                </span>
                                <span className="line-clamp-3 text-sm text-(--app-foreground)">{highlight(bodyForEditing(message).text)}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
