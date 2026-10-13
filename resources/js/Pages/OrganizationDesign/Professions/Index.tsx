import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import CatalogEntryForm, { type CatalogEntry, type CatalogOption } from '@/Components/organizationDesign/CatalogEntryForm';
import { StatusPill, nameOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Alert, Button, Card, CardHeader, CardTitle, FilterField, FilterPanel, Input, ListPagination, Select, actionLinkClassName, type LaravelPageMeta } from '@/Components/ui';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Specialization = CatalogEntry & { id: string; code: string; name_en: string; is_active: boolean };
type Profession = CatalogEntry & { id: string; code: string; name_en: string; is_active: boolean; specializations: Specialization[] };
type OccupationRow = { id: string; code: string; isco_code: string | null; name_en: string | null; name_am: string | null; is_active: boolean; isco_group: { code: string; revision: string; name_en: string; name_am: string | null } | null; professions: Profession[] };

type Props = {
    occupations: LaravelPageMeta & { data: OccupationRow[] };
    filters: { occupation_id?: string; search?: string };
    occupationOptions: CatalogOption[];
    can: { manage: boolean };
};

type Editing =
    | { kind: 'profession'; record: Profession | null; occupationId: string }
    | { kind: 'specialization'; record: Specialization | null; professionId: string }
    | null;

export default function ProfessionsIndex({ occupations, filters, occupationOptions, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const [draft, setDraft] = useState({ occupation_id: filters.occupation_id ?? '', search: filters.search ?? '' });
    const [editing, setEditing] = useState<Editing>(null);
    const hasActiveFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout header={<PageHeader title={tt('professions.title')} description={tt('professions.description')}
            actions={can.manage && <Button variant="primary" onClick={() => setEditing({ kind: 'profession', record: null, occupationId: filters.occupation_id ?? '' })}>{tt('professions.addProfession')}</Button>} />}>
            <Head title={tt('professions.title')} />

            <div className="space-y-4">
                {!can.manage && <Alert tone="info">{tt('professions.readOnly')}</Alert>}
                <FilterPanel
                    onSubmit={() => router.get(route('organization-design.professions.index'), Object.fromEntries(Object.entries(draft).filter(([, v]) => v)), { preserveState: true, replace: true })}
                    onReset={() => router.get(route('organization-design.professions.index'))}
                    hasActiveFilters={hasActiveFilters}
                >
                    <FilterField label={t('common.search')} wide>
                        <Input type="search" value={draft.search} placeholder={tt('professions.searchPlaceholder')} onChange={(e) => setDraft({ ...draft, search: e.target.value })} />
                    </FilterField>
                    <FilterField label={tt('fields.occupation')}>
                        <Select value={draft.occupation_id} onChange={(e) => setDraft({ ...draft, occupation_id: e.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {occupationOptions.map((o) => <option key={o.id} value={o.id}>{o.isco_code ?? o.code} · {nameOf(o, locale)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                {occupations.data.length === 0 ? (
                    <Card><EmptyState title={tt('professions.noOccupations')} description={hasActiveFilters ? t('common.noMatchingRecords') : undefined} /></Card>
                ) : occupations.data.map((o) => (
                    <Card key={o.id}>
                        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <CardTitle><span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{o.isco_code ?? o.code}</span> {nameOf({ name_en: o.name_en ?? o.code, name_am: o.name_am }, locale)}</CardTitle>
                                {o.isco_group && <p className="mt-0.5 text-xs text-[color:var(--app-muted-foreground)]">{tt('professions.iscoReference')}: {o.isco_group.code} · {nameOf(o.isco_group, locale)} ({o.isco_group.revision})</p>}
                            </div>
                            {can.manage && <Button size="sm" variant="outline" onClick={() => setEditing({ kind: 'profession', record: null, occupationId: o.id })}>{tt('professions.addProfession')}</Button>}
                        </CardHeader>
                        {o.professions.length === 0 ? <p className="px-5 py-4 text-sm text-[color:var(--app-muted-foreground)]">{tt('professions.noProfessions')}</p> : (
                            <ul className="divide-y divide-[color:var(--app-border)]">
                                {o.professions.map((p) => (
                                    <li key={p.id} className="px-5 py-3">
                                        <div className="flex flex-wrap items-start gap-3">
                                            <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{p.code}</span>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium text-[color:var(--app-foreground)]">{nameOf(p, locale)}</p>
                                                {(locale === 'am' ? p.description_am : p.description_en) && <p className="text-xs text-[color:var(--app-muted-foreground)]">{locale === 'am' ? p.description_am : p.description_en}</p>}
                                            </div>
                                            <StatusPill status={p.is_active ? 'active' : 'inactive'} />
                                            {can.manage && (
                                                <span className="flex gap-3">
                                                    <button type="button" className={actionLinkClassName()} onClick={() => setEditing({ kind: 'profession', record: p, occupationId: o.id })}>{t('common.edit')}</button>
                                                    <button type="button" className={actionLinkClassName({ tone: 'neutral' })} onClick={() => setEditing({ kind: 'specialization', record: null, professionId: p.id })}>{tt('professions.addSpecialization')}</button>
                                                </span>
                                            )}
                                        </div>
                                        {p.specializations.length > 0 && (
                                            <ul className="mt-2 space-y-1 border-l-2 border-[color:var(--app-border)] pl-3">
                                                {p.specializations.map((s) => (
                                                    <li key={s.id} className="flex flex-wrap items-center gap-3 text-sm">
                                                        <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{s.code}</span>
                                                        <span className="min-w-0 flex-1">{nameOf(s, locale)}</span>
                                                        <StatusPill status={s.is_active ? 'active' : 'inactive'} />
                                                        {can.manage && <button type="button" className={actionLinkClassName()} onClick={() => setEditing({ kind: 'specialization', record: s, professionId: p.id })}>{t('common.edit')}</button>}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                ))}
                <ListPagination meta={occupations} />
            </div>

            {editing?.kind === 'profession' && (
                <CatalogEntryForm
                    title={editing.record ? tt('professions.editProfession') : tt('professions.addProfession')}
                    record={editing.record ? { ...editing.record, occupation_id: editing.occupationId } : { occupation_id: editing.occupationId }}
                    url={editing.record ? route('organization-design.professions.update', editing.record.id) : route('organization-design.professions.store')}
                    method={editing.record ? 'put' : 'post'}
                    fields={{ description: true, sortOrder: true, occupation: true }}
                    options={{ occupations: occupationOptions }}
                    onClose={() => setEditing(null)}
                />
            )}
            {editing?.kind === 'specialization' && (
                <CatalogEntryForm
                    title={editing.record ? tt('professions.editSpecialization') : tt('professions.addSpecialization')}
                    record={editing.record}
                    url={editing.record ? route('organization-design.specializations.update', editing.record.id) : route('organization-design.professions.specializations.store', editing.professionId)}
                    method={editing.record ? 'put' : 'post'}
                    fields={{ description: true, sortOrder: true }}
                    onClose={() => setEditing(null)}
                />
            )}
        </AuthenticatedLayout>
    );
}
