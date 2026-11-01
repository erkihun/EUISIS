import { useMemo, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import {
    Input, Select, buttonClassName, cx, inputClassName,
    tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName,
    tableHeadClassName, tableHeaderCellClassName, tableRowClassName,
} from '@/Components/ui';

type Profile = {
    id: string;
    name: string;
    mode: 'card' | 'sheet';
    paper: 'a4' | 'letter' | null;
    sides: 'both' | 'front' | 'back';
    duplex_order: 'interleaved' | 'grouped';
    offset_x_mm: number;
    offset_y_mm: number;
    scale_percent: number;
    margin_mm: number;
    gap_mm: number;
    cut_guides: boolean;
    mirror_backs: boolean;
    is_default: boolean;
    notes: string | null;
};
type Item = {
    id: string;
    status: 'pending' | 'printed' | 'spoiled';
    reprint_reason: string | null;
    prepared: boolean;
    card_id: string;
    card_number: string | null;
    employee_number: string | null;
    employee_name: string | null;
    organization: string | null;
};
type Props = {
    batch: {
        id: string;
        batch_number: string;
        status: 'draft' | 'printing' | 'completed' | 'cancelled';
        total_cards: number;
        printed_count: number;
        spoiled_count: number;
        printer_profile_id: string | null;
        printer_notes: string | null;
    };
    items: Item[];
    toPrepare: number;
    profiles: Profile[];
    can: { print: boolean; manageProfiles: boolean; requeue: boolean };
    limits: { chunk: number };
};

const fill = (text: string, params: Record<string, string | number>) =>
    Object.entries(params).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), text);

const BLANK_PROFILE: Omit<Profile, 'id'> = {
    name: '', mode: 'card', paper: null, sides: 'both', duplex_order: 'interleaved',
    offset_x_mm: 0, offset_y_mm: 0, scale_percent: 100, margin_mm: 6, gap_mm: 3,
    cut_guides: true, mirror_backs: true, is_default: false, notes: '',
};

/** Cards per sheet, as CardPrinterProfile::grid computes it for a landscape ID-1 card. */
function perSheet(profile: Omit<Profile, 'id'>): number {
    if (profile.mode !== 'sheet') return 1;
    const [pageW, pageH] = profile.paper === 'letter' ? [215.9, 279.4] : [210, 297];
    const scale = profile.scale_percent / 100;
    const fit = (page: number, card: number) => Math.max(1, Math.floor((page - 2 * profile.margin_mm + profile.gap_mm) / (card * scale + profile.gap_mm)));
    return fit(pageW, 85.6) * fit(pageH, 54);
}

