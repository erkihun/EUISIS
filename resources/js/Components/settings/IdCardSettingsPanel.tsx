import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import Button from '@/Components/Button';
import SettingField from '@/Components/settings/SettingField';
import IdCardFront from '@/Components/IdCards/IdCardFront';
import IdCardBack from '@/Components/IdCards/IdCardBack';
import IdCardPortraitFront from '@/Components/IdCards/IdCardPortraitFront';
import IdCardPortraitBack from '@/Components/IdCards/IdCardPortraitBack';
import { IdCardTemplateContext, useIdCardTemplate, type TemplatePresentation } from '@/Components/IdCards/IdCardTemplateContext';
import { SystemSettingsPreviewContext } from '@/hooks/useSystemSettings';
import { useLocale } from '@/hooks/useLocale';
import type { SettingsField, SettingsGroupPayload } from '@/lib/settings';

type Template = TemplatePresentation & {
    id: string; name: string; code: string; description: string | null;
    status: 'active' | 'inactive'; is_default: boolean;
};
export type TemplateManagement = {
    templates: Template[];
    uploadLimitMb: number;
    can: { create: boolean; update: boolean; delete: boolean; set_default: boolean };
};
type Props = { payload: SettingsGroupPayload; readOnly: boolean; management: TemplateManagement | null };
type SettingValue = string | number | boolean;

const templateFormId = 'id-card-template-editor';
const inputClass = 'mt-1 min-h-11 w-full min-w-0 rounded-lg border-gray-300 bg-white text-sm dark:border-slate-700 dark:bg-slate-950';

function Card({ title, helper, children }: { title: string; helper?: string; children: ReactNode }) {
    return <section className="min-w-0 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5 dark:border-slate-800 dark:bg-slate-900">
        <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h3>
        {helper && <p className="mt-1 text-xs leading-relaxed text-gray-500 dark:text-slate-400">{helper}</p>}
        <div className="mt-4 min-w-0 space-y-4">{children}</div>
    </section>;
}

function templateValues(template?: Template | null) {
    return {
        name: template?.name ?? '', code: template?.code ?? '', description: template?.description ?? '',
        orientation: template?.orientation ?? 'landscape' as 'landscape' | 'portrait',
        width_mm: template?.width_mm ?? 85.6, height_mm: template?.height_mm ?? 54,
        status: template?.status ?? 'active' as 'active' | 'inactive', is_default: template?.is_default ?? false,
        front_background: null as File | null, back_background: null as File | null,
        remove_front_background: false, remove_back_background: false,
    };
}

function useBackground(file: File | null, existing: string | null, removed: boolean) {
    const [url, setUrl] = useState<string | null>(null);
    useEffect(() => {
        if (!file) { setUrl(null); return; }
        const value = URL.createObjectURL(file);
        setUrl(value);
        return () => URL.revokeObjectURL(value);
    }, [file]);
    return removed ? null : file ? url : existing;
}

