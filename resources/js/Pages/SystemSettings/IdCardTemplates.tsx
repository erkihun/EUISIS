import { useEffect, useState, type FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Button from '@/Components/Button';
import IdCardFront from '@/Components/IdCards/IdCardFront';
import IdCardBack from '@/Components/IdCards/IdCardBack';
import IdCardPortraitFront from '@/Components/IdCards/IdCardPortraitFront';
import IdCardPortraitBack from '@/Components/IdCards/IdCardPortraitBack';
import TemplateLayoutDesigner from '@/Components/IdCards/TemplateLayoutDesigner';
import {
    IdCardTemplateContext,
    FRONT_ROLES,
    BACK_ROLES,
    BACK_LAYOUT_DEFAULTS,
    BACK_LAYOUT_ELEMENTS,
    LAYOUT_DEFAULTS,
    LAYOUT_ELEMENTS,
    type LayoutBox,
    type LayoutElement,
    type TemplatePresentation,
    type TextStyle,
    type TextStyleConfig,
} from '@/Components/IdCards/IdCardTemplateContext';
import { useLocale } from '@/hooks/useLocale';
import TemplateEditorSection from '@/Components/IdCards/TemplateEditorSection';
import TemplateWizardSteps from '@/Components/IdCards/TemplateWizardSteps';

type Template = TemplatePresentation & {
    id: string;
    name: string;
    code: string;
    description: string | null;
    status: 'active' | 'inactive';
    is_default: boolean;
};
type Can = { create: boolean; update: boolean; delete: boolean; set_default: boolean };
type Props = { templates: Template[]; can: Can; uploadLimitMb: number };

const FONT_SIZES = ['7px', '8px', '9px', '10px', '11px', '12px', '13px', '14px', '15px', '16px', '18px', '20px'] as const;
const FONT_WEIGHTS = ['400', '500', '600', '700', '800'] as const;

/** Mirrors IdCardTextStyle::ROLES so a new template previews as it will save. */
const ROLE_DEFAULTS: Record<'front' | 'back', Record<string, TextStyle>> = {
    front: {
        header: { color: '#FFFFFF', font_size: '9px', font_weight: '700' },
        label: { color: '#BFDBFE', font_size: '7px', font_weight: '400' },
        value: { color: '#FFFFFF', font_size: '10px', font_weight: '600' },
        footer: { color: '#BFDBFE', font_size: '7px', font_weight: '400' },
    },
    back: {
        header: { color: '#94A3B8', font_size: '9px', font_weight: '700' },
        label: { color: '#94A3B8', font_size: '7px', font_weight: '400' },
        value: { color: '#94A3B8', font_size: '8px', font_weight: '600' },
        footer: { color: '#94A3B8', font_size: '7px', font_weight: '400' },
    },
};

/** Every styling group shown on the form, in display order. */
const STYLE_GROUPS = [
    ...FRONT_ROLES.map((role) => ({ side: 'front' as const, role })),
    ...BACK_ROLES.map((role) => ({ side: 'back' as const, role })),
];

/** Fills in any role the template has not stored yet. */
function withDefaults(config: TextStyleConfig | null | undefined): {
    front: NonNullable<Required<TextStyleConfig>['front']>;
    back: NonNullable<Required<TextStyleConfig>['back']>;
} {
    const resolve = (side: 'front' | 'back', roles: readonly string[]) =>
        Object.fromEntries(
            roles.map((role) => [role, config?.[side]?.[role as keyof object] ?? ROLE_DEFAULTS[side][role]]),
        );
    return {
        front: resolve('front', FRONT_ROLES) as NonNullable<Required<TextStyleConfig>['front']>,
        back: resolve('back', BACK_ROLES) as NonNullable<Required<TextStyleConfig>['back']>,
    };
}

function useBackgroundPreview(file: File | null, saved: string | null, removed: boolean) {
    const [url, setUrl] = useState<string | null>(null);
    useEffect(() => {
        if (!file) {
            setUrl(null);
            return;
        }
        const next = URL.createObjectURL(file);
        setUrl(next);
        return () => URL.revokeObjectURL(next);
    }, [file]);
    return removed ? null : file ? url : saved;
}

function TemplateForm({
    template,
    can,
    uploadLimitMb,
    onSaved,
}: {
    template: Template | null;
    can: Can;
    uploadLimitMb: number;
    onSaved: () => void;
}) {
    const { t } = useLocale();
    const label = (key: string) => t(`settings.templateManager.${key}`);
    const editable = template ? can.update : can.create;
    const form = useForm({
        name: template?.name ?? '',
        code: template?.code ?? '',
        description: template?.description ?? '',
        orientation: template?.orientation ?? ('landscape' as 'landscape' | 'portrait'),
        width_mm: template?.width_mm ?? 85.6,
        height_mm: template?.height_mm ?? 54,
        status: template?.status ?? ('active' as 'active' | 'inactive'),
        is_default: template?.is_default ?? false,
        front_background: null as File | null,
        back_background: null as File | null,
        remove_front_background: false,
        remove_back_background: false,
        text_style_config: withDefaults(template?.text_style_config),
        layout_config: {
            front: Object.fromEntries(
                LAYOUT_ELEMENTS.map((element) => [
                    element,
                    template?.layout_config?.front?.[element] ?? LAYOUT_DEFAULTS[element],
                ]),
            ) as Record<string, LayoutBox>,
            back: Object.fromEntries(
                BACK_LAYOUT_ELEMENTS.map((element) => [
                    element,
                    template?.layout_config?.back?.[element] ?? BACK_LAYOUT_DEFAULTS[element],
                ]),
            ) as Record<string, LayoutBox>,
        },
    });
    // Creating walks the steps in order; editing opens them all at once.
    const stepKeys = ['stepDetails', 'stepSize', 'stepBackground', 'stepDesign', 'stepReview'] as const;
    const [step, setStep] = useState(0);
    const [furthest, setFurthest] = useState(template ? stepKeys.length - 1 : 0);
    const goTo = (next: number) => {
        setStep(next);
        setFurthest((seen) => Math.max(seen, next));
    };

    const frontUrl = useBackgroundPreview(
        form.data.front_background,
        template?.front_background_url ?? null,
        form.data.remove_front_background,
    );
    const backUrl = useBackgroundPreview(
        form.data.back_background,
        template?.back_background_url ?? null,
        form.data.remove_back_background,
    );
    const presentation: TemplatePresentation = {
        orientation: form.data.orientation,
        width_mm: form.data.width_mm || 85.6,
        height_mm: form.data.height_mm || 54,
        front_background_url: frontUrl,
        back_background_url: backUrl,
        text_style_config: form.data.text_style_config,
        layout_config: form.data.layout_config,
    };
    const portrait = form.data.orientation === 'portrait';
    const Front = portrait ? IdCardPortraitFront : IdCardFront;
    const Back = portrait ? IdCardPortraitBack : IdCardBack;
    const inputClass =
        'mt-1 w-full rounded-lg border-gray-300 bg-white text-sm dark:border-slate-700 dark:bg-slate-900';
    const error = (field: keyof typeof form.data) =>
        form.errors[field] && (
            <p className="mt-1 text-sm text-red-600" role="alert">
                {form.errors[field]}
            </p>
        );

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => (template ? { ...data, _method: 'patch' } : data));
        form.post(
            route(
                template ? 'id-card-templates.update' : 'id-card-templates.store',
                template ? template.id : undefined,
            ),
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: onSaved,
            },
        );
    }

    return (
        <form onSubmit={submit} className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]">
            <fieldset disabled={!editable || form.processing} className="min-w-0 space-y-4">
                <div className="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-950">
                    <TemplateWizardSteps
                        steps={stepKeys.map((key) => label(key))}
                        current={step}
                        furthest={furthest}
                        onSelect={setStep}
                    />
                </div>

                {/* Identity — the fields an admin always fills in. */}
                {step === 0 && <TemplateEditorSection title={label(template ? 'edit' : 'create')}>
                {(['name', 'code', 'description'] as const).map((field) => (
                    <label key={field} className="block text-sm font-medium">
                        {label(field)}
                        <input
                            className={inputClass}
                            value={form.data[field]}
                            maxLength={field === 'code' ? 80 : field === 'description' ? 2000 : 255}
                            required={field !== 'description'}
                            onChange={(e) => form.setData(field, e.target.value)}
                        />
                        {error(field)}
                    </label>
                ))}
                </TemplateEditorSection>}

                {step === 1 && <TemplateEditorSection title={label('layoutSection')} description={label('layoutSectionHelp')}>
                <label className="block text-sm font-medium">
                    {label('orientation')}
                    <select
                        className={inputClass}
                        value={form.data.orientation}
                        onChange={(e) => {
                            const orientation = e.target.value as 'portrait' | 'landscape';
                            const [short, long] = [
                                Math.min(form.data.width_mm, form.data.height_mm),
                                Math.max(form.data.width_mm, form.data.height_mm),
                            ];
                            form.setData((data) => ({
                                ...data,
                                orientation,
                                width_mm: orientation === 'portrait' ? short : long,
                                height_mm: orientation === 'portrait' ? long : short,
                            }));
                        }}
                    >
                        <option value="landscape">{label('landscape')}</option>
                        <option value="portrait">{label('portrait')}</option>
                    </select>
                    {error('orientation')}
                </label>
                <div className="grid grid-cols-2 gap-3">
                    {(['width_mm', 'height_mm'] as const).map((field) => (
                        <label key={field} className="text-sm font-medium">
                            {label(field)}
                            <input
                                type="number"
                                min="30"
                                max="200"
                                step="0.01"
                                required
                                className={inputClass}
                                value={form.data[field] || ''}
                                onChange={(e) => form.setData(field, Number(e.target.value))}
                            />
                            {error(field)}
                        </label>
                    ))}
                </div>
                </TemplateEditorSection>}

                {step === 2 && <TemplateEditorSection title={label('backgroundsSection')} description={label('backgroundsSectionHelp')}>
                <p className="text-xs text-gray-500">{label('upload_help').replace(':max', String(uploadLimitMb))}</p>
                {(['front', 'back'] as const).map((side) => {
                    const field = `${side}_background` as const;
                    const remove = `remove_${side}_background` as const;
                    const url = side === 'front' ? frontUrl : backUrl;
                    return (
                        <div
                            key={side}
                            className="space-y-2 rounded-lg border border-dashed border-gray-300 p-3 dark:border-slate-700"
                        >
                            <p className="text-sm font-semibold">{label(field)}</p>
                            {url && (
                                <img
                                    src={url}
                                    alt={label('background_preview')}
                                    className="h-24 w-full rounded object-contain"
                                />
                            )}
                            <label className="inline-flex cursor-pointer items-center rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-slate-800">
                                {label(url ? 'replace_background' : 'browse_png')}
                                <input
                                    type="file"
                                    accept=".png,image/png"
                                    className="sr-only"
                                    disabled={!editable || form.processing}
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        e.target.value = '';
                                        if (!file) return;
                                        if (file.type !== 'image/png' || !/\.png$/i.test(file.name)) {
                                            form.setError(field, label('png_only'));
                                            return;
                                        }
                                        if (file.size > uploadLimitMb * 1024 * 1024) {
                                            form.setError(
                                                field,
                                                label('upload_help').replace(':max', String(uploadLimitMb)),
                                            );
                                            return;
                                        }
                                        form.clearErrors(field);
                                        form.setData((data) => ({ ...data, [field]: file, [remove]: false }));
                                    }}
                                />
                            </label>
                            {url && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="ml-2"
                                    onClick={() => form.setData((data) => ({ ...data, [field]: null, [remove]: true }))}
                                >
                                    {label('remove_background')}
                                </Button>
                            )}
                            {form.data[field] && (
                                <p className="break-all text-xs">
                                    {form.data[field]?.name} — {label('upload_background')}
                                </p>
                            )}
                            {error(field)}
                        </div>
                    );
                })}
                </TemplateEditorSection>}

                {step === 3 && <>{/* Text Style Management — every text role on both card sides. */}
                <TemplateEditorSection
                    title={label('text_style_management')}
                    description={label('text_styling_help')}
                    defaultOpen={false}
                    badge={<span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-slate-800 dark:text-slate-300">{STYLE_GROUPS.length}</span>}
                >
                    {STYLE_GROUPS.map(({ side, role }) => {
                        const style = form.data.text_style_config[side][role as keyof object] as TextStyle;
                        const groupKey = `${side}_${role}_text`;
                        const set = (patch: Partial<TextStyle>) =>
                            form.setData('text_style_config', {
                                ...form.data.text_style_config,
                                [side]: { ...form.data.text_style_config[side], [role]: { ...style, ...patch } },
                            });
                        const errorFor = (key: string) =>
                            form.errors[`text_style_config.${side}.${role}.${key}` as keyof typeof form.errors];
                        return (
                            <div key={groupKey} className="space-y-2 rounded-md bg-gray-50 p-3 dark:bg-slate-900">
                                <p className="text-sm font-semibold">{label(groupKey)}</p>
                                <div className="grid gap-2 sm:grid-cols-3">
                                    <label className="text-xs font-medium">
                                        {label('text_color')}
                                        <input
                                            type="color"
                                            className="mt-1 h-9 w-full rounded border border-gray-300 dark:border-slate-700"
                                            value={style.color}
                                            aria-label={`${label(groupKey)} — ${label('text_color')}`}
                                            onChange={(e) => set({ color: e.target.value })}
                                        />
                                    </label>
                                    <label className="text-xs font-medium">
                                        {label('font_size')}
                                        <select
                                            className={inputClass}
                                            value={style.font_size}
                                            aria-label={`${label(groupKey)} — ${label('font_size')}`}
                                            onChange={(e) => set({ font_size: e.target.value })}
                                        >
                                            {FONT_SIZES.map((size) => (
                                                <option key={size} value={size}>{size}</option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="text-xs font-medium">
                                        {label('font_weight')}
                                        <select
                                            className={inputClass}
                                            value={style.font_weight}
                                            aria-label={`${label(groupKey)} — ${label('font_weight')}`}
                                            onChange={(e) => set({ font_weight: e.target.value })}
                                        >
                                            {FONT_WEIGHTS.map((weight) => (
                                                <option key={weight} value={weight}>{label(`weight_${weight}`)}</option>
                                            ))}
                                        </select>
                                    </label>
                                </div>
                                {(['color', 'font_size', 'font_weight'] as const).map((key) =>
                                    errorFor(key) ? (
                                        <p key={key} className="text-sm text-red-600" role="alert">{errorFor(key)}</p>
                                    ) : null,
                                )}
                            </div>
                        );
                    })}
                </TemplateEditorSection></>}

                {step === 4 && <TemplateEditorSection title={label('publishingSection')} description={label('publishingSectionHelp')}>
                <label className="block text-sm font-medium">
                    {label('status')}
                    <select
                        className={inputClass}
                        value={form.data.status}
                        onChange={(e) => form.setData('status', e.target.value as 'active' | 'inactive')}
                    >
                        <option value="active">{label('active')}</option>
                        <option value="inactive">{label('inactive')}</option>
                    </select>
                    {error('status')}
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.is_default}
                        disabled={!can.set_default}
                        onChange={(e) => form.setData('is_default', e.target.checked)}
                    />
                    {label('set_default')}
                </label>
                {error('is_default')}
                </TemplateEditorSection>}

                {form.progress && <progress className="w-full" value={form.progress.percentage} max="100" />}
                {editable && (
                    // Sticks to the bottom so navigation is always reachable.
                    <div className="sticky bottom-0 -mx-1 flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-950/95">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={step === 0 || form.processing}
                            onClick={() => setStep((current) => Math.max(0, current - 1))}
                        >
                            {label('back')}
                        </Button>
                        {step < stepKeys.length - 1 ? (
                            <Button type="button" disabled={form.processing} onClick={() => goTo(step + 1)}>
                                {label('next')}
                            </Button>
                        ) : (
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? label('saving') : label('save')}
                            </Button>
                        )}
                        <span className="text-xs text-gray-500">
                            {label('stepCounter')
                                .replace(':current', String(step + 1))
                                .replace(':total', String(stepKeys.length))}
                        </span>
                        {form.isDirty && !form.processing && (
                            <span className="text-xs text-amber-600 dark:text-amber-400">{label('unsavedChanges')}</span>
                        )}
                    </div>
                )}
            </fieldset>
            {/* Preview follows the admin down the form. */}
            <div className="space-y-4 self-start rounded-xl border border-gray-200 bg-gray-50 p-5 xl:sticky xl:top-4 dark:border-slate-800 dark:bg-slate-900">
                <h2 className="font-semibold">{label('live_preview')}</h2>
                <p className="text-xs text-gray-500">{label('preview_help')}</p>
                <IdCardTemplateContext.Provider value={presentation}>
                    <div className="space-y-4">
                        {/* The designer overlays the real preview, so the box an
                            admin drags is the region that prints. */}
                        <div className="relative" style={{ width: '100%', maxWidth: portrait ? 260 : 400 }}>
                            <Front
                                cardNumber="CARD-0001"
                                employeeNumber="EMP-0001"
                                fullName={label('sample_employee')}
                                fullNameAm="ምሳሌ ሰራተኛ"
                                organizationName={label('sample_organization')}
                                status="active"
                            />
                            {!portrait && (
                                <TemplateLayoutDesigner
                                    side="front"
                                    value={form.data.layout_config.front}
                                    onChange={(front) =>
                                        form.setData('layout_config', { ...form.data.layout_config, front })
                                    }
                                    disabled={!editable || form.processing}
                                    labels={
                                        Object.fromEntries(
                                            LAYOUT_ELEMENTS.map((element) => [element, label(`layout_${element}`)]),
                                        ) as Record<LayoutElement, string>
                                    }
                                    text={{
                                        reset: label('layout_reset'),
                                        resetAll: label('layout_reset_all'),
                                        hint: label('layout_hint'),
                                    }}
                                />
                            )}
                        </div>
                        <div className="relative" style={{ width: '100%', maxWidth: portrait ? 260 : 400 }}>
                            <Back cardNumber="CARD-0001" qrValue="https://example.invalid/id-card-preview" />
                            {!portrait && (
                                <TemplateLayoutDesigner
                                    side="back"
                                    value={form.data.layout_config.back}
                                    onChange={(back) =>
                                        form.setData('layout_config', { ...form.data.layout_config, back })
                                    }
                                    disabled={!editable || form.processing}
                                    labels={Object.fromEntries(
                                        BACK_LAYOUT_ELEMENTS.map((element) => [element, label(`layout_${element}`)]),
                                    )}
                                    text={{
                                        reset: label('layout_reset'),
                                        resetAll: label('layout_reset_all'),
                                        hint: label('layout_hint'),
                                    }}
                                />
                            )}
                        </div>
                    </div>
                </IdCardTemplateContext.Provider>
            </div>
        </form>
    );
}