function ProfileForm({ profile, onDone }: { profile: Profile | null; onDone: () => void }) {
    const { t } = useLocale();
    const label = (key: string) => t(`cardPrinting.profiles.${key}`);
    const form = useForm<Omit<Profile, 'id'>>(profile ? { ...profile, notes: profile.notes ?? '' } : BLANK_PROFILE);
    const submit = () => {
        const options = { preserveScroll: true, onSuccess: onDone };
        if (profile) form.patch(route('printer-profiles.update', profile.id), options);
        else form.post(route('printer-profiles.store'), options);
    };
    const number = (key: 'offset_x_mm' | 'offset_y_mm' | 'scale_percent' | 'margin_mm' | 'gap_mm', min: number, max: number, step: number) => (
        <label className="text-xs font-medium">
            {label({ offset_x_mm: 'offsetX', offset_y_mm: 'offsetY', scale_percent: 'scale', margin_mm: 'margin', gap_mm: 'gap' }[key])}
            <Input type="number" min={min} max={max} step={step} value={form.data[key]} onChange={(event) => form.setData(key, Number(event.target.value))} className="mt-1" />
            {form.errors[key] && <span role="alert" className="mt-1 block text-red-600">{form.errors[key]}</span>}
        </label>
    );

    return (
        <form
            className="space-y-3 rounded-lg border border-(--app-border) bg-(--app-surface-muted) p-3"
            onSubmit={(event) => { event.preventDefault(); submit(); }}
        >
            <label className="block text-xs font-medium">
                {label('name')}
                <Input required maxLength={120} value={form.data.name} placeholder={label('namePlaceholder')} onChange={(event) => form.setData('name', event.target.value)} className="mt-1" />
                {form.errors.name && <span role="alert" className="mt-1 block text-red-600">{form.errors.name}</span>}
            </label>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-xs font-medium">
                    {label('mode')}
                    <Select
                        className="mt-1"
                        value={form.data.mode}
                        onChange={(event) => {
                            const mode = event.target.value as Profile['mode'];
                            form.setData((data) => ({ ...data, mode, paper: mode === 'sheet' ? (data.paper ?? 'a4') : null }));
                        }}
                    >
                        <option value="card">{label('mode_card')}</option>
                        <option value="sheet">{label('mode_sheet')}</option>
                    </Select>
                </label>
                {form.data.mode === 'sheet' && (
                    <label className="text-xs font-medium">
                        {label('paper')}
                        <Select className="mt-1" value={form.data.paper ?? 'a4'} onChange={(event) => form.setData('paper', event.target.value as 'a4' | 'letter')}>
                            <option value="a4">{label('paper_a4')}</option>
                            <option value="letter">{label('paper_letter')}</option>
                        </Select>
                    </label>
                )}
                <label className="text-xs font-medium">
                    {label('sides')}
                    <Select className="mt-1" value={form.data.sides} onChange={(event) => form.setData('sides', event.target.value as Profile['sides'])}>
                        {(['both', 'front', 'back'] as const).map((side) => <option key={side} value={side}>{label(`sides_${side}`)}</option>)}
                    </Select>
                </label>
                {form.data.sides === 'both' && (
                    <label className="text-xs font-medium">
                        {label('duplexOrder')}
                        <Select className="mt-1" value={form.data.duplex_order} onChange={(event) => form.setData('duplex_order', event.target.value as Profile['duplex_order'])}>
                            {(['interleaved', 'grouped'] as const).map((order) => <option key={order} value={order}>{label(`duplex_${order}`)}</option>)}
                        </Select>
                    </label>
                )}
            </div>
            <div className="grid gap-3 sm:grid-cols-3">
                {number('offset_x_mm', -20, 20, 0.1)}
                {number('offset_y_mm', -20, 20, 0.1)}
                {number('scale_percent', 80, 120, 1)}
                {form.data.mode === 'sheet' && number('margin_mm', 0, 30, 0.5)}
                {form.data.mode === 'sheet' && number('gap_mm', 0, 20, 0.5)}
            </div>
            {form.data.mode === 'sheet' && (
                <p className="text-xs text-(--app-muted-foreground)">{fill(label('perSheet'), { count: perSheet(form.data) })}</p>
            )}
            <div className="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                {form.data.mode === 'sheet' && (
                    <>
                        <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.cut_guides} onChange={(event) => form.setData('cut_guides', event.target.checked)} />{label('cutGuides')}</label>
                        <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.mirror_backs} onChange={(event) => form.setData('mirror_backs', event.target.checked)} />{label('mirrorBacks')}</label>
                    </>
                )}
                <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.is_default} onChange={(event) => form.setData('is_default', event.target.checked)} />{label('isDefault')}</label>
            </div>
            <label className="block text-xs font-medium">
                {label('notes')}
                <textarea className={cx(inputClassName, 'mt-1 w-full')} rows={2} maxLength={1000} value={form.data.notes ?? ''} onChange={(event) => form.setData('notes', event.target.value)} />
            </label>
            <div className="flex gap-2">
                <button type="submit" className={buttonClassName({ variant: 'primary', size: 'sm' })} disabled={form.processing}>{label('save')}</button>
                <button type="button" className={buttonClassName({ variant: 'outline', size: 'sm' })} onClick={onDone}>{label('cancel')}</button>
            </div>
        </form>
    );
}

