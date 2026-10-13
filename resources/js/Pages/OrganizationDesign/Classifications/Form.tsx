import { useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, FieldError, FormField, Input } from '@euisis/ui';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export type ClassificationRecord = {
    id: string;
    scheme: string;
    revision: string;
    code: string;
    parent_id: string | null;
    parent_code: string | null;
    level: number | string | null;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    description_am: string | null;
    is_active: boolean;
    sort_order: number | null;
};

type Props = {
    scheme: string;
    revision: string;
    record: ClassificationRecord | null;
};

/**
 * One LOCAL or CPC classification entry, typed in by a steward (ISIC and ISCO
 * are imported, never typed). The same form serves the create and the edit
 * page; saving returns to the list of the entry's scheme and revision.
 */
export default function ClassificationForm({ scheme, revision, record }: Props) {
    const { tt } = useDesignText();
    const form = useForm({
        scheme,
        revision,
        code: record?.code ?? '',
        parent_id: record?.parent_id ?? '',
        level: String(record?.level ?? 1),
        name_en: record?.name_en ?? '',
        name_am: record?.name_am ?? '',
        description_en: record?.description_en ?? '',
        description_am: record?.description_am ?? '',
        is_active: record?.is_active ?? true,
        sort_order: String(record?.sort_order ?? 0),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const listHref = route('organization-design.classifications.index', { scheme, revision });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => {
            const clean: Record<string, unknown> = Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value]));
            // The scheme and revision of an existing entry never change.
            if (record) { delete clean.scheme; delete clean.revision; }
            return clean;
        });

        if (record) form.put(route('organization-design.classifications.update', record.id));
        else form.post(route('organization-design.classifications.store'));
    }

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <div className="grid gap-4 p-6 sm:grid-cols-2">
                    {!record && (
                        <FormField id="cl-revision" label={tt('classifications.revision')} required error={errors.revision}>{({ id }) => <Input id={id} value={form.data.revision} maxLength={20} onChange={(e) => form.setData('revision', e.target.value)} />}</FormField>
                    )}
                    <FormField id="cl-code" label={tt('fields.code')} required error={errors.code}>{({ id }) => <Input id={id} value={form.data.code} maxLength={40} onChange={(e) => form.setData('code', e.target.value)} />}</FormField>
                    <FormField id="cl-level" label={tt('fields.level')} error={errors.level}>{({ id }) => <Input id={id} type="number" min={1} max={10} value={form.data.level} onChange={(e) => form.setData('level', e.target.value)} />}</FormField>
                    <FormField id="cl-sort" label={tt('fields.sortOrder')} error={errors.sort_order}>{({ id }) => <Input id={id} type="number" min={0} value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} />}</FormField>
                    <FormField id="cl-name-en" label={tt('fields.nameEn')} required error={errors.name_en}>{({ id }) => <Input id={id} value={form.data.name_en} onChange={(e) => form.setData('name_en', e.target.value)} />}</FormField>
                    <FormField id="cl-name-am" label={tt('fields.nameAm')} error={errors.name_am}>{({ id }) => <Input id={id} value={form.data.name_am} onChange={(e) => form.setData('name_am', e.target.value)} />}</FormField>
                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" className="rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                        <span>{tt('fields.isActive')}</span>
                    </label>
                    <FieldError>{errors.parent_id}</FieldError>
                </div>
                <div className="flex justify-end gap-2 border-t border-[color:var(--app-border)] px-6 py-4">
                    <Button as={Link} href={listHref} variant="outline">{tt('actions.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{tt('actions.save')}</Button>
                </div>
            </Card>
        </form>
    );
}
