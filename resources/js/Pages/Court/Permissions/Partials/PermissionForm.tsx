import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Card, FieldError, FormField, Input, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { LocaleTexts, PermissionFormPermission } from '@/court/types/access';

interface PermissionFormData {
    name: string;
    label_translations: LocaleTexts;
    description_translations: LocaleTexts;
}

type Props = {
    locales: string[];
    primaryLocale: string;
} & ({ mode: 'create'; permission?: null } | { mode: 'edit'; permission: PermissionFormPermission });

const blank = (locales: string[], values?: LocaleTexts): LocaleTexts => Object.fromEntries(locales.map((locale) => [locale, values?.[locale] ?? '']));

/**
 * A permission's system name and, per locale, its label and description;
 * posts to permissions.store or permissions.update.
 */
export default function PermissionForm(props: Props) {
    const { t } = useLocale();
    const { mode, locales, primaryLocale } = props;
    const permission = mode === 'edit' ? props.permission : null;

    const form = useForm<PermissionFormData>({
        name: permission?.name ?? '',
        label_translations: blank(locales, permission?.label_translations),
        description_translations: blank(locales, permission?.description_translations),
    });

    const setText = (field: 'label_translations' | 'description_translations', locale: string, value: string) => {
        form.setData(field, { ...form.data[field], [locale]: value });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (mode === 'create') form.post(route('court.permissions.store'));
        else form.patch(route('court.permissions.update', permission!.id));
    };

    const fields: Array<{ field: 'label_translations' | 'description_translations'; label: string }> = [
        { field: 'label_translations', label: t('cm.permissions.fields.label') },
        { field: 'description_translations', label: t('cm.permissions.fields.description') },
    ];
    const errorFor = (field: string, locale: string): string | undefined => (form.errors as Record<string, string | undefined>)[`${field}.${locale}`];

    return (
        <Card>
            <form onSubmit={submit} className="space-y-5">
                <FormField id="permission-name" label={t('cm.permissions.fields.name')} required error={form.errors.name} description={t('cm.permissions.create.name_hint')}>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} value={form.data.name} required aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(event) => form.setData('name', event.target.value)} />
                    )}
                </FormField>

                {fields.map(({ field, label }) => (
                    <fieldset key={field} className="space-y-3">
                        <legend className="text-sm font-medium text-(--app-foreground)">{label}</legend>
                        {locales.map((locale) => {
                            const id = `permission-${field}-${locale}`;
                            const error = errorFor(field, locale);
                            return (
                                <div key={locale} className="space-y-1">
                                    <label htmlFor={id} className="flex items-center gap-2 text-xs uppercase tracking-wide text-(--app-muted-foreground)">
                                        <span>{locale.toUpperCase()}</span>
                                        {locale === primaryLocale && <span className="normal-case tracking-normal opacity-80">{t('cm.permissions.fields.primary_locale_notice')}</span>}
                                    </label>
                                    <Input id={id} value={form.data[field][locale] ?? ''} placeholder={`${label} (${locale.toUpperCase()})`} aria-invalid={error ? true : undefined}
                                        onChange={(event) => setText(field, locale, event.target.value)} />
                                    <FieldError>{error}</FieldError>
                                </div>
                            );
                        })}
                    </fieldset>
                ))}

                <div className="flex gap-2 pt-2">
                    <Button type="submit" variant="primary" loading={form.processing}>{mode === 'create' ? t('cm.permissions.create.create_button') : t('cm.permissions.edit.save_button')}</Button>
                    <Link href={route('court.permissions.index')} className={buttonClassName({ variant: 'outline' })}>{mode === 'create' ? t('cm.permissions.create.cancel_button') : t('cm.permissions.edit.back_button')}</Link>
                </div>
            </form>
        </Card>
    );
}