export default function Station({ batch, items, toPrepare, profiles, can, limits }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const label = (key: string) => t(`cardPrinting.station.${key}`);
    const profileLabel = (key: string) => t(`cardPrinting.profiles.${key}`);
    const open = batch.status === 'draft' || batch.status === 'printing';

    // ── 1. Prepare, a chunk per request until nothing is left ──────────────
    const [progress, setProgress] = useState<{ total: number; error: string | null } | null>(null);
    function prepare(total: number, before: number) {
        setProgress({ total, error: null });
        router.post(route('print-batches.prepare', batch.id), {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['items', 'toPrepare', 'batch'],
            onSuccess: (page) => {
                const left = Number(page.props.toPrepare ?? 0);
                // Keep going while each round freezes another chunk; a round
                // that makes no progress stops rather than looping.
                if (left > 0 && left < before) prepare(total, left);
                else setProgress(null);
            },
            onError: (errors) => setProgress({ total, error: Object.values(errors).join(' ') }),
        });
    }

    // ── 2. Profile, range, print or download ───────────────────────────────
    const fallbackProfile = profiles.find((profile) => profile.id === batch.printer_profile_id)
        ?? profiles.find((profile) => profile.is_default) ?? null;
    const [profileId, setProfileId] = useState<string>(fallbackProfile?.id ?? '');
    const [editing, setEditing] = useState<Profile | 'new' | null>(null);
    const ready = items.filter((item) => item.status === 'pending' && item.prepared);
    const [from, setFrom] = useState(1);
    // Follows the prepared count until the operator types an end of their own.
    const [toInput, setTo] = useState<number | null>(null);
    const to = toInput ?? Math.max(1, ready.length);
    const rangeQuery = { profile: profileId || undefined, from, to: Math.max(from, to) };
    function chooseProfile(id: string) {
        setProfileId(id);
        router.post(route('print-batches.use-profile', batch.id), { printer_profile_id: id || null }, { preserveScroll: true, preserveState: true, only: ['batch'] });
    }
    async function deleteProfile(profile: Profile) {
        if ((await confirm({ title: profileLabel('confirmDelete'), variant: 'danger' })).confirmed) {
            router.delete(route('printer-profiles.destroy', profile.id), { preserveScroll: true });
        }
    }

    // ── 3. Results, card by card ───────────────────────────────────────────
    const pending = items.filter((item) => item.status === 'pending');
    const [spoiled, setSpoiled] = useState<Record<string, string>>({});
    const results = useForm({ printer_notes: batch.printer_notes ?? '' });
    function saveResults() {
        results.transform((data) => ({
            ...data,
            printed: pending.filter((item) => item.prepared && !(item.id in spoiled)).map((item) => item.id),
            spoiled: Object.entries(spoiled).map(([item_id, reason]) => ({ item_id, reason })),
        }));
        results.post(route('print-batches.results', batch.id), { preserveScroll: true, onSuccess: () => setSpoiled({}) });
    }
    const spoiledInBatch = useMemo(() => items.filter((item) => item.status === 'spoiled').length, [items]);
    const counts = {
        printed: items.filter((item) => item.status === 'printed').length,
        spoiled: spoiledInBatch,
        pending: pending.length,
    };

    const stepCls = 'rounded-panel border border-(--app-border) bg-(--app-surface) p-4';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('print-batches.show', batch.id)}
                    title={`${label('title')} — ${batch.batch_number}`}
                    description={label('description')}
                    actions={<Link href={route('print-batches.show', batch.id)} className={buttonClassName({ variant: 'outline' })}>{label('backToBatch')}</Link>}
                />
            }
        >
            <Head title={`${label('title')} — ${batch.batch_number}`} />
            <p className="mb-4 text-sm text-(--app-muted-foreground)">{fill(label('counts'), counts)}</p>
            {!open && <p role="status" className="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">{label('closed')}</p>}

            <div className="grid gap-4 xl:grid-cols-2">
                {/* 1. Prepare */}
                <section className={stepCls} aria-labelledby="step-prepare">
                    <h2 id="step-prepare" className="text-sm font-semibold">{label('step1')}</h2>
                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{label('step1Help')}</p>
                    {open && can.print && toPrepare > 0 && !progress && (
                        <button type="button" className={buttonClassName({ variant: 'primary', className: 'mt-3' })} onClick={() => prepare(toPrepare, toPrepare)}>
                            {fill(label('prepareAll'), { count: toPrepare })}
                        </button>
                    )}
                    {progress && (
                        <div className="mt-3" role="status">
                            <p className="text-sm">{fill(label('preparing'), { done: progress.total - toPrepare, total: progress.total })}</p>
                            <progress className="mt-1 block h-2 w-full" value={progress.total - toPrepare} max={progress.total} />
                            {progress.error && <p role="alert" className="mt-2 text-sm text-red-600">{progress.error}</p>}
                        </div>
                    )}
                    {toPrepare === 0 && open && <p className="mt-3 text-sm text-emerald-700 dark:text-emerald-300">✓ {label('prepared')}</p>}
                </section>

                {/* 2. Print or download */}
                <section className={stepCls} aria-labelledby="step-print">
                    <h2 id="step-print" className="text-sm font-semibold">{label('step2')}</h2>
                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{label('step2Help')}</p>
                    <div className="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
                        <label className="text-xs font-medium">
                            {label('profile')}
                            <Select className="mt-1" value={profileId} disabled={!can.print} onChange={(event) => chooseProfile(event.target.value)}>
                                <option value="">{label('noProfile')}</option>
                                {profiles.map((profile) => (
                                    <option key={profile.id} value={profile.id}>
                                        {profile.name}{profile.is_default ? ` (${profileLabel('default')})` : ''}
                                    </option>
                                ))}
                            </Select>
                        </label>
                        <label className="text-xs font-medium">
                            {label('rangeFrom')}
                            <Input type="number" className="mt-1 w-20" min={1} max={Math.max(1, ready.length)} value={from} onChange={(event) => setFrom(Math.max(1, Number(event.target.value)))} />
                        </label>
                        <label className="text-xs font-medium">
                            {label('rangeTo')}
                            <Input type="number" className="mt-1 w-20" min={from} max={Math.max(1, ready.length)} value={to} onChange={(event) => setTo(Math.max(1, Number(event.target.value)))} />
                        </label>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <a
                            href={ready.length > 0 && can.print ? route('print-batches.sheet', { batch: batch.id, ...rangeQuery }) : undefined}
                            target="_blank"
                            rel="noopener"
                            aria-disabled={ready.length === 0 || !can.print}
                            className={buttonClassName({ variant: 'primary' })}
                        >
                            {label('openPrint')}
                        </a>
                        <a
                            href={can.print ? route('print-batches.download', { batch: batch.id, from, to: Math.max(from, to) }) : undefined}
                            aria-disabled={!can.print || items.every((item) => !item.prepared && item.status === 'pending')}
                            className={buttonClassName({ variant: 'outline' })}
                        >
                            {label('download')}
                        </a>
                    </div>
                    <p className="mt-2 text-xs text-(--app-muted-foreground)">{label('downloadHelp')}</p>
                    <p className="mt-2 rounded-md bg-(--app-surface-muted) px-3 py-2 text-xs text-(--app-foreground)">{label('printerNote')}</p>

                    {/* Printer profiles, managed where they are used. */}
                    <div className="mt-4 border-t border-(--app-border) pt-3">
                        <div className="flex items-center justify-between gap-2">
                            <h3 className="text-sm font-semibold">{profileLabel('title')}</h3>
                            {can.manageProfiles && editing === null && (
                                <button type="button" className={buttonClassName({ variant: 'outline', size: 'sm' })} onClick={() => setEditing('new')}>{profileLabel('add')}</button>
                            )}
                        </div>
                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{profileLabel('help')}</p>
                        {editing === 'new' && <div className="mt-3"><ProfileForm profile={null} onDone={() => setEditing(null)} /></div>}
                        <ul className="mt-3 space-y-2">
                            {profiles.map((profile) => (
                                <li key={profile.id} className="rounded-md border border-(--app-border) px-3 py-2 text-sm">
                                    {editing !== null && editing !== 'new' && editing.id === profile.id ? (
                                        <ProfileForm profile={profile} onDone={() => setEditing(null)} />
                                    ) : (
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{profile.name}</span>
                                            {profile.is_default && <span className="rounded-full bg-primary/10 px-2 text-xs text-primary">{profileLabel('default')}</span>}
                                            <span className="text-xs text-(--app-muted-foreground)">
                                                {profileLabel(`mode_${profile.mode}`)}
                                                {profile.mode === 'sheet' && ` · ${fill(profileLabel('perSheet'), { count: perSheet(profile) })}`}
                                            </span>
                                            {can.manageProfiles && (
                                                <span className="ms-auto flex gap-1">
                                                    <button type="button" className={buttonClassName({ variant: 'ghost', size: 'xs' })} onClick={() => setEditing(profile)}>{profileLabel('edit')}</button>
                                                    <button type="button" className={buttonClassName({ variant: 'ghost', size: 'xs', className: 'text-red-600' })} onClick={() => deleteProfile(profile)}>{profileLabel('delete')}</button>
                                                </span>
                                            )}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            </div>

            {/* 3. Results */}
            <section className={cx(stepCls, 'mt-4')} aria-labelledby="step-results">
                <div className="flex flex-wrap items-start gap-3">
                    <div>
                        <h2 id="step-results" className="text-sm font-semibold">{label('step3')}</h2>
                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{label('step3Help')}</p>
                    </div>
                    {can.requeue && spoiledInBatch > 0 && (
                        <button
                            type="button"
                            className={buttonClassName({ variant: 'outline', size: 'sm', className: 'ms-auto' })}
                            onClick={() => router.post(route('print-batches.requeue', batch.id))}
                        >
                            {fill(label('requeue'), { count: spoiledInBatch })}
                        </button>
                    )}
                </div>
                <div className={cx(tableContainerClassName, 'mt-3')}>
                    <table className={tableClassName}>
                        <thead className={tableHeadClassName}>
                            <tr>
                                <th className={tableHeaderCellClassName}>#</th>
                                <th className={tableHeaderCellClassName}>{t('cardPrinting.bulk.card')}</th>
                                <th className={tableHeaderCellClassName}>{t('cardPrinting.bulk.employee')}</th>
                                <th className={tableHeaderCellClassName}>{label('printedOk')}</th>
                                <th className={tableHeaderCellClassName}>{label('spoiledReason')}</th>
                            </tr>
                        </thead>
                        <tbody className={tableBodyClassName}>
                            {items.map((item, index) => {
                                const isSpoiled = item.id in spoiled;
                                return (
                                    <tr key={item.id} className={cx(tableRowClassName, isSpoiled && 'bg-red-500/5')}>
                                        <td className={cx(tableCellClassName, 'tabular-nums text-xs text-(--app-muted-foreground)')}>{index + 1}</td>
                                        <td className={cx(tableCellClassName, 'font-mono text-xs')}>{item.card_number}</td>
                                        <td className={tableCellClassName}>
                                            <p className="font-medium">{item.employee_name}</p>
                                            <p className="text-xs text-(--app-muted-foreground)">{item.employee_number} · {item.organization}</p>
                                        </td>
                                        <td className={tableCellClassName}>
                                            {item.status !== 'pending' ? (
                                                <span className={cx('text-xs font-medium', item.status === 'printed' ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-600')}>
                                                    {label(`status_${item.status}`)}
                                                </span>
                                            ) : !item.prepared ? (
                                                <span className="text-xs text-(--app-muted-foreground)">{label('notPrepared')}</span>
                                            ) : (
                                                <input
                                                    type="checkbox"
                                                    aria-label={`${item.card_number} — ${label('printedOk')}`}
                                                    checked={!isSpoiled}
                                                    disabled={!open || !can.print}
                                                    onChange={(event) => setSpoiled((current) => {
                                                        const next = { ...current };
                                                        if (event.target.checked) delete next[item.id]; else next[item.id] = '';
                                                        return next;
                                                    })}
                                                />
                                            )}
                                        </td>
                                        <td className={tableCellClassName}>
                                            {item.status === 'spoiled' && <span className="text-xs">{item.reprint_reason}</span>}
                                            {isSpoiled && (
                                                <Input
                                                    required
                                                    maxLength={255}
                                                    aria-label={`${item.card_number} — ${label('spoiledReason')}`}
                                                    placeholder={label('spoiledPlaceholder')}
                                                    value={spoiled[item.id]}
                                                    onChange={(event) => setSpoiled((current) => ({ ...current, [item.id]: event.target.value }))}
                                                />
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                {open && can.print && pending.some((item) => item.prepared) && (
                    <form
                        className="mt-3 flex flex-wrap items-end gap-3"
                        onSubmit={(event) => { event.preventDefault(); saveResults(); }}
                    >
                        <label className="min-w-64 flex-1 text-xs font-medium">
                            {label('notes')}
                            <Input maxLength={1000} value={results.data.printer_notes} onChange={(event) => results.setData('printer_notes', event.target.value)} className="mt-1" />
                        </label>
                        {Object.keys(spoiled).length > 0 && (
                            <button type="button" className={buttonClassName({ variant: 'ghost', size: 'sm' })} onClick={() => setSpoiled({})}>{label('markAll')}</button>
                        )}
                        <button
                            type="submit"
                            className={buttonClassName({ variant: 'primary' })}
                            disabled={results.processing || Object.values(spoiled).some((reason) => reason.trim() === '')}
                        >
                            {label('saveResults')}
                        </button>
                        {Object.values(results.errors).length > 0 && (
                            <p role="alert" className="w-full text-sm text-red-600">{Object.values(results.errors).join(' ')}</p>
                        )}
                    </form>
                )}
            </section>
        </AuthenticatedLayout>
    );
}
