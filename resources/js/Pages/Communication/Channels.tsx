import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import { communicationApi, firstError, statusOf } from '@/lib/communication/api';
import { tr } from '@/lib/communication/format';
import { HashIcon, PlusIcon, SearchIcon, XMarkIcon } from '@/Components/NavIcons';
import type { PageProps } from '@/types';
import type { ChannelPage, ChannelRow, ChannelType, PostingPolicy, ScopeOption } from '@/types/communication';

type Props = PageProps<{
    initial: ChannelPage;
    can: { createManaged: boolean; createSystem: boolean; createOwn: boolean };
    types: ChannelType[];
    postingPolicies: PostingPolicy[];
}>;

const messagesUrl = (conversationId: string) => route('communication.messages.show', conversationId);

export default function Channels({ initial, can }: Props): JSX.Element {
    const { t } = useLocale();
    const [filter, setFilter] = useState<'all' | 'mine'>('all');
    const [q, setQ] = useState('');
    const [page, setPage] = useState<ChannelPage>(initial);
    const [loading, setLoading] = useState(false);
    const [creating, setCreating] = useState(false);
    const [joining, setJoining] = useState<string | null>(null);
    const first = useRef(true);
    const canCreate = can.createManaged || can.createOwn;

    useEffect(() => {
        if (first.current) { first.current = false; return; }
        let cancelled = false;
        setLoading(true);
        const timer = window.setTimeout(() => {
            communicationApi.channels({ filter, q: q.trim().length >= 2 ? q.trim() : null })
                .then((result) => { if (!cancelled) setPage(result); })
                .catch(() => undefined)
                .finally(() => { if (!cancelled) setLoading(false); });
        }, q ? 250 : 0);
        return () => { cancelled = true; window.clearTimeout(timer); };
    }, [filter, q]);

    const more = async () => {
        if (!page.next_cursor) return;
        setLoading(true);
        try {
            const next = await communicationApi.channels({ filter, q: q.trim().length >= 2 ? q.trim() : null, cursor: page.next_cursor });
            setPage((current) => ({ data: [...current.data, ...next.data], next_cursor: next.next_cursor }));
        } finally {
            setLoading(false);
        }
    };

    const join = async (row: ChannelRow) => {
        setJoining(row.id);
        try {
            const conversationId = await communicationApi.joinChannel(row.id);
            router.visit(messagesUrl(conversationId));
        } catch (reason) {
            toast.error(firstError(reason) ?? t('communication.errors.generic'));
            setJoining(null);
        }
    };

    const actions = canCreate && !creating ? (
        <button type="button" onClick={() => setCreating(true)} className={buttonClassName({ variant: 'primary', className: 'gap-1.5' })}>
            <PlusIcon className="h-4 w-4" aria-hidden="true" />{t('communication.channels.create')}
        </button>
    ) : null;

    const empty = q.trim().length >= 2 ? t('communication.channels.emptySearch') : filter === 'mine' ? t('communication.channels.emptyMine') : t('communication.channels.empty');

    return (
        <AuthenticatedLayout mode="communication" header={<PageHeader title={t('communication.channels.title')} description={t('communication.channels.description')} actions={actions} />}>
            <Head title={t('communication.channels.title')} />

            <div className="space-y-5">
                {creating && <CreateChannel can={can} onCancel={() => setCreating(false)} />}

                <section className="overflow-hidden rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    <div className="flex flex-col gap-3 border-b border-[color:var(--app-border)] p-3 sm:flex-row sm:items-center">
                        <div role="tablist" aria-label={t('communication.channels.title')} className="flex gap-1">
                            {(['all', 'mine'] as const).map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="tab"
                                    aria-selected={filter === value}
                                    onClick={() => setFilter(value)}
                                    className={`h-8 rounded-full px-3 text-xs font-medium transition-colors ${filter === value ? 'bg-[color:var(--app-foreground)] text-[color:var(--app-background)]' : 'text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)] hover:text-[color:var(--app-foreground)]'}`}
                                >
                                    {t(`communication.channels.tabs.${value}`)}
                                </button>
                            ))}
                        </div>
                        <label className="relative block sm:ms-auto sm:w-72">
                            <span className="sr-only">{t('communication.channels.search')}</span>
                            <SearchIcon className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                            <input
                                type="search"
                                value={q}
                                onChange={(event) => setQ(event.target.value)}
                                placeholder={t('communication.channels.search')}
                                className="h-9 w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] ps-8 pe-2 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15"
                            />
                        </label>
                    </div>

                    <div aria-busy={loading}>
                        {page.data.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                                <HashIcon className="h-6 w-6 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                                <p className="max-w-sm text-sm text-[color:var(--app-muted-foreground)]">{loading ? '…' : empty}</p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-[color:var(--app-border)]">
                                {page.data.map((row) => (
                                    <li key={row.id} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center">
                                        <div className="flex min-w-0 flex-1 items-start gap-3">
                                            <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]">
                                                <HashIcon className="h-4 w-4" aria-hidden="true" />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-[color:var(--app-foreground)]">{row.name}</p>
                                                <p className="text-xs text-[color:var(--app-muted-foreground)]">
                                                    {[
                                                        t(`communication.channels.types.${row.type}`),
                                                        [row.unit, row.organization].filter(Boolean).join(' · ') || (row.type === 'PUBLIC_ORGANIZATION' || row.type === 'SYSTEM_MANAGED' ? t('communication.channels.cityWide') : null),
                                                        tr(t('communication.channels.memberCount'), { n: row.member_count }),
                                                        row.auto_membership ? t('communication.channels.automatic') : null,
                                                        row.posting_policy === 'ADMINS_ONLY' ? t('communication.channels.adminsPost') : null,
                                                    ].filter(Boolean).join(' · ')}
                                                </p>
                                                {row.description && <p className="mt-1 line-clamp-2 text-xs text-[color:var(--app-foreground)]/80">{row.description}</p>}
                                            </div>
                                        </div>
                                        <div className="shrink-0 sm:ps-3">
                                            {row.joined ? (
                                                <Link href={messagesUrl(row.conversation_id)} className={buttonClassName({ variant: 'secondary', size: 'sm' })}>{t('communication.channels.open')}</Link>
                                            ) : row.can_join ? (
                                                <button type="button" disabled={joining !== null} onClick={() => void join(row)} className={buttonClassName({ variant: 'primary', size: 'sm' })}>
                                                    {t('communication.channels.join')}
                                                </button>
                                            ) : null}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {page.next_cursor && (
                            <div className="border-t border-[color:var(--app-border)] p-3">
                                <button type="button" disabled={loading} onClick={() => void more()} className="h-8 w-full rounded-control text-xs font-medium text-[color:var(--color-primary)] hover:bg-[color:var(--app-surface-muted)] disabled:opacity-50">
                                    {t('communication.list.loadMore')}
                                </button>
                            </div>
                        )}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function CreateChannel({ can, onCancel }: { can: Props['can']; onCancel: () => void }): JSX.Element {
    const { t } = useLocale();
    const managed = can.createManaged;
    const allowedTypes: ChannelType[] = managed
        ? ['PUBLIC_ORGANIZATION', 'PRIVATE', 'PROJECT', ...(can.createSystem ? ['SYSTEM_MANAGED' as const] : [])]
        : ['PRIVATE', 'PROJECT'];
    const [name, setName] = useState('');
    const [nameAm, setNameAm] = useState('');
    const [description, setDescription] = useState('');
    const [type, setType] = useState<ChannelType>(allowedTypes[0]);
    const [org, setOrg] = useState<ScopeOption | null>(null);
    const [unit, setUnit] = useState<ScopeOption | null>(null);
    const [descendants, setDescendants] = useState(false);
    const [auto, setAuto] = useState(true);
    const [removeOnTransfer, setRemoveOnTransfer] = useState(true);
    const [policy, setPolicy] = useState<PostingPolicy>('ALL_MEMBERS');
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const scoped = managed && (type === 'PUBLIC_ORGANIZATION' || type === 'SYSTEM_MANAGED');
    const field = 'w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-2.5 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15';

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const created = await communicationApi.createChannel({
                name: name.trim(),
                name_am: nameAm.trim() || null,
                description: description.trim() || null,
                type,
                organization_id: managed ? org?.id ?? null : null,
                organization_unit_id: scoped ? unit?.id ?? null : null,
                include_descendants: scoped && descendants,
                auto_membership: scoped && auto,
                remove_on_transfer: removeOnTransfer,
                posting_policy: type === 'SYSTEM_MANAGED' ? 'ADMINS_ONLY' : policy,
            });
            toast.success(t('communication.channels.form.created'));
            router.visit(messagesUrl(created.conversation_id));
        } catch (reason) {
            const data = (reason as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors;
            setErrors(data ? Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v[0]])) : { name: statusOf(reason) === 403 ? t('communication.errors.forbidden') : t('communication.errors.generic') });
            setSaving(false);
        }
    };

    return (
        <section aria-labelledby="new-channel-title" className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
            <h2 id="new-channel-title" className="border-b border-[color:var(--app-border)] px-4 py-3 text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.channels.createTitle')}</h2>
            <form onSubmit={submit} className="grid gap-5 p-4 lg:grid-cols-2">
                <div className="space-y-4">
                    <Field label={t('communication.channels.form.name')} error={errors.name}>
                        <input value={name} onChange={(e) => setName(e.target.value)} required maxLength={80} autoFocus className={`${field} h-9`} />
                    </Field>
                    <Field label={t('communication.channels.form.nameAm')} error={errors.name_am}>
                        <input value={nameAm} onChange={(e) => setNameAm(e.target.value)} maxLength={80} lang="am" className={`${field} h-9`} />
                    </Field>
                    <Field label={t('communication.channels.form.description')} error={errors.description}>
                        <textarea value={description} onChange={(e) => setDescription(e.target.value)} maxLength={1000} rows={3} className={`${field} resize-none py-2`} />
                    </Field>
                </div>

                <div className="space-y-4">
                    <fieldset className="space-y-2">
                        <legend className="mb-1 text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.channels.form.type')}</legend>
                        {allowedTypes.map((value) => (
                            <label key={value} className={`flex cursor-pointer items-start gap-2.5 rounded-control border px-3 py-2 ${type === value ? 'border-[color:var(--color-primary)] bg-[color:color-mix(in_srgb,var(--color-primary)_7%,var(--app-surface))]' : 'border-[color:var(--app-border)]'}`}>
                                <input type="radio" name="type" value={value} checked={type === value} onChange={() => setType(value)} className="mt-0.5 h-4 w-4 text-[color:var(--color-primary)]" />
                                <span>
                                    <span className="block text-sm font-medium text-[color:var(--app-foreground)]">{t(`communication.channels.types.${value}`)}</span>
                                    <span className="block text-xs text-[color:var(--app-muted-foreground)]">{t(`communication.channels.typeHelp.${value}`)}</span>
                                </span>
                            </label>
                        ))}
                        {errors.type && <p className="text-xs text-[color:var(--color-danger,#b42318)]">{errors.type}</p>}
                    </fieldset>

                    {managed ? (
                        <>
                            <ScopePicker
                                label={t('communication.channels.form.organization')}
                                placeholder={t('communication.channels.form.organizationSearch')}
                                none={t('communication.channels.form.organizationNone')}
                                value={org}
                                onChange={(value) => { setOrg(value); setUnit(null); }}
                                search={(term) => communicationApi.scopeOrganizations(term)}
                                error={errors.organization_id}
                            />
                            {scoped && org && (
                                <ScopePicker
                                    label={t('communication.channels.form.unit')}
                                    placeholder={t('communication.channels.form.unitSearch')}
                                    none={t('communication.channels.form.unitNone')}
                                    value={unit}
                                    onChange={setUnit}
                                    search={(term) => communicationApi.scopeUnits(org.id, term)}
                                    error={errors.organization_unit_id}
                                />
                            )}
                            {scoped && (
                                <div className="space-y-2">
                                    <Check checked={descendants} onChange={setDescendants} label={t('communication.channels.form.includeDescendants')} />
                                    <Check checked={auto} onChange={setAuto} label={t('communication.channels.form.autoMembership')} help={t('communication.channels.form.autoHelp')} />
                                    {auto && <Check checked={removeOnTransfer} onChange={setRemoveOnTransfer} label={t('communication.channels.form.removeOnTransfer')} />}
                                </div>
                            )}
                        </>
                    ) : (
                        <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('communication.channels.form.ownChannel')}</p>
                    )}

                    {type !== 'SYSTEM_MANAGED' && (
                        <fieldset className="space-y-1.5">
                            <legend className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.channels.form.postingPolicy')}</legend>
                            {(['ALL_MEMBERS', 'ADMINS_ONLY'] as const).map((value) => (
                                <label key={value} className="flex items-center gap-2 text-sm text-[color:var(--app-foreground)]">
                                    <input type="radio" name="posting_policy" value={value} checked={policy === value} onChange={() => setPolicy(value)} className="h-4 w-4 text-[color:var(--color-primary)]" />
                                    {t(`communication.manage.postingPolicies.${value}`)}
                                </label>
                            ))}
                        </fieldset>
                    )}
                </div>

                <div className="flex justify-end gap-2 border-t border-[color:var(--app-border)] pt-4 lg:col-span-2">
                    <button type="button" onClick={onCancel} className={buttonClassName({ variant: 'secondary' })}>{t('communication.channels.cancel')}</button>
                    <button type="submit" disabled={saving || !name.trim()} className={buttonClassName({ variant: 'primary' })}>
                        {saving ? t('communication.channels.form.creating') : t('communication.channels.form.submit')}
                    </button>
                </div>
            </form>
        </section>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }): JSX.Element {
    return (
        <label className="block space-y-1">
            <span className="text-xs font-medium text-[color:var(--app-foreground)]">{label}</span>
            {children}
            {error && <span className="block text-xs text-[color:var(--color-danger,#b42318)]">{error}</span>}
        </label>
    );
}

