import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { ListPagination } from '@/Components/ui';
import { errorCls, inputCls, labelCls, panelCls, primaryBtn, scrollCls, secondaryBtn, tableCls, tdCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Named, NamedWithId, Paginated, ReportingLevel } from '@/types/serviceMonitoring';
import { Deferred, Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type Row = {
    id: string;
    level: ReportingLevel;
    organization: NamedWithId;
    parent_participant_id: string | null;
    parent: Named | null;
    active_from: string;
    active_to: string | null;
    support_officers: { id: number; name: string }[];
};
type Proposal = { organization_id: string; name_en: string | null; name_am: string | null; level: ReportingLevel; parent_name_en: string | null; applicable: boolean; reason: string };

interface Props {
    participants: Paginated<Row>;
    filters: { level: string; q: string };
    sub_cities: NamedWithId[];
    organizations: NamedWithId[];
    support_candidates: { id: number; name: string }[];
    suggestions?: Proposal[] | null;
    can: { manage: boolean };
}

const LEVELS: ReportingLevel[] = ['center', 'sub_city', 'woreda'];

/** The participant registry (D7): who reports, at which level, through which sub-city, with which support officers. */
export default function ParticipantsIndex({ participants, filters, sub_cities, organizations, support_candidates, suggestions, can }: Props): JSX.Element {
    const { sm, name } = useSm();
    const [editing, setEditing] = useState<Row | 'new' | null>(null);
    const [support, setSupport] = useState<Row | null>(null);
    const [picked, setPicked] = useState<string[]>([]);
    const form = useForm({ organization_id: '', level: 'center' as ReportingLevel, parent_participant_id: '', active_from: new Date().toISOString().slice(0, 10), active_to: '' });
    const supportForm = useForm<{ user_ids: number[] }>({ user_ids: [] });
    const apply = useForm({ active_from: new Date().toISOString().slice(0, 10), organization_ids: [] as string[] });

    function open(row: Row | null) {
        form.clearErrors();
        form.setData(row
            ? { organization_id: row.organization.id, level: row.level, parent_participant_id: row.parent_participant_id ?? '', active_from: row.active_from, active_to: row.active_to ?? '' }
            : { organization_id: '', level: 'center', parent_participant_id: '', active_from: new Date().toISOString().slice(0, 10), active_to: '' });
        setEditing(row ?? 'new');
    }

    function save(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };
        form.transform((d) => ({ ...d, parent_participant_id: d.level === 'woreda' ? d.parent_participant_id : null, active_to: d.active_to || null, ...(editing === 'new' ? {} : { organization_id: undefined }) }));
        if (editing === 'new') form.post(route('service-monitoring.participants.store'), options);
        else if (editing) form.put(route('service-monitoring.participants.update', editing.id), options);
    }

    function saveSupport(event: FormEvent) {
        event.preventDefault();
        if (support) supportForm.put(route('service-monitoring.participants.support', support.id), { preserveScroll: true, onSuccess: () => setSupport(null) });
    }

    function register() {
        apply.transform((d) => ({ ...d, organization_ids: picked }));
        apply.post(route('service-monitoring.participants.suggestions.apply'), { preserveScroll: true, onSuccess: () => setPicked([]) });
    }

    const filter = (changes: Record<string, string>) => router.get(route('service-monitoring.participants.index'), { ...filters, ...changes }, { preserveState: true, preserveScroll: true });

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('participants.title')} description={sm('participants.description')}
            actions={can.manage ? <button type="button" className={primaryBtn} onClick={() => open(null)}>{sm('participants.add')}</button> : undefined} />}>
            <Head title={sm('participants.title')} />
            <div className="space-y-4">
                <div className={`${panelCls} grid gap-3 p-3 sm:grid-cols-2`}>
                    <div>
                        <label htmlFor="sm-q" className={labelCls}>{sm('common.search')}</label>
                        <input id="sm-q" className={inputCls} defaultValue={filters.q} onKeyDown={(e) => { if (e.key === 'Enter') filter({ q: (e.target as HTMLInputElement).value }); }} />
                    </div>
                    <div>
                        <label htmlFor="sm-level" className={labelCls}>{sm('common.level')}</label>
                        <select id="sm-level" className={inputCls} value={filters.level} onChange={(e) => filter({ level: e.target.value })}>
                            <option value="">{sm('common.all')}</option>
                            {LEVELS.map((l) => <option key={l} value={l}>{sm(`level.${l}`)}</option>)}
                        </select>
                    </div>
                </div>

                {editing !== null && (
                    <form onSubmit={save} className={`${panelCls} grid gap-3 p-4 sm:grid-cols-2`} aria-label={sm('participants.add')}>
                        {editing === 'new' && (
                            <div className="sm:col-span-2">
                                <label htmlFor="sm-org" className={labelCls}>{sm('participants.organization')}</label>
                                <select id="sm-org" className={inputCls} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)} required>
                                    <option value="">—</option>
                                    {organizations.map((o) => <option key={o.id} value={o.id}>{name(o)}</option>)}
                                </select>
                                {form.errors.organization_id && <p className={errorCls}>{form.errors.organization_id}</p>}
                            </div>
                        )}
                        <div>
                            <label htmlFor="sm-plevel" className={labelCls}>{sm('common.level')}</label>
                            <select id="sm-plevel" className={inputCls} value={form.data.level} onChange={(e) => form.setData('level', e.target.value as ReportingLevel)}>
                                {LEVELS.map((l) => <option key={l} value={l}>{sm(`level.${l}`)}</option>)}
                            </select>
                        </div>
                        {form.data.level === 'woreda' && (
                            <div>
                                <label htmlFor="sm-parent" className={labelCls}>{sm('participants.parent')}</label>
                                <select id="sm-parent" className={inputCls} value={form.data.parent_participant_id} onChange={(e) => form.setData('parent_participant_id', e.target.value)} required>
                                    <option value="">—</option>
                                    {sub_cities.map((s) => <option key={s.id} value={s.id}>{name(s)}</option>)}
                                </select>
                                {form.errors.parent_participant_id && <p className={errorCls}>{form.errors.parent_participant_id}</p>}
                            </div>
                        )}
                        <div>
                            <label htmlFor="sm-from" className={labelCls}>{sm('participants.activeFrom')}</label>
                            <LocalizedDatePicker id="sm-from" className={inputCls} value={form.data.active_from} onChange={(iso) => form.setData('active_from', iso)} required />
                        </div>
                        <div>
                            <label htmlFor="sm-to" className={labelCls}>{sm('participants.activeTo')}</label>
                            <LocalizedDatePicker id="sm-to" className={inputCls} value={form.data.active_to} onChange={(iso) => form.setData('active_to', iso)} />
                            {form.errors.active_to && <p className={errorCls}>{form.errors.active_to}</p>}
                        </div>
                        <div className="flex gap-2 sm:col-span-2">
                            <button type="submit" className={primaryBtn} disabled={form.processing}>{sm('common.save')}</button>
                            <button type="button" className={secondaryBtn} onClick={() => setEditing(null)}>{sm('common.cancel')}</button>
                        </div>
                    </form>
                )}

                {support && (
                    <form onSubmit={saveSupport} className={`${panelCls} space-y-3 p-4`}>
                        <p className="text-sm font-semibold">{sm('participants.editSupport', { name: name(support.organization) })}</p>
                        <fieldset className="grid gap-1 sm:grid-cols-2">
                            <legend className="sr-only">{sm('participants.support')}</legend>
                            {support_candidates.map((u) => (
                                <label key={u.id} className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={supportForm.data.user_ids.includes(u.id)}
                                        onChange={(e) => supportForm.setData('user_ids', e.target.checked ? [...supportForm.data.user_ids, u.id] : supportForm.data.user_ids.filter((id) => id !== u.id))} />
                                    {u.name}
                                </label>
                            ))}
                        </fieldset>
                        <div className="flex gap-2">
                            <button type="submit" className={primaryBtn} disabled={supportForm.processing}>{sm('common.save')}</button>
                            <button type="button" className={secondaryBtn} onClick={() => setSupport(null)}>{sm('common.cancel')}</button>
                        </div>
                    </form>
                )}

                {participants.data.length === 0 ? <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('participants.none')}</p> : (
                    <div className={scrollCls}>
                        <table className={tableCls}>
                            <thead>
                                <tr>{['participants.organization', 'common.level', 'participants.parent', 'participants.activeFrom', 'participants.activeTo', 'participants.support', 'common.actions'].map((k) => <th key={k} scope="col" className={thCls}>{sm(k)}</th>)}</tr>
                            </thead>
                            <tbody>
                                {participants.data.map((row) => (
                                    <tr key={row.id}>
                                        <td className={tdCls}>{name(row.organization)}</td>
                                        <td className={tdCls}>{sm(`level.${row.level}`)}</td>
                                        <td className={tdCls}>{row.parent ? name(row.parent) : '—'}</td>
                                        <td className={tdCls}><LocalizedDateDisplay value={row.active_from} /></td>
                                        <td className={tdCls}><LocalizedDateDisplay value={row.active_to} /></td>
                                        <td className={tdCls}>{row.support_officers.map((u) => u.name).join(', ') || '—'}</td>
                                        <td className={tdCls}>
                                            {can.manage && (
                                                <div className="flex flex-wrap gap-2">
                                                    <button type="button" className={secondaryBtn} onClick={() => open(row)}>{sm('common.edit')}</button>
                                                    <button type="button" className={secondaryBtn} onClick={() => { supportForm.setData('user_ids', row.support_officers.map((u) => u.id)); setSupport(row); }}>{sm('participants.support')}</button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <ListPagination meta={participants} />
                    </div>
                )}

                {can.manage && (
                    <section className={`${panelCls} space-y-3 p-4`} aria-labelledby="sm-suggestions">
                        <h2 id="sm-suggestions" className="text-sm font-semibold">{sm('participants.suggestions')}</h2>
                        <p className="text-xs text-(--app-muted-foreground)">{sm('participants.suggestionsHint')}</p>
                        <Deferred data="suggestions" fallback={() => <p className="text-sm text-(--app-muted-foreground)">{sm('common.loading')}</p>}>
                            {(suggestions ?? []).length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{sm('participants.noSuggestions')}</p> : (
                                <>
                                    <ul className="divide-y divide-(--app-border) text-sm">
                                        {(suggestions ?? []).map((p) => (
                                            <li key={p.organization_id} className="flex flex-wrap items-center gap-2 py-2">
                                                <input type="checkbox" aria-label={name(p)} disabled={!p.applicable} checked={picked.includes(p.organization_id)}
                                                    onChange={(e) => setPicked(e.target.checked ? [...picked, p.organization_id] : picked.filter((id) => id !== p.organization_id))} />
                                                <span className="font-medium">{name(p)}</span>
                                                <span>{sm(`level.${p.level}`)}{p.parent_name_en ? ` → ${p.parent_name_en}` : ''}</span>
                                                <span className="text-xs text-(--app-muted-foreground)">{p.applicable ? p.reason : sm('participants.notApplicable')}</span>
                                            </li>
                                        ))}
                                    </ul>
                                    <div className="flex flex-wrap items-end gap-2">
                                        <div>
                                            <label htmlFor="sm-apply-from" className={labelCls}>{sm('participants.activeFrom')}</label>
                                            <LocalizedDatePicker id="sm-apply-from" className={inputCls} value={apply.data.active_from} onChange={(iso) => apply.setData('active_from', iso)} />
                                        </div>
                                        <button type="button" className={primaryBtn} disabled={picked.length === 0 || apply.processing} onClick={register}>{sm('participants.register')}</button>
                                    </div>
                                </>
                            )}
                        </Deferred>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
