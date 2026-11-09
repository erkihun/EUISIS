import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { AppDialog, Button, Card, FormField, Input, Select, Textarea } from '@euisis/ui';

type TemplateConfig = {
    orientation: string;
    colors: { primary: string; accent: string; text: string; background: string };
    header_en: string;
    header_am: string;
    footer_en: string;
    footer_am: string;
    fields: string[];
};

type Version = {
    id: string;
    version: number;
    status: 'draft' | 'active' | 'retired';
    config: TemplateConfig;
    created_by: string | null;
    activated_by: string | null;
    activated_at: string | null;
    retired_at: string | null;
    preview_url: string;
    save_url: string | null;
    activate_url: string | null;
    retire_url: string | null;
};

type Template = { id: string; code: string; name_en: string; name_am: string | null; new_version_url: string | null; versions: Version[] };

const TONE: Record<Version['status'], string> = { draft: 'pending', active: 'active', retired: 'inactive' };

/**
 * Core › Card templates (ADR 0010): versioned card layouts. A draft is edited
 * and previewed with the renderer that prints; someone other than its author
 * activates it, which retires the version active before.
 */
export default function CardTemplates({ templates, fields, required_fields, orientations, store_url }: {
    templates: Template[];
    fields: string[];
    required_fields: string[];
    orientations: string[];
    store_url: string | null;
}) {
    const { t, locale } = useLocale();
    const label = (key: string) => t(`core.cardTemplates.${key}`);
    const all = useMemo(() => templates.flatMap((template) => template.versions.map((version) => ({ template, version }))), [templates]);
    const [selectedId, setSelectedId] = useState<string | null>(all.find((entry) => entry.version.status === 'draft')?.version.id ?? all[0]?.version.id ?? null);
    const selected = all.find((entry) => entry.version.id === selectedId) ?? null;
    const [stamp, setStamp] = useState(0);
    const [creating, setCreating] = useState(false);
    const [retiring, setRetiring] = useState<Version | null>(null);
    const createForm = useForm({ code: '', name_en: '', name_am: '' });
    const retireForm = useForm({ reason: '' });
    const draft = useForm<{ config: TemplateConfig }>({ config: selected?.version.config ?? ({} as TemplateConfig) });

    const choose = (version: Version) => {
        setSelectedId(version.id);
        draft.setData('config', version.config);
        draft.clearErrors();
    };
    const setConfig = (patch: Partial<TemplateConfig>) => draft.setData('config', { ...draft.data.config, ...patch });
    const toggleField = (field: string) => setConfig({ fields: draft.data.config.fields.includes(field) ? draft.data.config.fields.filter((f) => f !== field) : [...draft.data.config.fields, field] });

    const save = (event: FormEvent) => {
        event.preventDefault();
        if (selected?.version.save_url) draft.put(selected.version.save_url, { preserveScroll: true, onSuccess: () => setStamp((value) => value + 1) });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')}
            actions={store_url ? <Button variant="primary" onClick={() => { createForm.reset(); setCreating(true); }}>{label('newTemplate')}</Button> : undefined} />}>
            <Head title={label('title')} />

            <div className="grid gap-5 lg:grid-cols-[18rem_1fr]">
                <Card className="divide-y divide-(--app-border)">
                    {templates.map((template) => (
                        <div key={template.id} className="p-4">
                            <div className="flex items-center justify-between gap-2">
                                <div>
                                    <p className="font-medium text-(--app-foreground)">{locale === 'am' && template.name_am ? template.name_am : template.name_en}</p>
                                    <p className="font-mono text-xs text-(--app-muted-foreground)">{template.code}</p>
                                </div>
                                {template.new_version_url && <Button size="sm" variant="outline" onClick={() => router.post(template.new_version_url as string, {}, { preserveScroll: true })}>{label('newVersion')}</Button>}
                            </div>
                            <ul className="mt-3 space-y-1">
                                {template.versions.map((version) => (
                                    <li key={version.id}>
                                        <button type="button" onClick={() => choose(version)} aria-current={selectedId === version.id ? 'true' : undefined}
                                            className={`flex w-full items-center justify-between rounded-md px-2 py-1.5 text-left text-sm ${selectedId === version.id ? 'bg-(--app-surface-muted) font-medium' : 'hover:bg-(--app-surface-muted)'}`}>
                                            <span>{label('version')} {version.version}</span>
                                            <StatusBadge status={TONE[version.status]} label={label(`statuses.${version.status}`)} />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </Card>

                {selected && (
                    <div className="space-y-5">
                        <Card className="p-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 className="font-semibold text-(--app-foreground)">{selected.template.code} · {label('version')} {selected.version.version}</h2>
                                    <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                        {label('createdBy')} {selected.version.created_by ?? '—'}
                                        {selected.version.activated_at && <> · {label('activatedBy')} {selected.version.activated_by ?? '—'} · <LocalizedDateDisplay value={selected.version.activated_at} withTime /></>}
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    {selected.version.activate_url && <Button variant="primary" onClick={() => router.post(selected.version.activate_url as string, {}, { preserveScroll: true })}>{label('activate')}</Button>}
                                    {selected.version.retire_url && <Button variant="outline" onClick={() => { retireForm.reset(); setRetiring(selected.version); }}>{label('retire')}</Button>}
                                </div>
                            </div>
                            {selected.version.status === 'draft' && !selected.version.activate_url && selected.version.save_url && <p className="mt-3 text-sm text-(--app-muted-foreground)">{label('secondPerson')}</p>}
                            <img key={`${selected.version.id}-${stamp}`} src={`${selected.version.preview_url}?v=${stamp}`} alt={label('preview')} className="mt-4 w-full max-w-xl rounded-lg border border-(--app-border)" />
                        </Card>

                        {selected.version.save_url && (
                            <Card className="p-5">
                                <form onSubmit={save} className="grid gap-4 md:grid-cols-2">
                                    <FormField label={label('orientation')} error={(draft.errors as Record<string, string | undefined>)['orientation']}>
                                        {({ id }) => (
                                            <Select id={id} value={draft.data.config.orientation} onChange={(event) => setConfig({ orientation: event.target.value })}>
                                                {orientations.map((value) => <option key={value} value={value}>{label(`orientations.${value}`)}</option>)}
                                            </Select>
                                        )}
                                    </FormField>
                                    <div className="grid grid-cols-4 gap-2">
                                        {(['primary', 'accent', 'text', 'background'] as const).map((color) => (
                                            <label key={color} className="text-xs text-(--app-muted-foreground)">
                                                {label(`colors.${color}`)}
                                                <input type="color" className="mt-1 block h-9 w-full rounded border border-(--app-border)" value={draft.data.config.colors[color]}
                                                    onChange={(event) => setConfig({ colors: { ...draft.data.config.colors, [color]: event.target.value } })} />
                                            </label>
                                        ))}
                                    </div>
                                    {(['header_am', 'header_en', 'footer_am', 'footer_en'] as const).map((key) => (
                                        <FormField key={key} label={label(`texts.${key}`)} description={key.startsWith('header') ? label('headerHelp') : undefined}>
                                            {({ id }) => <Input id={id} maxLength={120} value={draft.data.config[key]} onChange={(event) => setConfig({ [key]: event.target.value })} />}
                                        </FormField>
                                    ))}
                                    <fieldset className="md:col-span-2">
                                        <legend className="text-sm font-medium text-(--app-foreground)">{label('fields')}</legend>
                                        <div className="mt-2 flex flex-wrap gap-3">
                                            {fields.map((field) => (
                                                <label key={field} className="flex items-center gap-2 text-sm">
                                                    <input type="checkbox" checked={draft.data.config.fields.includes(field)} disabled={required_fields.includes(field)} onChange={() => toggleField(field)} />
                                                    {label(`fieldNames.${field}`)}
                                                </label>
                                            ))}
                                        </div>
                                        {(draft.errors as Record<string, string | undefined>).fields && <p className="mt-1 text-xs text-red-600">{(draft.errors as Record<string, string | undefined>).fields}</p>}
                                    </fieldset>
                                    <div className="md:col-span-2"><Button variant="primary" type="submit" loading={draft.processing}>{label('saveDraft')}</Button></div>
                                </form>
                            </Card>
                        )}
                    </div>
                )}
            </div>

            <AppDialog open={creating} onClose={() => setCreating(false)} title={label('newTemplate')}
                footer={<><Button variant="outline" onClick={() => setCreating(false)}>{label('cancel')}</Button><Button variant="primary" type="submit" form="card-template-form" loading={createForm.processing}>{label('create')}</Button></>}>
                <form id="card-template-form" className="space-y-4" onSubmit={(event) => { event.preventDefault(); if (store_url) createForm.post(store_url, { onSuccess: () => setCreating(false) }); }}>
                    <FormField label={label('code')} required error={createForm.errors.code}>{({ id }) => <Input id={id} maxLength={64} value={createForm.data.code} onChange={(event) => createForm.setData('code', event.target.value)} />}</FormField>
                    <FormField label={label('nameEn')} required error={createForm.errors.name_en}>{({ id }) => <Input id={id} value={createForm.data.name_en} onChange={(event) => createForm.setData('name_en', event.target.value)} />}</FormField>
                    <FormField label={label('nameAm')} error={createForm.errors.name_am}>{({ id }) => <Input id={id} value={createForm.data.name_am} onChange={(event) => createForm.setData('name_am', event.target.value)} />}</FormField>
                </form>
            </AppDialog>

            <AppDialog open={retiring !== null} onClose={() => setRetiring(null)} title={label('retire')} description={label('retireDescription')}
                footer={<><Button variant="outline" onClick={() => setRetiring(null)}>{label('cancel')}</Button><Button variant="primary" type="submit" form="card-template-retire" loading={retireForm.processing}>{label('retire')}</Button></>}>
                <form id="card-template-retire" onSubmit={(event) => { event.preventDefault(); if (retiring?.retire_url) retireForm.post(retiring.retire_url, { preserveScroll: true, onSuccess: () => setRetiring(null) }); }}>
                    <FormField label={label('reason')} required error={retireForm.errors.reason}>{({ id }) => <Textarea id={id} rows={3} value={retireForm.data.reason} onChange={(event) => retireForm.setData('reason', event.target.value)} />}</FormField>
                </form>
            </AppDialog>
        </AuthenticatedLayout>
    );
}
