import Modal from '@/Components/Modal';
import { Button, FieldError, FormField, Input, Select, Textarea } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type CatalogEntry = { id?: string; code?: string; name_en?: string; name_am?: string | null; description_en?: string | null; description_am?: string | null; rank?: number | null; sort_order?: number | null; is_active?: boolean; occupation_id?: string | null; competency_type_id?: string | null };
export type CatalogOption = { id: string; code: string; name_en: string; name_am: string | null; isco_code?: string | null };

/**
 * One modal for the small catalog entries of the people side of the design:
 * professions, specializations, competency types, competency levels,
 * education levels and framework competencies. `fields` says which inputs
 * the entry has beyond code, names and the active flag.
 */
export default function CatalogEntryForm({ title, record, url, method, fields, options, onClose }: {
    title: string;
    record: CatalogEntry | null;
    url: string;
    method: 'post' | 'put';
    fields: { description?: boolean; rank?: boolean; rankMax?: number; sortOrder?: boolean; occupation?: boolean; competencyType?: boolean };
    options?: { occupations?: CatalogOption[]; competencyTypes?: CatalogOption[] };
    onClose: () => void;
}) {
    const { locale, tt } = useDesignText();
    const form = useForm({
        code: record?.code ?? '', name_en: record?.name_en ?? '', name_am: record?.name_am ?? '', description_en: record?.description_en ?? '', description_am: record?.description_am ?? '',
        rank: record?.rank == null ? '' : String(record.rank), sort_order: String(record?.sort_order ?? 0), is_active: record?.is_active ?? true,
        occupation_id: record?.occupation_id ?? '', competency_type_id: record?.competency_type_id ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => {
            const out: Record<string, unknown> = { code: data.code, name_en: data.name_en, name_am: data.name_am || null, is_active: data.is_active };
            if (fields.description) { out.description_en = data.description_en || null; out.description_am = data.description_am || null; }
            if (fields.rank) out.rank = data.rank === '' ? null : Number(data.rank);
            if (fields.sortOrder) out.sort_order = Number(data.sort_order || 0);
            if (fields.occupation) out.occupation_id = data.occupation_id || null;
            if (fields.competencyType) out.competency_type_id = data.competency_type_id || null;
            return out;
        });
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (method === 'put') form.put(url, opts);
        else form.post(url, opts);
    }

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{title}</h2>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    {fields.occupation && (
                        <FormField id="cat-occupation" label={tt('fields.occupation')} required className="sm:col-span-2" error={errors.occupation_id}>
                            {({ id }) => (
                                <Select id={id} value={form.data.occupation_id} onChange={(e) => form.setData('occupation_id', e.target.value)}>
                                    <option value="">—</option>
                                    {(options?.occupations ?? []).map((o) => <option key={o.id} value={o.id}>{o.isco_code ?? o.code} · {nameOf(o, locale)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField id="cat-code" label={tt('fields.code')} required error={errors.code}>{({ id }) => <Input id={id} value={form.data.code} maxLength={40} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}</FormField>
                    {fields.rank && <FormField id="cat-rank" label={tt('fields.rank')} required error={errors.rank}>{({ id }) => <Input id={id} type="number" min={1} max={fields.rankMax ?? 10} value={form.data.rank} onChange={(e) => form.setData('rank', e.target.value)} />}</FormField>}
                    {fields.sortOrder && <FormField id="cat-sort" label={tt('fields.sortOrder')} error={errors.sort_order}>{({ id }) => <Input id={id} type="number" min={0} value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} />}</FormField>}
                    {fields.competencyType && (
                        <FormField id="cat-type" label={tt('fields.competencyType')} error={errors.competency_type_id}>
                            {({ id }) => (
                                <Select id={id} value={form.data.competency_type_id} onChange={(e) => form.setData('competency_type_id', e.target.value)}>
                                    <option value="">{tt('competencyCatalog.untyped')}</option>
                                    {(options?.competencyTypes ?? []).map((t) => <option key={t.id} value={t.id}>{nameOf(t, locale)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
                    <FormField id="cat-name-en" label={tt('fields.nameEn')} required error={errors.name_en}>{({ id }) => <Input id={id} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />}</FormField>
                    <FormField id="cat-name-am" label={tt('fields.nameAm')} error={errors.name_am}>{({ id }) => <Input id={id} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />}</FormField>
                    {fields.description && (
                        <>
                            <FormField id="cat-desc-en" label={tt('fields.descriptionEn')} className="sm:col-span-2" error={errors.description_en}>{({ id }) => <Textarea id={id} rows={2} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}</FormField>
                            <FormField id="cat-desc-am" label={tt('fields.descriptionAm')} className="sm:col-span-2" error={errors.description_am}>{({ id }) => <Textarea id={id} rows={2} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}</FormField>
                        </>
                    )}
                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" className="rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                        <span>{tt('fields.isActive')}</span>
                    </label>
                </div>
                <FieldError>{errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