function Check({ checked, onChange, label, help }: { checked: boolean; onChange: (value: boolean) => void; label: string; help?: string }): JSX.Element {
    return (
        <label className="flex items-start gap-2.5 text-sm text-[color:var(--app-foreground)]">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)]" />
            <span>
                {label}
                {help && <span className="block text-xs text-[color:var(--app-muted-foreground)]">{help}</span>}
            </span>
        </label>
    );
}

/** Search-to-pick for an organization or unit; the empty choice is explicit ("city-wide", "whole organization"). */
function ScopePicker({ label, placeholder, none, value, onChange, search, error }: {
    label: string; placeholder: string; none: string; value: ScopeOption | null; onChange: (value: ScopeOption | null) => void;
    search: (term: string) => Promise<ScopeOption[]>; error?: string;
}): JSX.Element {
    const [q, setQ] = useState('');
    const [options, setOptions] = useState<ScopeOption[]>([]);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!open) return;
        let cancelled = false;
        const timer = window.setTimeout(() => {
            search(q.trim()).then((rows) => { if (!cancelled) setOptions(rows); }).catch(() => undefined);
        }, 250);
        return () => { cancelled = true; window.clearTimeout(timer); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q, open]);

    return (
        <div className="space-y-1">
            <span className="text-xs font-medium text-[color:var(--app-foreground)]">{label}</span>
            {value && !open ? (
                <div className="flex h-9 items-center justify-between gap-2 rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-2.5 text-sm">
                    <span className="truncate text-[color:var(--app-foreground)]">{value.name}</span>
                    <button type="button" onClick={() => { onChange(null); setOpen(true); }} aria-label={`${label}: ${none}`} className="rounded p-0.5 text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]"><XMarkIcon className="h-4 w-4" /></button>
                </div>
            ) : (
                <div className="relative">
                    <input
                        type="search"
                        value={q}
                        onFocus={() => setOpen(true)}
                        onChange={(e) => { setQ(e.target.value); setOpen(true); }}
                        placeholder={value ? value.name : placeholder}
                        className="h-9 w-full rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-2.5 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/15"
                    />
                    {open && (
                        <ul className="comm-scroll absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-surface)] py-1 shadow-lg">
                            <li>
                                <button type="button" onClick={() => { onChange(null); setOpen(false); setQ(''); }} className="w-full px-3 py-1.5 text-start text-sm italic text-[color:var(--app-muted-foreground)] hover:bg-[color:var(--app-surface-muted)]">{none}</button>
                            </li>
                            {options.map((option) => (
                                <li key={option.id}>
                                    <button type="button" onClick={() => { onChange(option); setOpen(false); setQ(''); }} className="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-start text-sm text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]">
                                        <span className="truncate">{option.name}</span>
                                        {option.code && <span className="shrink-0 text-[11px] text-[color:var(--app-muted-foreground)]">{option.code}</span>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
            {!value && !open && <p className="text-[11px] text-[color:var(--app-muted-foreground)]">{none}</p>}
            {error && <p className="text-xs text-[color:var(--color-danger,#b42318)]">{error}</p>}
        </div>
    );
}
