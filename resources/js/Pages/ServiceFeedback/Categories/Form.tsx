import { Button, Card, FormField, Input, Select, Textarea } from '@euisis/ui';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

export type SubmissionType = 'feedback' | 'suggestion' | 'complaint';
export type TargetType = 'employee' | 'organization';

export type CategoryRecord = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    description_am: string | null;
    submission_types: SubmissionType[];
    target_types: TargetType[];
    organization_id: string | null;
    is_active: boolean;
    sort_order: number;
};

export type OrganizationOption = { id: string; code: string; name_en: string; name_am: string | null };

export const SUBMISSION_TYPES: Array<{ value: SubmissionType; key: string }> = [
    { value: 'feedback', key: 'typeFeedback' },
    { value: 'suggestion', key: 'typeSuggestion' },
    { value: 'complaint', key: 'typeComplaint' },
];
export const TARGET_TYPES: Array<{ value: TargetType; key: string }> = [
    { value: 'employee', key: 'targetEmployee' },
    { value: 'organization', key: 'targetOrganization' },
];

type Props = {
    record: CategoryRecord | null;
    organizations: OrganizationOption[];
    canManageCityWide: boolean;
};

/** The one category form, used by the create and the edit page. Saving returns to the list. */
export default function CategoryForm({ record, organizations, canManageCityWide }: Props) {
    const { t, locale } = useLocale();
    const form = useForm({
        code: record?.code ?? '',
        name_en: record?.name_en ?? '',
        name_am: record?.name_am ?? '',
        description_en: record?.description_en ?? '',
        description_am: record?.description_am ?? '',
        submission_types: record?.submission_types ?? (['feedback', 'suggestion', 'complaint'] as SubmissionType[]),
        target_types: record?.target_types ?? (['employee', 'organization'] as TargetType[]),
        organization_id: record?.organization_id ?? (canManageCityWide ? '' : organizations[0]?.id ?? ''),
        is_active: record?.is_active ?? true,
        sort_order: record?.sort_order ?? 0,
    });

    function toggle<T extends string>(list: T[], value: T): T[] {
        return list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, organization_id: data.organization_id === '' ? null : data.organization_id }));

        if (record === null) {
            form.post(route('service-feedback.categories.store'));
        } else {
            form.put(route('service-feedback.categories.update', record.id));
        }
    }

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <div className="grid gap-4 p-6 sm:grid-cols-2">
                    <FormField id="category-code" label={t('serviceFeedback.categoryCode')} error={errors.code} required>
                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.code} maxLength={40} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}
                    </FormField>
                    <FormField id="category-scope" label={t('serviceFeedback.categoryScope')} error={errors.organization_id}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                                {canManageCityWide && <option value="">{t('serviceFeedback.cityWide')}</option>}
                                {organizations.map((organization) => (
                                    <option key={organization.id} value={organization.id}>{locale === 'am' && organization.name_am ? organization.name_am : organization.name_en}</option>
                                ))}
                            </Select>
                        )}
                    </FormField>
                    <FormField id="category-name-en" label={t('serviceFeedback.serviceNameEn').replace(/.*\(/, t('serviceFeedback.category') + ' (')} error={errors.name_en} required>
                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.name_en} maxLength={255} onChange={(e) => form.setData('name_en', e.target.value)} />}
                    </FormField>
                    <FormField id="category-name-am" label={t('serviceFeedback.serviceNameAm').replace(/.*\(/, t('serviceFeedback.category') + ' (')} error={errors.name_am}>
                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.name_am} maxLength={255} onChange={(e) => form.setData('name_am', e.target.value)} />}
                    </FormField>
                    <FormField id="category-description-en" label={`${t('serviceFeedback.serviceDescription')} (EN)`} error={errors.description_en}>
                        {({ id, describedBy, invalid }) => <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} rows={2} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}
                    </FormField>
                    <FormField id="category-description-am" label={`${t('serviceFeedback.serviceDescription')} (አማ)`} error={errors.description_am}>
                        {({ id, describedBy, invalid }) => <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} rows={2} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}
                    </FormField>

                    <fieldset className="space-y-1.5">
                        <legend className="text-sm font-medium text-[color:var(--app-foreground)]">{t('serviceFeedback.appliesToTypes')}</legend>
                        {SUBMISSION_TYPES.map((item) => (
                            <label key={item.value} className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.data.submission_types.includes(item.value)} onChange={() => form.setData('submission_types', toggle(form.data.submission_types, item.value))} className="h-4 w-4 rounded border-[color:var(--app-border-strong)]" />
                                {t(`serviceFeedback.${item.key}`)}
                            </label>
                        ))}
                        {errors.submission_types && <p role="alert" className="text-sm text-red-600">{errors.submission_types}</p>}
                    </fieldset>
                    <fieldset className="space-y-1.5">
                        <legend className="text-sm font-medium text-[color:var(--app-foreground)]">{t('serviceFeedback.appliesToTargets')}</legend>
                        {TARGET_TYPES.map((item) => (
                            <label key={item.value} className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.data.target_types.includes(item.value)} onChange={() => form.setData('target_types', toggle(form.data.target_types, item.value))} className="h-4 w-4 rounded border-[color:var(--app-border-strong)]" />
                                {t(`serviceFeedback.${item.key}`)}
                            </label>
                        ))}
                        {errors.target_types && <p role="alert" className="text-sm text-red-600">{errors.target_types}</p>}
                    </fieldset>

                    <FormField id="category-sort" label={t('serviceFeedback.sortOrder')} error={errors.sort_order}>
                        {({ id, describedBy, invalid }) => <Input id={id} type="number" min={0} max={9999} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.sort_order} onChange={(e) => form.setData('sort_order', Number(e.target.value))} />}
                    </FormField>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm">
                        <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="h-4 w-4 rounded border-[color:var(--app-border-strong)]" />
                        {t('serviceFeedback.qrActive')}
                    </label>
                </div>
                <div className="flex justify-end gap-2 border-t border-[color:var(--app-border)] px-6 py-4">
                    <Button as={Link} href={route('service-feedback.categories.index')} variant="outline">{t('common.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{t('common.save')}</Button>
                </div>
            </Card>
        </form>
    );
}
