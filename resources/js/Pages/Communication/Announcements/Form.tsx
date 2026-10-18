import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { buttonClassName } from '@/Components/ui';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedTimePicker from '@/Components/Calendar/LocalizedTimePicker';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import { communicationApi, firstError } from '@/lib/communication/api';
import { dayKey, fileSize, tr } from '@/lib/communication/format';
import PriorityMark from '@/Components/Communication/PriorityMark';
import { PaperclipIcon, PlusIcon, SearchIcon, XMarkIcon } from '@/Components/NavIcons';
import type { PageProps } from '@/types';
import type { AnnouncementFile, AnnouncementPriority, AnnouncementStatus, AnnouncementTargetType, TargetLabel, TargetOption } from '@/types/communication';

type Existing = {
    id: string;
    status: AnnouncementStatus;
    title: string;
    title_am: string | null;
    body: string;
    body_am: string | null;
    priority: AnnouncementPriority;
    acknowledgement_required: boolean;
    expires_at: string | null;
    publish_at: string | null;
    targets: TargetLabel[];
    attachments: AnnouncementFile[];
};

type Props = PageProps<{
    announcement: Existing | null;
    priorities: AnnouncementPriority[];
    targetTypes: AnnouncementTargetType[];
    canPublish: boolean;
    limits: { maxAttachmentKb: number; allowedTypes: string[] };
}>;

type Intent = 'draft' | 'publish' | 'schedule';

const clockOf = (iso: string | null) => {
    if (!iso) return '';
    const d = new Date(iso);
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};
const combine = (date: string, time: string) => (date ? `${date} ${time || '00:00'}:00` : null);

