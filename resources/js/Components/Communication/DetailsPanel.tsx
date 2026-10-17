import { useEffect, useState, type JSX } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { communicationApi } from '@/lib/communication/api';
import { fileSize, listTime, tr } from '@/lib/communication/format';
import { formatRelative } from '@/lib/relativeTime';
import Avatar from '@/Components/Communication/Avatar';
import { bodyForEditing } from '@/Components/Communication/MessageText';
import { BellOffIcon, BellIcon, DownloadIcon, PaperclipIcon, XMarkIcon } from '@/Components/NavIcons';
import type { Attachment, ConversationDetail, MessageItem } from '@/types/communication';

type Tab = 'members' | 'files' | 'pinned';
type FileRow = Attachment & { uploaded_by: string | null; created_at: string | null; message_id: string };
type PinRow = { pinned_at: string | null; pinned_by: string | null; message: MessageItem };

type Props = {
    detail: ConversationDetail;
    pinsVersion: number;
    onClose: () => void;
    onMute: (mute: boolean) => void;
    onJump: (messageId: string) => void;
};

export default function DetailsPanel({ detail, pinsVersion, onClose, onMute, onJump }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const system = useCalendarSystem();
    const [tab, setTab] = useState<Tab>(detail.type === 'DIRECT' ? 'files' : 'members');
    const [files, setFiles] = useState<FileRow[] | null>(null);
    const [filesNext, setFilesNext] = useState<string | null>(null);
    const [pins, setPins] = useState<PinRow[] | null>(null);

    useEffect(() => {
        setFiles(null);
        setPins(null);
        setTab(detail.type === 'DIRECT' ? 'files' : 'members');
    }, [detail.id, detail.type]);

    useEffect(() => {
        if (tab === 'files' && files === null) {
            communicationApi.files(detail.id).then((page) => { setFiles(page.data); setFilesNext(page.next_before); }).catch(() => setFiles([]));
        }
    }, [tab, files, detail.id]);

    useEffect(() => {
        if (tab === 'pinned') communicationApi.pins(detail.id).then(setPins).catch(() => setPins([]));
    }, [tab, detail.id, pinsVersion]);

    const other = detail.type === 'DIRECT' ? detail.members.find((m) => m.id === detail.other_user?.id) : null;
    const tabs: Tab[] = detail.type === 'DIRECT' ? ['files', 'pinned'] : ['members', 'files', 'pinned'];

    return (
        <aside aria-label={t('communication.chat.details')} className="flex h-full min-h-0 flex-col bg-[color:var(--app-surface)]">
            <div className="flex items-center justify-between gap-2 border-b border-[color:var(--app-border)] px-4 py-3">
                <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.chat.details')}</h2>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('communication.chat.hideDetails')}
                    className="flex h-8 w-8 items-center justify-center rounded-control text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)]"
                >
                    <XMarkIcon className="h-4 w-4" />
                </button>
            </div>

            <div className="comm-scroll min-h-0 flex-1 overflow-y-auto">
                <section className="flex flex-col items-center gap-2 px-4 pb-4 pt-5 text-center">
                    <Avatar name={detail.title} kind={detail.type} userId={detail.other_user?.id} hasPhoto={detail.has_avatar} online={detail.other_user?.online ?? null} size="lg" />
                    <div>
                        <p className="text-sm font-semibold text-[color:var(--app-foreground)]">{detail.title}</p>
                        <p className="text-xs text-[color:var(--app-muted-foreground)]">
                            {other
                                ? [other.position, other.unit ?? other.organization].filter(Boolean).join(' · ')
                                : `${t(`communication.types.${detail.type}`)} · ${tr(t('communication.details.memberCount'), { n: detail.member_count })}`}
                        </p>
                        {other && other.online === false && other.last_seen_at && (
                            <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{tr(t('communication.list.lastSeen'), { time: formatRelative(other.last_seen_at, locale) ?? '' })}</p>
                        )}
                    </div>
                    {detail.description && <p className="max-w-prose text-xs leading-relaxed text-[color:var(--app-muted-foreground)]">{detail.description}</p>}
                    <button
                        type="button"
                        onClick={() => onMute(!detail.muted)}
                        aria-pressed={detail.muted}
                        className="mt-1 inline-flex h-8 items-center gap-1.5 rounded-control border border-[color:var(--app-border)] px-3 text-xs font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                    >
                        {detail.muted ? <BellIcon className="h-4 w-4" aria-hidden="true" /> : <BellOffIcon className="h-4 w-4" aria-hidden="true" />}
                        {detail.muted ? t('communication.actions.unmute') : t('communication.actions.mute')}
                    </button>
                </section>

                <div role="tablist" aria-label={t('communication.chat.details')} className="sticky top-0 z-10 flex border-b border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-2">
                    {tabs.map((value) => (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            aria-selected={tab === value}
                            onClick={() => setTab(value)}
                            className={`-mb-px flex-1 border-b-2 px-2 py-2 text-xs font-medium transition-colors ${tab === value ? 'border-[color:var(--color-primary)] text-[color:var(--app-foreground)]' : 'border-transparent text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]'}`}
                        >
                            {t(`communication.details.${value === 'members' ? 'members' : value === 'files' ? 'files' : 'pinned'}`)}
                        </button>
                    ))}
                </div>

                <div role="tabpanel" className="px-2 py-2">
                    {tab === 'members' && (
                        <>
                            {!detail.members_complete && <p className="px-2 pb-2 text-xs text-[color:var(--app-muted-foreground)]">{t('communication.details.partialMembers')}</p>}
                            <ul>
                                {detail.members.map((member) => (
                                    <li key={member.id} className="flex items-center gap-2.5 rounded-control px-2 py-1.5">
                                        <Avatar name={member.name} userId={member.id} hasPhoto={member.has_photo} online={member.online} size="sm" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm text-[color:var(--app-foreground)]">{member.name}</span>
                                            <span className="block truncate text-[11px] text-[color:var(--app-muted-foreground)]">{member.position ?? member.unit ?? ''}</span>
                                        </span>
                                        {member.role !== 'member' && (
                                            <span className="shrink-0 rounded-full bg-[color:var(--app-surface-muted)] px-2 py-0.5 text-[10px] font-medium text-[color:var(--app-muted-foreground)]">
                                                {t(`communication.details.roles.${member.role}`)}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}

                    {tab === 'files' && (
                        files === null ? <PanelSkeleton /> : files.length === 0 ? (
                            <p className="px-2 py-6 text-center text-xs text-[color:var(--app-muted-foreground)]">{t('communication.details.noFiles')}</p>
                        ) : (
                            <>
                                <ul>
                                    {files.map((file) => (
                                        <li key={file.id}>
                                            <a
                                                href={file.downloadable ? communicationApi.downloadUrl(file.id) : undefined}
                                                aria-disabled={!file.downloadable}
                                                className="flex items-center gap-2.5 rounded-control px-2 py-1.5 hover:bg-[color:var(--app-surface-muted)]"
                                            >
                                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]">
                                                    <PaperclipIcon className="h-4 w-4" aria-hidden="true" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm text-[color:var(--app-foreground)]">{file.name}</span>
                                                    <span className="block truncate text-[11px] text-[color:var(--app-muted-foreground)]">
                                                        {[fileSize(file.size), file.uploaded_by, listTime(file.created_at, locale, system)].filter(Boolean).join(' · ')}
                                                    </span>
                                                </span>
                                                {file.downloadable && <DownloadIcon className="h-4 w-4 shrink-0 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                                {filesNext && (
                                    <button
                                        type="button"
                                        onClick={() => communicationApi.files(detail.id, filesNext).then((page) => { setFiles((current) => [...(current ?? []), ...page.data]); setFilesNext(page.next_before); })}
                                        className="mt-1 h-8 w-full rounded-control text-xs font-medium text-[color:var(--color-primary)] hover:bg-[color:var(--app-surface-muted)]"
                                    >
                                        {t('communication.list.loadMore')}
                                    </button>
                                )}
                            </>
                        )
                    )}

                    {tab === 'pinned' && (
                        pins === null ? <PanelSkeleton /> : pins.length === 0 ? (
                            <p className="px-2 py-6 text-center text-xs text-[color:var(--app-muted-foreground)]">{t('communication.details.noPins')}</p>
                        ) : (
                            <ul className="space-y-1">
                                {pins.map((pin) => (
                                    <li key={pin.message.id}>
                                        <button
                                            type="button"
                                            onClick={() => onJump(pin.message.id)}
                                            className="block w-full rounded-control px-2 py-2 text-start hover:bg-[color:var(--app-surface-muted)]"
                                        >
                                            <span className="block text-xs font-semibold text-[color:var(--app-foreground)]">{pin.message.sender?.name}</span>
                                            <span className="line-clamp-3 text-sm text-[color:var(--app-foreground)]">
                                                {pin.message.deleted ? t('communication.chat.deleted') : (pin.message.body ? bodyForEditing(pin.message).text : t('communication.list.attachment'))}
                                            </span>
                                            {pin.pinned_by && <span className="mt-0.5 block text-[11px] text-[color:var(--app-muted-foreground)]">{tr(t('communication.details.pinnedBy'), { name: pin.pinned_by })}</span>}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )
                    )}
                </div>
            </div>
        </aside>
    );
}

function PanelSkeleton(): JSX.Element {
    return (
        <div aria-hidden="true" className="animate-pulse space-y-2 px-2 py-2">
            {[0, 1, 2].map((key) => <div key={key} className="h-9 rounded-control bg-[color:var(--app-surface-muted)]" />)}
        </div>
    );
}
