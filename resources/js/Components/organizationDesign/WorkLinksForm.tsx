import Modal from '@/Components/Modal';
import { Button, FieldError, FormField, Select, actionLinkClassName } from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import DesignLookup, { type LookupHit } from './DesignLookup';
import { nameOf, useDesignText } from './ui';

export type WorkObjectType = 'service' | 'process' | 'activity' | 'task';
export type WorkObject = { id: string; code: string; name_en: string; name_am: string | null };
export type WorkLinkRow = { object_type: WorkObjectType; object_id: string; is_primary: boolean; object?: WorkObject | null };

const TYPES: WorkObjectType[] = ['service', 'process', 'activity', 'task'];
const KIND: Record<WorkObjectType, 'services' | 'processes' | 'activities' | 'tasks'> = { service: 'services', process: 'processes', activity: 'activities', task: 'tasks' };
const checkbox = 'rounded-sm border-(--app-border-strong) text-primary focus:ring-primary';

/** The work a position is responsible for, replaced as a set (PUT); objects are searched on the server inside the organization. */
export default function WorkLinksForm({ positionId, organizationId, current, onClose }: { positionId: string; organizationId: string; current: WorkLinkRow[]; onClose: () => void }) {
    const { locale, tt } = useDesignText();
    const form = useForm<{ items: WorkLinkRow[] }>({ items: current.map((l) => ({ object_type: l.object_type, object_id: l.object_id, is_primary: l.is_primary })) });
    const [labels, setLabels] = useState<Record<string, string>>(Object.fromEntries(current.filter((l) => l.object).map((l) => [l.object_id, `${l.object!.code} · ${nameOf(l.object, locale)}`])));
    const errors = form.errors as Record<string, string | undefined>;
    const setRow = (index: number, patch: Partial<WorkLinkRow>) => form.setData('items', form.data.items.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    const removeRow = (index: number) => form.setData('items', form.data.items.filter((_, i) => i !== index));

    function submit(event: React.FormEvent) {
        event.preventDefault();
        form.put(route('organization-design.position-work-links.sync', positionId), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-(--app-foreground)">{tt('positionDesign.editWorkLinks')}</h2>
                        <p className="mt-1 text-sm text-(--app-muted-foreground)">{tt('positionDesign.workLinksHelp')}</p>
                    </div>
                    <Button type="button" size="sm" variant="outline" onClick={() => form.setData('items', [...form.data.items, { object_type: 'task', object_id: '', is_primary: false }])}>{tt('positionDesign.addLink')}</Button>
                </div>
                <div className="mt-5 space-y-2">
                    {form.data.items.length === 0 && <p className="py-4 text-center text-sm text-(--app-muted-foreground)">{tt('positionDesign.noWorkLinks')}</p>}
                    {form.data.items.map((row, i) => (
                        <div key={i} className="grid gap-2 sm:grid-cols-[10rem_1fr_auto]">
                            <FormField id={`link-type-${i}`} label={tt('fields.objectType')} required error={errors[`items.${i}.object_type`]}>
                                {({ id }) => <Select id={id} value={row.object_type} onChange={(e) => setRow(i, { object_type: e.target.value as WorkObjectType, object_id: '' })}>{TYPES.map((type) => <option key={type} value={type}>{tt(`positionDesign.objectTypes.${type}`)}</option>)}</Select>}
                            </FormField>
                            <FormField id={`link-object-${i}`} label={tt('fields.object')} required error={errors[`items.${i}.object_id`]}>
                                {({ id }) => (
                                    <DesignLookup id={id} kind={KIND[row.object_type]} organizationId={organizationId} value={row.object_id} display={labels[row.object_id] ?? ''}
                                        onChange={(objectId, hit: LookupHit | null) => { setRow(i, { object_id: objectId }); if (hit) setLabels({ ...labels, [objectId]: `${hit.code} · ${(locale === 'am' && hit.name_am) || hit.name_en}` }); }} />
                                )}
                            </FormField>
                            <div className="flex items-end gap-3 pb-2">
                                <label className="flex items-center gap-1 text-xs"><input type="checkbox" className={checkbox} checked={row.is_primary} onChange={(e) => setRow(i, { is_primary: e.target.checked })} />{tt('positionDesign.primary')}</label>
                                <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => removeRow(i)}>{tt('functions.remove')}</button>
                            </div>
                        </div>
                    ))}
                </div>
                <FieldError>{errors.items ?? errors.status}</FieldError>
                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </form>
        </Modal>
    );
}
