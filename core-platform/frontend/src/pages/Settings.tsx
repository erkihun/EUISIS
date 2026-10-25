import { useEffect, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { api, fieldErrors, firstError } from '../api';
import PageHeader from '../components/PageHeader';
import { useLocale } from '../i18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout';
import { useSettings } from '../settings';
import { Alert, Button, Card, CardDescription, CardFooter, CardHeader, CardTitle, EmptyState, FormField, Input, Select, Tabs, Textarea } from '../ui';

type SettingValue = string | number | boolean | null;

type Field = {
    key: string;
    type: 'string' | 'text' | 'select' | 'boolean' | 'integer' | 'color';
    label_en: string;
    label_am: string;
    description_en: string | null;
    description_am: string | null;
    options: string[] | null;
    required: boolean;
    is_public: boolean;
    default: SettingValue;
    value: SettingValue;
};

type Group = { key: string; fields: Field[] };

/**
 * Core › Settings: every setting the Core owns, one tab per group, saved per
 * group. Changes apply at once (the shell reads them again) and are audited.
 */
export default function SettingsPage() {
    const { t } = useLocale();
    const [groups, setGroups] = useState<Group[] | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        api<{ groups: Group[] }>('/v1/settings').then((response) => setGroups(response.groups)).catch(() => setError(true));
    }, []);

    const saved = (groupKey: string, values: Record<string, SettingValue>) => {
        setGroups((current) => current?.map((group) => group.key !== groupKey ? group : {
            ...group,
            fields: group.fields.map((field) => ({ ...field, value: values[field.key] ?? null })),
        }) ?? null);
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={t('core.settings.title')} description={t('platform.settings.description')} />}>
            {error && <Alert tone="danger">{t('platform.error')}</Alert>}
            {!groups && !error && <Card><EmptyState title={t('common.loading')} /></Card>}
            {groups && (
                <Tabs items={groups.map((group) => ({
                    id: group.key,
                    label: t(`core.settings.sections.${group.key}.title`),
                    content: <GroupForm key={group.key} group={group} onSaved={(values) => saved(group.key, values)} />,
                }))} />
            )}
        </AuthenticatedLayout>
    );
}

function GroupForm({ group, onSaved }: { group: Group; onSaved: (values: Record<string, SettingValue>) => void }) {
    const { t, locale } = useLocale();
    const { reload } = useSettings();
    const initial = () => Object.fromEntries(group.fields.map((field) => [field.key, field.value])) as Record<string, SettingValue>;
    const [values, setValues] = useState<Record<string, SettingValue>>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const changed = group.fields.filter((field) => values[field.key] !== field.value);

    useEffect(() => setValues(initial()), [group]); // eslint-disable-line react-hooks/exhaustive-deps

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (changed.length === 0) {
            toast.info(t('platform.settings.nothingChanged'));
            return;
        }
        setBusy(true);
        setErrors({});
        try {
            const body = { values: Object.fromEntries(changed.map((field) => [field.key, values[field.key]])) };
            const response = await api<{ values: Record<string, SettingValue> }>(`/v1/settings/${group.key}`, { method: 'PUT', body });
            onSaved(response.values);
            // The shell, sign-in page and lists read the public settings: take the new ones at once.
            await reload();
            toast.success(t('platform.settings.saved'));
        } catch (caught) {
            const fields = fieldErrors(caught);
            setErrors(Object.keys(fields).length > 0 ? fields : { form: firstError(caught) ?? t('platform.error') });
        } finally {
            setBusy(false);
        }
    }

    const label = (field: Field) => (locale === 'am' ? field.label_am : field.label_en);
    const description = (field: Field) => (locale === 'am' ? field.description_am : field.description_en) ?? undefined;
    const set = (key: string, value: SettingValue) => setValues((current) => ({ ...current, [key]: value }));

    return (
        <form onSubmit={submit}>
            <Card>
                <CardHeader>
                    <div>
                        <CardTitle>{t(`core.settings.sections.${group.key}.title`)}</CardTitle>
                        <CardDescription>{t(`core.settings.sections.${group.key}.description`)}</CardDescription>
                    </div>
                </CardHeader>
                {errors.form && <Alert tone="danger" className="mb-4">{errors.form}</Alert>}
                <div className="grid gap-5 md:grid-cols-2">
                    {group.fields.map((field) => (
                        <div key={field.key} className={field.type === 'text' ? 'md:col-span-2' : undefined}>
                            {field.type === 'boolean' ? (
                                <label className="flex items-start gap-3 rounded-card border border-(--app-border) bg-(--app-surface) p-3">
                                    <input
                                        type="checkbox"
                                        checked={values[field.key] === true}
                                        onChange={(e) => set(field.key, e.target.checked)}
                                        className="mt-0.5 h-4 w-4 rounded border-(--app-border-strong) text-primary focus:ring-primary"
                                    />
                                    <span>
                                        <span className="block text-label text-(--app-foreground)">{label(field)}</span>
                                        {description(field) && <span className="mt-0.5 block text-helper text-(--app-muted-foreground)">{description(field)}</span>}
                                        {errors[field.key] && <span className="mt-0.5 block text-helper text-red-600">{errors[field.key]}</span>}
                                    </span>
                                </label>
                            ) : (
                                <FormField label={label(field)} required={field.required} description={description(field)} error={errors[field.key]}>
                                    {({ id, describedBy, invalid }) => <FieldControl field={field} id={id} describedBy={describedBy} invalid={invalid} value={values[field.key]} onChange={(value) => set(field.key, value)} />}
                                </FormField>
                            )}
                        </div>
                    ))}
                </div>
                <CardFooter>
                    <Button variant="ghost" onClick={() => { setValues(initial()); setErrors({}); }} disabled={busy || changed.length === 0}>{t('common.reset')}</Button>
                    <Button type="submit" variant="primary" loading={busy} disabled={changed.length === 0}>{t('platform.settings.save')}</Button>
                </CardFooter>
            </Card>
        </form>
    );
}

function FieldControl({ field, id, describedBy, invalid, value, onChange }: {
    field: Field;
    id: string;
    describedBy?: string;
    invalid: boolean;
    value: SettingValue;
    onChange: (value: SettingValue) => void;
}) {
    const { t } = useLocale();
    const common = { id, 'aria-describedby': describedBy, 'aria-invalid': invalid || undefined };
    const optionLabel = (option: string) => {
        if (field.key === 'default_theme') return t(`common.${option}`);
        if (field.key === 'default_locale') return option === 'am' ? 'አማርኛ' : 'English';
        return option;
    };

    switch (field.type) {
        case 'select':
            return (
                <Select {...common} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)}>
                    {(field.options ?? []).map((option) => <option key={option} value={option}>{optionLabel(option)}</option>)}
                </Select>
            );
        case 'integer':
            return <Input {...common} type="number" value={value === null ? '' : String(value)} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} />;
        case 'color':
            return (
                <div className="flex items-center gap-2">
                    <input type="color" aria-label={field.label_en} value={String(value ?? '#000000')} onChange={(e) => onChange(e.target.value.toUpperCase())} className="h-(--control-h-md) w-12 shrink-0 cursor-pointer rounded-control border border-(--app-border-strong) bg-(--app-surface) p-1" />
                    <Input {...common} value={String(value ?? '')} maxLength={7} onChange={(e) => onChange(e.target.value)} className="font-mono uppercase" />
                </div>
            );
        case 'text':
            return <Textarea {...common} value={String(value ?? '')} onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)} />;
        default:
            return <Input {...common} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} />;
    }
}