export default function IdCardSettingsPanel({ payload, readOnly, management }: Props) {
    const { t, locale } = useLocale();
    const label = (key: string) => t(`settings.pngCards.${key}`);
    const templateLabel = (key: string) => t(`settings.templateManager.${key}`);
    const active = useIdCardTemplate();
    const templates = management?.templates ?? [];
    const [selectedId, setSelectedId] = useState<string>(templates[0]?.id ?? '');
    const selected = templates.find(template => template.id === selectedId);
    const editor = useForm(templateValues(selected));
    const fields = payload.fields.filter(field => field.type !== 'color');
    const initialSettings = useMemo(() => Object.fromEntries(payload.fields
        .filter(field => field.type !== 'color')
        .map(field => [field.key, (field.value ?? field.default ?? '') as SettingValue])), [payload.fields]);
    const settings = useForm<Record<string, SettingValue>>(initialSettings);
    const canEdit = Boolean(management && (selected ? management.can.update : management.can.create));
    const frontUrl = useBackground(editor.data.front_background, selected?.front_background_url ?? null, editor.data.remove_front_background);
    const backUrl = useBackground(editor.data.back_background, selected?.back_background_url ?? null, editor.data.remove_back_background);
    const preview: TemplatePresentation | null = management ? {
        orientation: editor.data.orientation, width_mm: editor.data.width_mm || 85.6,
        height_mm: editor.data.height_mm || 54, front_background_url: frontUrl, back_background_url: backUrl,
    } : active;
    const Front = preview?.orientation === 'portrait' ? IdCardPortraitFront : IdCardFront;
    const Back = preview?.orientation === 'portrait' ? IdCardPortraitBack : IdCardBack;
    const previewSettings = Object.fromEntries(Object.entries(settings.data).map(([key, value]) => [`id_cards.${key}`, value]));
    const [exportPreview, setExportPreview] = useState(false);

    useEffect(() => {
        if (!settings.isDirty) settings.setData(initialSettings);
    }, [initialSettings]);

    function chooseTemplate(id: string) {
        if (editor.isDirty && !window.confirm(label('discard_changes'))) return;
        const values = templateValues(templates.find(template => template.id === id));
        setSelectedId(id);
        editor.setData(values);
        editor.clearErrors();
    }

    function saveSettings(event: FormEvent) {
        event.preventDefault();
        settings.patch(route('system-settings.id-cards.update'), {
            preserveScroll: true,
            onError: () => toast.error(label('validation_error')),
        });
    }

    function saveTemplate(event: FormEvent) {
        event.preventDefault();
        editor.transform(data => selected ? { ...data, _method: 'patch' } : data);
        editor.post(route(selected ? 'id-card-templates.update' : 'id-card-templates.store', selected?.id), {
            forceFormData: true, preserveScroll: true,
            onSuccess: page => {
                const updated = (page.props.templateManagement as TemplateManagement | null)?.templates
                    .find(template => template.code === editor.data.code);
                if (updated) {
                    setSelectedId(updated.id);
                    const values = templateValues(updated);
                    editor.setData(values);
                }
            },
            onError: () => toast.error(label('validation_error')),
        });
    }

    const error = (field: keyof typeof editor.data) => editor.errors[field] && <p role="alert" className="mt-1 text-xs text-red-600">{editor.errors[field]}</p>;
    const renderFields = (list: SettingsField[]) => list.map(field => <SettingField key={field.key} field={field} locale={locale}
        value={settings.data[field.key]} error={settings.errors[field.key]} disabled={readOnly || settings.processing}
        onChange={value => settings.setData(field.key, value as SettingValue)} />);
    const general = fields.filter(field => ['template', 'card_padding'].includes(field.key));
    const visibility = fields.filter(field => field.type === 'boolean');
    const front = fields.filter(field => !general.includes(field) && !visibility.includes(field) && !field.key.startsWith('return_') && !field.key.startsWith('back_') && field.key !== 'qr_size');
    const back = fields.filter(field => !general.includes(field) && !visibility.includes(field) && !front.includes(field));

    return <div data-id-card-settings="png" className="grid min-w-0 grid-cols-1 items-start gap-6 lg:grid-cols-2">
        <form onSubmit={saveSettings} className="min-w-0 space-y-5" aria-label={label('general')}>
            <Card title={label('general')} helper={label('general_help')}>{renderFields(general)}</Card>
            <Card title={label('size_orientation')} helper={label('size_help')}>
                <fieldset disabled={!canEdit || editor.processing} className="min-w-0 space-y-3">
                    <label className="block text-sm font-medium">{templateLabel('orientation')}
                        <select form={templateFormId} className={inputClass} value={management ? editor.data.orientation : active?.orientation ?? 'landscape'} onChange={event => {
                            const orientation = event.target.value as 'landscape' | 'portrait';
                            const long = Math.max(editor.data.width_mm, editor.data.height_mm);
                            const short = Math.min(editor.data.width_mm, editor.data.height_mm);
                            editor.setData(data => ({ ...data, orientation, width_mm: orientation === 'portrait' ? short : long, height_mm: orientation === 'portrait' ? long : short }));
                        }}><option value="landscape">{templateLabel('landscape')}</option><option value="portrait">{templateLabel('portrait')}</option></select>{error('orientation')}
                    </label>
                    <div className="grid min-w-0 grid-cols-2 gap-3">{(['width_mm', 'height_mm'] as const).map(field => <label key={field} className="min-w-0 text-sm font-medium">
                        {templateLabel(field)}<input form={templateFormId} type="number" min="30" max="200" step="0.01" required className={inputClass}
                            value={management ? editor.data[field] || '' : active?.[field] ?? (field === 'width_mm' ? 85.6 : 54)} onChange={event => editor.setData(field, Number(event.target.value))} />{error(field)}
                    </label>)}</div>
                </fieldset>
            </Card>
            <Card title={label('visibility')} helper={label('visibility_help')}>{renderFields(visibility)}</Card>
            <Card title={label('front_fields')}>{renderFields(front)}</Card>
            <Card title={label('back_fields')}>{renderFields(back)}</Card>
            <div className="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-3 rounded-xl border bg-white/95 p-3 backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
                <span role="status" className="mr-auto text-xs text-gray-500">{settings.isDirty ? t('settings.unsavedChanges') : ''}</span>
                <Button type="button" variant="outline" disabled={readOnly || settings.processing || !settings.isDirty} onClick={() => { settings.reset(); settings.clearErrors(); }}>{label('reset')}</Button>
                <Button type="submit" loading={settings.processing} disabled={readOnly || !settings.isDirty}>{label('save_fields')}</Button>
            </div>
        </form>

        <div className="min-w-0 space-y-5">
            <Card title={templateLabel('title')} helper={label('png_design')}>
                {management ? <div className="flex min-w-0 flex-wrap items-end gap-3">
                    <label className="min-w-0 flex-1 text-sm font-medium">{label('select_template')}
                        <select className={inputClass} value={selectedId} onChange={event => chooseTemplate(event.target.value)}>
                            <option value="">{templateLabel('create')}</option>
                            {templates.map(template => <option key={template.id} value={template.id}>{template.name}{template.is_default ? ` (${templateLabel('default')})` : ''}</option>)}
                        </select>
                    </label>
                    {management.can.create && <Button type="button" variant="outline" className="min-h-11" onClick={() => chooseTemplate('')}>{templateLabel('create')}</Button>}
                </div> : <p className="text-sm text-gray-500">{label('template_read_only')}</p>}
                <p className="text-xs text-gray-500">{label('gradient_unused')}</p>
            </Card>

            {management && <form id={templateFormId} onSubmit={saveTemplate} className="min-w-0 space-y-5" aria-label={label('template_settings')}>
                <Card title={label('template_settings')}>
                    <fieldset disabled={!canEdit || editor.processing} className="min-w-0 space-y-3">
                        {(['name', 'code', 'description'] as const).map(field => <label key={field} className="block text-sm font-medium">
                            {templateLabel(field)}<input className={inputClass} required={field !== 'description'} maxLength={field === 'code' ? 80 : field === 'description' ? 2000 : 255}
                                value={editor.data[field]} onChange={event => editor.setData(field, event.target.value)} />{error(field)}
                        </label>)}
                        <label className="block text-sm font-medium">{templateLabel('status')}
                            <select className={inputClass} value={editor.data.status} onChange={event => editor.setData('status', event.target.value as 'active' | 'inactive')}>
                                <option value="active">{templateLabel('active')}</option><option value="inactive">{templateLabel('inactive')}</option>
                            </select>{error('status')}
                        </label>
                    </fieldset>
                </Card>
                {(['front', 'back'] as const).map(side => {
                    const field = `${side}_background` as const;
                    const remove = `remove_${side}_background` as const;
                    const url = side === 'front' ? frontUrl : backUrl;
                    return <Card key={side} title={templateLabel(field)} helper={templateLabel('upload_help').replace(':max', String(management.uploadLimitMb))}>
                        {url && <div className="rounded-lg bg-gray-100 p-2 dark:bg-slate-800"><img src={url} alt={label('background_preview')} className="h-28 w-full object-contain" /></div>}
                        <div className="flex flex-wrap gap-2">
                            <label className={`relative flex min-h-11 max-w-full cursor-pointer items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 focus-within:ring-2 focus-within:ring-blue-500 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200 ${!canEdit ? 'opacity-50' : ''}`}>
                                {templateLabel(url ? 'replace_background' : 'browse_png')}
                                <input type="file" accept=".png,image/png" aria-label={templateLabel(field)} className="absolute inset-0 w-full cursor-pointer opacity-0" disabled={!canEdit || editor.processing}
                                    onChange={event => {
                                        const file = event.target.files?.[0]; event.target.value = '';
                                        if (!file) return;
                                        if (file.type !== 'image/png' || !/\.png$/i.test(file.name)) { editor.setError(field, templateLabel('png_only')); return; }
                                        if (file.size > management.uploadLimitMb * 1024 * 1024) { editor.setError(field, templateLabel('upload_help').replace(':max', String(management.uploadLimitMb))); return; }
                                        editor.clearErrors(field);
                                        editor.setData(data => ({ ...data, [field]: file, [remove]: false }));
                                    }} />
                            </label>
                            {url && <Button type="button" variant="outline" className="min-h-11" disabled={!canEdit || editor.processing} onClick={() => editor.setData(data => ({ ...data, [field]: null, [remove]: true }))}>{templateLabel('remove_background')}</Button>}
                        </div>
                        <p role="status" className="break-all text-xs text-gray-500">{editor.data[field] ? `${editor.data[field]?.name} · ${label('ready_to_upload')}` : editor.data[remove] ? label('remove_pending') : url ? label('uploaded') : label('no_background')}</p>
                        {error(field)}
                    </Card>;
                })}
                <Card title={label('default_template')} helper={label('default_help')}>
                    <label className="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" className="h-5 w-5 rounded" checked={editor.data.is_default} disabled={!canEdit || !management.can.set_default || editor.processing}
                        onChange={event => editor.setData('is_default', event.target.checked)} />{templateLabel('set_default')}</label>{error('is_default')}
                    {selected && !selected.is_default && selected.status === 'active' && management.can.set_default && <Button type="button" variant="outline" disabled={editor.isDirty} onClick={() => router.post(route('id-card-templates.set-default', selected.id), {}, { preserveScroll: true, onSuccess: () => { editor.setData('is_default', true); } })}>{templateLabel('set_default')}</Button>}
                </Card>
                <div className="sticky bottom-0 z-10 space-y-2 rounded-xl border bg-white/95 p-3 backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
                    {editor.progress && <progress aria-label={templateLabel('upload_background')} className="w-full" value={editor.progress.percentage} max="100" />}
                    <div className="flex flex-wrap justify-end gap-3">
                        <Button type="button" variant="outline" disabled={!canEdit || editor.processing || !editor.isDirty} onClick={() => { editor.reset(); editor.clearErrors(); }}>{label('reset')}</Button>
                        <Button type="submit" loading={editor.processing} disabled={!canEdit || (!editor.isDirty && Boolean(selected))}>{templateLabel('save')}</Button>
                    </div>
                </div>
            </form>}

            <Card title={templateLabel('live_preview')} helper={label('preview_help')}>
                <IdCardTemplateContext.Provider value={preview}>
                    <SystemSettingsPreviewContext.Provider value={previewSettings}>
                        <div data-card-live-preview className={`min-w-0 space-y-5 ${exportPreview ? 'rounded-xl bg-slate-200 p-3 dark:bg-slate-950' : ''}`}>
                            <div className="min-w-0"><p className="mb-2 text-xs font-medium text-gray-500">{t('idCards.cardFront')}</p>
                                <Front cardNumber="CARD-0001" employeeNumber="EMP-0001" fullName={templateLabel('sample_employee')} fullNameAm="ምሳሌ ሰራተኛ" organizationName={templateLabel('sample_organization')} status="active" rootStyle={{ maxWidth: preview?.orientation === 'portrait' ? 270 : 428 }} />
                            </div>
                            <div className="min-w-0"><p className="mb-2 text-xs font-medium text-gray-500">{t('idCards.cardBack')}</p>
                                <Back cardNumber="CARD-0001" qrValue="https://example.invalid/id-card-preview" rootStyle={{ maxWidth: preview?.orientation === 'portrait' ? 270 : 428 }} />
                            </div>
                        </div>
                    </SystemSettingsPreviewContext.Provider>
                </IdCardTemplateContext.Provider>
                <Button type="button" variant="outline" className="min-h-11" aria-pressed={exportPreview} onClick={() => setExportPreview(value => !value)}>{label('print_export_preview')}</Button>
                {exportPreview && <p role="status" className="text-xs text-gray-500">{label('export_help')} {preview?.width_mm ?? 85.6} × {preview?.height_mm ?? 54} mm</p>}
            </Card>
        </div>
    </div>;
}
