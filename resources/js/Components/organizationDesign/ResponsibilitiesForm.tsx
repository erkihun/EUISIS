import Modal from '@/Components/Modal';
import { Button, FieldError, FormField, Input, Select, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { nameOf, useDesignText } from './ui';

export type Named = { id: string; code: string; name_en: string; name_am: string | null; depth?: number };
export type ResponsibilityTypeOption = Named & { is_exclusive: boolean; covers_object: boolean };
export type ObjectCatalog = { function: Named[]; service: Named[]; process: Named[]; activity: Named[] };
export type ResponsibilityRow = { id?: string; object_type: string; object_id: string; responsibility_type_id: string; notes: string | null; object?: Named | null; type?: ResponsibilityTypeOption | null };

const OBJECT_TYPES = ['function', 'service', 'process', 'activity'] as const;

/** The responsibilities of a unit or proposed unit, replaced as a set. */
export default function ResponsibilitiesForm({ subjectType, subjectId, title, rows, objects, types, onClose }: {
    subjectType: 'unit' | 'proposal'; subjectId: string; title: string; rows: ResponsibilityRow[]; objects: ObjectCatalog; types: ResponsibilityTypeOption[]; onClose: () => void;
}) {
    const { locale, tt } = useDesignText();
    const form = useForm<{ items: ResponsibilityRow[] }>({ items: rows.map((r) => ({ object_type: r.object_type, object_id: r.object_id, responsibility_type_id: r.responsibility_type_id, notes: r.notes ?? '' })) });
    const errors = form.errors as Record<string, string | undefined>;

    const setItem = (index: number, patch: Partial<ResponsibilityRow>) => form.setData('items', form.data.items.map((item, i) => (i === index ? { ...item, ...patch } : item)));
    const add = () => form.setData('items', [...form.data.items, { object_type: 'function', object_id: '', responsibility_type_id: types[0]?.id ?? '', notes: '' }]);
    const remove = (index: number) => form.setData('items', form.data.items.filter((_, i) => i !== index));

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ items: data.items.map((item) => ({ ...item, notes: item.notes || null })) }));
        form.put(route('organization-design.responsibilities.sync', { subjectType, subject: subjectId }), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">{tt('responsibilities.edit')} · {title}</h2>
                <ul className="mt-5 space-y-3">
                    {form.data.items.map((item, index) => (
                        <li key={index} className="grid gap-2 rounded-[var(--radius-control)] border border-[color:var(--app-border)] p-3 sm:grid-cols-[8rem_1fr_10rem_auto]">
                            <FormField id={`resp-ot-${index}`} label={tt('fields.objectType')} error={errors[`items.${index}.object_type`]}>
                                {({ id }) => <Select id={id} value={item.object_type} onChange={(e) => setItem(index, { object_type: e.target.value, object_id: '' })}>{OBJECT_TYPES.map((k) => <option key={k} value={k}>{tt(`responsibilities.objects.${k}`)}</option>)}</Select>}
                            </FormField>
                            <FormField id={`resp-obj-${index}`} label={tt('fields.object')} required error={errors[`items.${index}.object_id`]}>
                                {({ id }) => (
                                    <Select id={id} value={item.object_id} onChange={(e) => setItem(index, { object_id: e.target.value })}>
                                        <option value="">—</option>
                                        {(objects[item.object_type as keyof ObjectCatalog] ?? []).map((o) => <option key={o.id} value={o.id}>{'— '.repeat(o.depth ?? 0)}{o.code} · {nameOf(o, locale)}</option>)}
                                    </Select>
                                )}
                            </FormField>
                            <FormField id={`resp-type-${index}`} label={tt('fields.responsibilityType')} required error={errors[`items.${index}.responsibility_type_id`]}>
                                {({ id }) => <Select id={id} value={item.responsibility_type_id} onChange={(e) => setItem(index, { responsibility_type_id: e.target.value })}>{types.map((t) => <option key={t.id} value={t.id}>{nameOf(t, locale)}</option>)}</Select>}
                            </FormField>
                            <div className="flex items-end pb-2"><button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => remove(index)}>{tt('functions.remove')}</button></div>
                            <FormField id={`resp-notes-${index}`} label={tt('fields.notes')} className="sm:col-span-4" error={errors[`items.${index}.notes`]}>{({ id }) => <Input id={id} value={item.notes ?? ''} maxLength={1000} onChange={(e) => setItem(index, { notes: e.target.value })} />}</FormField>
                        </li>
                    ))}
                </ul>
                <div className="mt-3"><Button type="button" size="sm" variant="outline" onClick={add}>{tt('responsibilities.addRow')}</Button></div>
                <FieldError>{errors.items ?? errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}

/** Compact list of a subject's responsibilities. */
export function ResponsibilitiesSummary({ rows }: { rows: ResponsibilityRow[] }) {
    const { locale, tt } = useDesignText();
    if (rows.length === 0) return <p className="text-xs text-[color:var(--app-muted-foreground)]">{tt('responsibilities.none')}</p>;
    return (
        <ul className="flex flex-wrap gap-1">
            {rows.map((r, i) => (
                <li key={r.id ?? i} className={badgeClassName(r.type?.covers_object ? 'success' : 'neutral')}>
                    {r.type ? nameOf(r.type, locale) : '—'}: {tt(`responsibilities.objects.${r.object_type}`)} {r.object ? `${r.object.code} · ${nameOf(r.object, locale)}` : r.object_id}
                </li>
            ))}
        </ul>
    );
}
