import { Button, Card, CardDescription, CardHeader, CardTitle, FormField, Input, Textarea } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';
import FormBuilder, { type BuilderLimits } from './FormBuilder';
import { nameOf } from './helpers';
import type { FormSchema, Named } from './types';

export interface EditableForm {
    id: string;
    code: string;
    name_am: string;
    name_en: string | null;
    description: string | null;
    schema: FormSchema;
    organization_type_ids: string[];
}

type Data = {
    code: string;
    name_am: string;
    name_en: string;
    description: string;
    organization_type_ids: string[];
    schema: FormSchema;
};

/** The create and edit form of a monitoring form: details, organization types, then the builder. */
export default function FormEditor({ form, organizationTypes, limits }: { form?: EditableForm; organizationTypes: Named[]; limits: BuilderLimits }): JSX.Element {
    const { t, locale } = useLocale();
    const editing = form !== undefined;
    const { data, setData, post, put, processing, errors } = useForm<Data>({
        code: form?.code ?? '',
        name_am: form?.name_am ?? '',
        name_en: form?.name_en ?? '',
        description: form?.description ?? '',
        organization_type_ids: form?.organization_type_ids ?? [],
        schema: form?.schema ?? { sections: [] },
    });
    const allErrors = errors as Record<string, string | undefined>;

    function submit(event: FormEvent) {
        event.preventDefault();
        if (editing) {
            put(route('service-monitoring.forms.update', form.id), { preserveScroll: true });
        } else {
            post(route('service-monitoring.forms.store'), { preserveScroll: true });
        }
    }

    function toggleType(id: string, on: boolean) {
        setData('organization_type_ids', on ? [...data.organization_type_ids, id] : data.organization_type_ids.filter((existing) => existing !== id));
    }

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card className="space-y-4 p-4">
                <CardHeader className="p-0">
                    <CardTitle>{t('serviceMonitoring.forms.details')}</CardTitle>
                </CardHeader>
                <div className="grid gap-4 md:grid-cols-2">
                    <FormField label={t('serviceMonitoring.forms.code')} required={!editing} description={t('serviceMonitoring.forms.codeHelp')} error={errors.code}>
                        {({ id }) => <Input id={id} className="w-full" maxLength={64} value={data.code} disabled={editing} onChange={(e) => setData('code', e.target.value.toUpperCase())} />}
                    </FormField>
                    <FormField label={t('serviceMonitoring.forms.nameAm')} required error={errors.name_am}>
                        {({ id }) => <Input id={id} className="w-full" maxLength={255} value={data.name_am} onChange={(e) => setData('name_am', e.target.value)} />}
                    </FormField>
                    <FormField label={t('serviceMonitoring.forms.nameEn')} error={errors.name_en}>
                        {({ id }) => <Input id={id} className="w-full" maxLength={255} value={data.name_en} onChange={(e) => setData('name_en', e.target.value)} />}
                    </FormField>
                    <FormField label={t('serviceMonitoring.forms.descriptionLabel')} error={errors.description} className="md:col-span-2">
                        {({ id }) => <Textarea id={id} className="w-full" maxLength={5000} value={data.description} onChange={(e) => setData('description', e.target.value)} />}
                    </FormField>
                </div>
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium text-(--app-foreground)">{t('serviceMonitoring.forms.organizationTypes')}</legend>
                    <CardDescription>{t('serviceMonitoring.forms.organizationTypesHelp')}</CardDescription>
                    <div className="grid gap-x-4 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                        {organizationTypes.map((type) => (
                            <label key={type.id} className="inline-flex items-center gap-2 text-sm text-(--app-foreground)">
                                <input type="checkbox" className="h-4 w-4 accent-(--color-primary)" checked={data.organization_type_ids.includes(type.id)} onChange={(e) => toggleType(type.id, e.target.checked)} />
                                {nameOf(type, locale)}
                            </label>
                        ))}
                    </div>
                    {(errors.organization_type_ids || allErrors['organization_type_ids.0']) && (
                        <p role="alert" className="text-xs text-red-700 dark:text-red-400">{errors.organization_type_ids ?? allErrors['organization_type_ids.0']}</p>
                    )}
                </fieldset>
            </Card>

            <div className="space-y-2">
                <div>
                    <h2 className="text-base font-semibold text-(--app-foreground)">{t('serviceMonitoring.builder.title')}</h2>
                    <p className="text-sm text-(--app-muted-foreground)">{t('serviceMonitoring.builder.description')}</p>
                </div>
                <FormBuilder schema={data.schema} onChange={(schema) => setData('schema', schema)} errors={allErrors} limits={limits} />
            </div>

            <div className="flex flex-wrap justify-end gap-2 border-t border-(--app-border) pt-4">
                <Button as={Link} href={editing ? route('service-monitoring.forms.show', form.id) : route('service-monitoring.forms.index')} variant="outline">{t('serviceMonitoring.actions.cancel')}</Button>
                <Button type="submit" variant="primary" loading={processing}>{t('serviceMonitoring.actions.save')}</Button>
            </div>
        </form>
    );
}