export default function AnnouncementForm({ announcement, priorities, targetTypes, canPublish, limits }: Props): JSX.Element {
    const { t } = useLocale();
    const [title, setTitle] = useState(announcement?.title ?? '');
    const [titleAm, setTitleAm] = useState(announcement?.title_am ?? '');
    const [body, setBody] = useState(announcement?.body ?? '');
    const [bodyAm, setBodyAm] = useState(announcement?.body_am ?? '');
    const [priority, setPriority] = useState<AnnouncementPriority>(announcement?.priority ?? 'NORMAL');
    const [ack, setAck] = useState(announcement?.acknowledgement_required ?? false);
    const [targets, setTargets] = useState<TargetLabel[]>(announcement?.targets ?? []);
    const [expiresDate, setExpiresDate] = useState(announcement?.expires_at ? dayKey(announcement.expires_at) : '');
    const [expiresTime, setExpiresTime] = useState(announcement?.expires_at ? clockOf(announcement.expires_at) : '17:00');
    const [publishDate, setPublishDate] = useState(announcement?.publish_at ? dayKey(announcement.publish_at) : '');
    const [publishTime, setPublishTime] = useState(announcement?.publish_at ? clockOf(announcement.publish_at) : '08:30');
    const [scheduling, setScheduling] = useState(announcement?.status === 'SCHEDULED');
    const [files, setFiles] = useState<AnnouncementFile[]>(announcement?.attachments ?? []);
    const [uploading, setUploading] = useState(false);
    const [estimate, setEstimate] = useState<number | null>(null);
    const [estimating, setEstimating] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState<Intent | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (targets.length === 0) { setEstimate(null); return; }
        setEstimating(true);
        const timer = window.setTimeout(() => {
            communicationApi.audience(targets.map((x) => ({ type: x.type, id: x.id })))
                .then(setEstimate).catch(() => setEstimate(null)).finally(() => setEstimating(false));
        }, 400);
        return () => window.clearTimeout(timer);
    }, [targets]);

    const submit = (intent: Intent) => (event?: FormEvent) => {
        event?.preventDefault();
        setSaving(intent);
        setErrors({});
        const payload = {
            title, title_am: titleAm || null, body, body_am: bodyAm || null, priority,
            acknowledgement_required: ack,
            expires_at: combine(expiresDate, expiresTime),
            targets: targets.map((x) => ({ type: x.type, id: x.id })),
            intent,
            publish_at: intent === 'schedule' ? combine(publishDate, publishTime) : null,
        };
        const options = {
            preserveScroll: true,
            onError: (bag: Record<string, string>) => {
                setErrors(bag);
                toast.error(Object.values(bag)[0] ?? t('communication.errors.generic'));
            },
            onFinish: () => setSaving(null),
        };
        if (announcement) router.put(route('communication.announcements.update', announcement.id), payload, options);
        else router.post(route('communication.announcements.store'), payload, options);
    };

    const upload = async (list: FileList | null) => {
        if (!announcement || !list) return;
        setUploading(true);
        try {
            for (const file of Array.from(list)) {
                const stored = await communicationApi.uploadAnnouncementFile(announcement.id, file);
                setFiles((current) => [...current, stored]);
            }
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.composer.uploadFailed'));
        } finally {
            setUploading(false);
            if (fileInput.current) fileInput.current.value = '';
        }
    };

    const field = 'w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-3 text-sm text-[color:var(--app-foreground)] placeholder:text-[color:var(--app-muted-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15';
    const heading = announcement ? t('communication.announcements.form.editTitle') : t('communication.announcements.form.createTitle');

    return (
        <AuthenticatedLayout mode="communication" contentWidth="form" header={<PageHeader title={heading} backHref={announcement ? route('communication.announcements.show', announcement.id) : route('communication.announcements.index')} />}>
            <Head title={heading} />

            <form onSubmit={submit('draft')} className="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                <div className="space-y-6">
                    <Panel title={t('communication.announcements.form.english')}>
                        <Field label={t('communication.announcements.form.titleLabel')} error={errors.title}>
                            <input value={title} onChange={(e) => setTitle(e.target.value)} required maxLength={200} className={`${field} h-10`} />
                        </Field>
                        <RichTextEditor
                            label={t('communication.announcements.form.bodyLabel')}
                            helperText={t('communication.announcements.form.bodyHelp')}
                            value={body}
                            onChange={setBody}
                            error={errors.body}
                            preset="standard"
                            maxLength={20000}
                            required
                            locale="en"
                        />
                    </Panel>
                    <Panel title={t('communication.announcements.form.amharic')}>
                        <Field label={t('communication.announcements.form.titleLabel')} error={errors.title_am}>
                            <input value={titleAm} onChange={(e) => setTitleAm(e.target.value)} maxLength={200} lang="am" className={`${field} h-10`} />
                        </Field>
                        <RichTextEditor
                            label={t('communication.announcements.form.bodyLabel')}
                            value={bodyAm}
                            onChange={setBodyAm}
                            error={errors.body_am}
                            preset="standard"
                            maxLength={20000}
                            locale="am"
                        />
                    </Panel>
                    <Panel title={t('communication.announcements.form.files')}>
                        {!announcement ? (
                            <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('communication.announcements.form.filesAfterSave')}</p>
                        ) : (
                            <div className="space-y-2">
                                {files.length > 0 && (
                                    <ul className="divide-y divide-[color:var(--app-border)] rounded-control border border-[color:var(--app-border)]">
                                        {files.map((file) => (
                                            <li key={file.id} className="flex items-center gap-2.5 px-3 py-2 text-sm">
                                                <PaperclipIcon className="h-4 w-4 shrink-0 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                                                <span className="min-w-0 flex-1 truncate text-[color:var(--app-foreground)]">{file.name}</span>
                                                <span className="shrink-0 text-xs text-[color:var(--app-muted-foreground)]">{fileSize(file.size)}</span>
                                                <button
                                                    type="button"
                                                    aria-label={`${t('communication.announcements.form.remove')} ${file.name}`}
                                                    onClick={() => communicationApi.deleteAnnouncementFile(file.id).then(() => setFiles((c) => c.filter((x) => x.id !== file.id))).catch(() => toast.error(t('communication.errors.generic')))}
                                                    className="rounded p-1 text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]"
                                                >
                                                    <XMarkIcon className="h-4 w-4" />
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <input ref={fileInput} type="file" multiple className="sr-only" tabIndex={-1} accept={limits.allowedTypes.map((x) => `.${x}`).join(',')} onChange={(e) => void upload(e.target.files)} />
                                <button type="button" disabled={uploading || files.length >= 10} onClick={() => fileInput.current?.click()} className={buttonClassName({ variant: 'secondary', size: 'sm', className: 'gap-1.5' })}>
                                    <PaperclipIcon className="h-4 w-4" aria-hidden="true" />
                                    {uploading ? t('communication.announcements.form.uploading') : t('communication.announcements.form.attach')}
                                </button>
                            </div>
                        )}
                    </Panel>
                </div>

                <div className="space-y-6">
                    <Panel title={t('communication.announcements.form.audience')} hint={t('communication.announcements.form.audienceHelp')}>
                        <TargetBuilder types={targetTypes} value={targets} onChange={setTargets} />
                        {errors.targets && <p className="text-xs text-[color:var(--color-danger,#b42318)]">{errors.targets}</p>}
                        {targets.length > 0 && (
                            <p className="text-xs font-medium text-[color:var(--app-foreground)]" aria-live="polite">
                                {estimating ? t('communication.announcements.form.estimating') : estimate !== null ? tr(t('communication.announcements.form.estimate'), { n: estimate.toLocaleString() }) : ''}
                            </p>
                        )}
                    </Panel>

                    <Panel title={t('communication.announcements.form.priority')}>
                        <div className="space-y-2">
                            {priorities.map((value) => (
                                <label key={value} className={`flex cursor-pointer items-start gap-2.5 rounded-control border px-3 py-2 ${priority === value ? 'border-[color:var(--color-primary)] bg-[color:color-mix(in_srgb,var(--color-primary)_7%,var(--app-surface))]' : 'border-[color:var(--app-border)]'}`}>
                                    <input type="radio" name="priority" value={value} checked={priority === value} onChange={() => setPriority(value)} className="mt-0.5 h-4 w-4 text-[color:var(--color-primary)]" />
                                    <span>
                                        <span className="flex items-center gap-1.5 text-sm font-medium text-[color:var(--app-foreground)]">
                                            <PriorityMark priority={value} withLabel={false} />{t(`communication.announcements.priorities.${value}`)}
                                        </span>
                                        <span className="block text-xs text-[color:var(--app-muted-foreground)]">{t(`communication.announcements.priorityHelp.${value}`)}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </Panel>

                    <Panel title={t('communication.announcements.form.options')}>
                        <label className="flex items-start gap-2.5 text-sm text-[color:var(--app-foreground)]">
                            <input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)]" />
                            <span>
                                {t('communication.announcements.form.acknowledgement')}
                                <span className="block text-xs text-[color:var(--app-muted-foreground)]">{t('communication.announcements.form.acknowledgementHelp')}</span>
                            </span>
                        </label>
                        <div className="space-y-1">
                            <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.announcements.form.expiresAt')}</span>
                            <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
                                <LocalizedDatePicker value={expiresDate} onChange={setExpiresDate} min={dayKey(new Date().toISOString())} />
                                <LocalizedTimePicker value={expiresTime} onChange={setExpiresTime} />
                            </div>
                            {errors.expires_at && <p className="text-xs text-[color:var(--color-danger,#b42318)]">{errors.expires_at}</p>}
                        </div>
                    </Panel>

                    <div className="space-y-3 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4">
                        {canPublish ? (
                            <>
                                {scheduling && (
                                    <div className="space-y-1">
                                        <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.announcements.form.publishAt')}</span>
                                        <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
                                            <LocalizedDatePicker value={publishDate} onChange={setPublishDate} min={dayKey(new Date().toISOString())} />
                                            <LocalizedTimePicker value={publishTime} onChange={setPublishTime} />
                                        </div>
                                        {errors.publish_at && <p className="text-xs text-[color:var(--color-danger,#b42318)]">{errors.publish_at}</p>}
                                    </div>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {scheduling ? (
                                        <button type="button" disabled={saving !== null || !publishDate} onClick={() => submit('schedule')()} className={buttonClassName({ variant: 'primary' })}>
                                            {t('communication.announcements.form.schedule')}
                                        </button>
                                    ) : (
                                        <button type="button" disabled={saving !== null} onClick={() => submit('publish')()} className={buttonClassName({ variant: 'primary' })}>
                                            {t('communication.announcements.form.publishNow')}
                                        </button>
                                    )}
                                    <button type="button" onClick={() => setScheduling((v) => !v)} className={buttonClassName({ variant: 'secondary' })}>
                                        {scheduling ? t('communication.announcements.form.publishNow') : t('communication.announcements.form.scheduleFor')}
                                    </button>
                                    <button type="submit" disabled={saving !== null} className={buttonClassName({ variant: 'ghost' })}>
                                        {t('communication.announcements.form.saveDraft')}
                                    </button>
                                </div>
                            </>
                        ) : (
                            <>
                                <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('communication.announcements.form.publisherNote')}</p>
                                <button type="submit" disabled={saving !== null} className={buttonClassName({ variant: 'primary' })}>{t('communication.announcements.form.saveDraft')}</button>
                            </>
                        )}
                        {errors.announcement && <p className="text-xs text-[color:var(--color-danger,#b42318)]">{errors.announcement}</p>}
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

function Panel({ title, hint, children }: { title: string; hint?: string; children: React.ReactNode }): JSX.Element {
    return (
        <section className="space-y-3 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-4">
            <div>
                <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{title}</h2>
                {hint && <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{hint}</p>}
            </div>
            {children}
        </section>
    );
}

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: React.ReactNode }): JSX.Element {
    return (
        <label className="block space-y-1">
            <span className="text-xs font-medium text-[color:var(--app-foreground)]">{label}</span>
            {children}
            {hint && !error && <span className="block text-[11px] text-[color:var(--app-muted-foreground)]">{hint}</span>}
            {error && <span className="block text-xs text-[color:var(--color-danger,#b42318)]">{error}</span>}
        </label>
    );
}

/** Pick recipients by kind; everything is searched, never listed whole. */
function TargetBuilder({ types, value, onChange }: { types: AnnouncementTargetType[]; value: TargetLabel[]; onChange: (targets: TargetLabel[]) => void }): JSX.Element {
    const { t } = useLocale();
    const [type, setType] = useState<AnnouncementTargetType>(types.includes('ORGANIZATION') ? 'ORGANIZATION' : types[0]);
    const [org, setOrg] = useState<TargetOption | null>(null);
    const add = (target: TargetLabel) => {
        if (!value.some((x) => x.type === target.type && x.id === target.id)) onChange([...value, target]);
    };

    return (
        <div className="space-y-3">
            {value.length === 0 ? (
                <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('communication.announcements.form.noTargets')}</p>
            ) : (
                <ul className="flex flex-wrap gap-1.5">
                    {value.map((target) => (
                        <li key={`${target.type}:${target.id}`} className="inline-flex max-w-full items-center gap-1.5 rounded-full border border-[color:var(--app-border)] bg-[color:var(--app-surface-muted)] py-1 ps-2.5 pe-1 text-xs text-[color:var(--app-foreground)]">
                            <span className="truncate"><span className="text-[color:var(--app-muted-foreground)]">{t(`communication.announcements.targetTypes.${target.type}`)}:</span> {target.type === 'CITY' ? '' : target.label}</span>
                            <button type="button" aria-label={`${t('communication.announcements.form.remove')} ${target.label}`} onClick={() => onChange(value.filter((x) => x !== target))} className="rounded-full p-0.5 text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]">
                                <XMarkIcon className="h-3.5 w-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="space-y-2 rounded-control border border-dashed border-[color:var(--app-border-strong)] p-3">
                <label className="block space-y-1">
                    <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.announcements.form.targetType')}</span>
                    <select value={type} onChange={(e) => { setType(e.target.value as AnnouncementTargetType); setOrg(null); }} className="h-9 w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-2.5 text-sm text-[color:var(--app-foreground)]">
                        {types.map((value2) => <option key={value2} value={value2}>{t(`communication.announcements.targetTypes.${value2}`)}</option>)}
                    </select>
                </label>
                {type === 'CITY' ? (
                    <button type="button" onClick={() => add({ type: 'CITY', id: null, label: t('communication.announcements.targetTypes.CITY') })} className={buttonClassName({ variant: 'secondary', size: 'sm', className: 'gap-1.5' })}>
                        <PlusIcon className="h-4 w-4" aria-hidden="true" />{t('communication.announcements.form.addTarget')}
                    </button>
                ) : type === 'ORGANIZATION_UNIT' ? (
                    <>
                        <OptionSearch key="unit-org" type="ORGANIZATION" placeholder={org ? org.name : t('communication.announcements.form.organizationFirst')} onPick={setOrg} />
                        {org && <OptionSearch key={`unit-${org.id}`} type="ORGANIZATION_UNIT" organizationId={org.id} placeholder={t('communication.announcements.form.choose')} onPick={(o) => add({ type, id: o.id, label: `${o.name} — ${org.name}` })} />}
                    </>
                ) : (
                    <OptionSearch key={type} type={type} placeholder={t('communication.announcements.form.choose')} onPick={(o) => add({ type, id: o.id, label: o.name })} />
                )}
            </div>
        </div>
    );
}

function OptionSearch({ type, organizationId, placeholder, onPick }: { type: AnnouncementTargetType; organizationId?: string; placeholder: string; onPick: (option: TargetOption) => void }): JSX.Element {
    const { t } = useLocale();
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const [options, setOptions] = useState<TargetOption[] | null>(null);
    const needsTerm = type === 'EMPLOYEE';

    useEffect(() => {
        if (!open || (needsTerm && q.trim().length < 2)) { setOptions(null); return; }
        let cancelled = false;
        const timer = window.setTimeout(() => {
            communicationApi.targetOptions(type, q.trim(), organizationId).then((rows) => { if (!cancelled) setOptions(rows); }).catch(() => setOptions([]));
        }, 250);
        return () => { cancelled = true; window.clearTimeout(timer); };
    }, [q, open, type, organizationId, needsTerm]);

    return (
        <div className="relative">
            <SearchIcon className="pointer-events-none absolute start-2.5 top-[1.125rem] h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
            <input
                type="search"
                value={q}
                placeholder={placeholder}
                aria-label={placeholder}
                onFocus={() => setOpen(true)}
                onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                onChange={(e) => { setQ(e.target.value); setOpen(true); }}
                className="h-9 w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] ps-8 pe-2 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15"
            />
            {open && options !== null && (
                <ul className="comm-scroll absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-surface)] py-1 shadow-lg">
                    {options.length === 0 && <li className="px-3 py-2 text-xs text-[color:var(--app-muted-foreground)]">{t('communication.newConversation.noResults')}</li>}
                    {options.map((option) => (
                        <li key={option.id}>
                            <button type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => { onPick(option); setQ(''); setOpen(false); }}
                                className="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-start text-sm text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]">
                                <span className="truncate">{option.name}</span>
                                {option.hint && <span className="shrink-0 text-[11px] text-[color:var(--app-muted-foreground)]">{option.hint}</span>}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