export default function IdCardTemplates({ templates, can, uploadLimitMb }: Props) {
    const { t } = useLocale();
    const label = (key: string) => t(`settings.templateManager.${key}`);
    const [selected, setSelected] = useState<string | null>(templates[0]?.id ?? null);
    const [revision, setRevision] = useState(0);
    const template = templates.find((item) => item.id === selected) ?? null;
    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('help')} />}>
            <Head title={label('title')} />
            <div className="space-y-5">
                <Link
                    href={route('system-settings.index', { tab: 'id_cards' })}
                    className="text-sm text-blue-600 hover:underline"
                >
                    {t('settings.title')} → {t('settings.tabs.id_cards')}
                </Link>
                <div className="grid gap-5 lg:grid-cols-[240px_minmax(0,1fr)]">
                    {/* Template list — one row per template, with its state visible. */}
                    <aside className="space-y-2">
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{label('title')}</h2>
                            {can.create && (
                                <button
                                    type="button"
                                    className="rounded-lg border border-dashed border-gray-300 px-2 py-1 text-xs font-medium hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800"
                                    onClick={() => {
                                        setSelected(null);
                                        setRevision((value) => value + 1);
                                    }}
                                >
                                    + {label('create')}
                                </button>
                            )}
                        </div>
                        {templates.length === 0 && <p className="text-sm text-gray-500">{label('empty')}</p>}
                        {templates.map((item) => (
                            <button
                                key={item.id}
                                type="button"
                                aria-current={selected === item.id}
                                onClick={() => setSelected(item.id)}
                                className={[
                                    'block w-full rounded-lg border px-3 py-2 text-left transition-colors',
                                    selected === item.id
                                        ? 'border-blue-500 bg-blue-50 dark:border-blue-500 dark:bg-blue-950/40'
                                        : 'border-gray-200 hover:bg-gray-50 dark:border-slate-800 dark:hover:bg-slate-900',
                                ].join(' ')}
                            >
                                <span className="block truncate text-sm font-medium text-gray-900 dark:text-slate-100">
                                    {item.name}
                                </span>
                                <span className="mt-1 flex flex-wrap items-center gap-1">
                                    {item.is_default && (
                                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            {label('default')}
                                        </span>
                                    )}
                                    <span
                                        className={[
                                            'rounded-full px-2 py-0.5 text-[10px] font-medium',
                                            item.status === 'active'
                                                ? 'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-300'
                                                : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                                        ].join(' ')}
                                    >
                                        {label(item.status)}
                                    </span>
                                </span>
                            </button>
                        ))}
                    </aside>

                    <div className="min-w-0 space-y-4">
                        {template && (
                            // Per-template actions live beside the template they affect.
                            <div className="flex flex-wrap items-center gap-2">
                                {can.set_default && !template.is_default && template.status === 'active' && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                route('id-card-templates.set-default', template.id),
                                                {},
                                                { onSuccess: () => setRevision((value) => value + 1) },
                                            )
                                        }
                                    >
                                        {label('set_default')}
                                    </Button>
                                )}
                                {can.delete && (!template.is_default || can.set_default) && (
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="sm"
                                        onClick={() => {
                                            if (window.confirm(label('confirm_delete')))
                                                router.delete(route('id-card-templates.destroy', template.id), {
                                                    onSuccess: () => setSelected(null),
                                                });
                                        }}
                                    >
                                        {label('delete')}
                                    </Button>
                                )}
                            </div>
                        )}
                        {template || can.create ? (
                            <TemplateForm
                                key={`${selected}-${revision}-${template?.front_background_url}-${template?.back_background_url}`}
                                template={template}
                                can={can}
                                uploadLimitMb={uploadLimitMb}
                                onSaved={() => setRevision((value) => value + 1)}
                            />
                        ) : (
                            <p className="text-sm text-gray-500">{label('empty')}</p>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
