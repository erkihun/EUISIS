import { useEffect, useState, type FormEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Button from '@/Components/Button';
import { useConfirm } from '@/hooks/useConfirm';
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
    LANDSCAPE_FRONT_LAYOUT_ELEMENTS,
    LAYOUT_DEFAULTS,
    LAYOUT_ELEMENTS,
    PORTRAIT_BACK_LAYOUT_ELEMENTS,
    PORTRAIT_FRONT_LAYOUT_ELEMENTS,
    type LayoutBox,
    type LayoutElement,
    type TemplatePresentation,
    type TextStyle,
    type TextStyleConfig,
} from '@/Components/IdCards/IdCardTemplateContext';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import enSettings from '@/i18n/en/settings';
import amSettings from '@/i18n/am/settings';
import TemplateEditorSection from '@/Components/IdCards/TemplateEditorSection';
import TemplateWizardSteps from '@/Components/IdCards/TemplateWizardSteps';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import StatusBadge from '@/Components/StatusBadge';

type Template = TemplatePresentation & {
    id: string;
    name: string;
    code: string;
    description: string | null;
    status: 'active' | 'inactive';
    is_default: boolean;
    employee_fields?: string[] | null;
    /**
     * What the template itself stores, as opposed to header_config which is
     * already resolved against the system settings. The editor edits these so a
     * field left blank stays blank rather than freezing the inherited text.
     */
    header_overrides?: {
        city_name_en: string;
        city_name_am: string;
        bureau_name_en: string;
        bureau_name_am: string;
        show_logo: boolean;
        show_secondary_logo: boolean;
    } | null;
};
type Can = { create: boolean; update: boolean; delete: boolean; set_default: boolean };
type Props = { templates: Template[]; can: Can; uploadLimitMb: number };

/**
 * Stand-in portrait for the live preview: a neutral silhouette, inlined as a
 * data URI so the editor makes no request and shows no real person.
 */
const SAMPLE_PHOTO =
    'data:image/svg+xml;utf8,' +
    encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 130">' +
        '<rect width="100" height="130" fill="#CBD5E1"/>' +
        '<circle cx="50" cy="45" r="22" fill="#94A3B8"/>' +
        '<path d="M8 130c0-25 19-42 42-42s42 17 42 42z" fill="#94A3B8"/>' +
        '</svg>',
    );

const FONT_SIZES = ['7px', '8px', '9px', '10px', '11px', '12px', '13px', '14px', '15px', '16px', '18px', '20px'] as const;
const FONT_WEIGHTS = ['400', '500', '600', '700', '800'] as const;

/** Mirrors IdCardTextStyle::ROLES so a new template previews as it will save. */
const ROLE_DEFAULTS: Record<'front' | 'back', Record<string, TextStyle>> = {
    front: {
        header: { color: '#FFFFFF', font_size: '9px', font_weight: '700' },
        label: { color: '#BFDBFE', font_size: '7px', font_weight: '400' },
        value: { color: '#FFFFFF', font_size: '10px', font_weight: '600' },
        employee_name: { color: '#FFFFFF', font_size: '12px', font_weight: '700' },
        employee_position: { color: '#BFDBFE', font_size: '9px', font_weight: '600' },
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
    onStateChange,
}: {
    template: Template | null;
    can: Can;
    uploadLimitMb: number;
    onSaved: (id: string | null) => void;
    onStateChange: (state: { dirty: boolean; processing: boolean }) => void;
}) {
    const { t } = useLocale();
    const { getString } = useSystemSettings();
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
        employee_fields: template?.employee_fields ?? ['name', 'sex', 'dob', 'nationality', 'employment', 'phone', 'idNumber', 'emergency_contact_name', 'emergency_contact_phone'],
        front_background: null as File | null,
        back_background: null as File | null,
        logo_primary: null as File | null,
        logo_secondary: null as File | null,
        seal: null as File | null,
        signature: null as File | null,
        remove_front_background: false,
        remove_back_background: false,
        remove_logo_primary: false,
        remove_logo_secondary: false,
        remove_seal: false,
        remove_signature: false,
        // Blank means "inherit the system setting", so the stored overrides are
        // what the form edits — never the resolved text.
        header_config: {
            city_name_en: template?.header_overrides?.city_name_en ?? '',
            city_name_am: template?.header_overrides?.city_name_am ?? '',
            bureau_name_en: template?.header_overrides?.bureau_name_en ?? '',
            bureau_name_am: template?.header_overrides?.bureau_name_am ?? '',
            show_logo: template?.header_overrides?.show_logo ?? true,
            show_secondary_logo: template?.header_overrides?.show_secondary_logo ?? true,
        },
        back_photo_config: {
            show: template?.back_photo_config?.show ?? false,
            opacity: template?.back_photo_config?.opacity ?? 15,
            contrast: template?.back_photo_config?.contrast ?? 100,
            fit: template?.back_photo_config?.fit ?? ('cover' as 'cover' | 'contain' | 'stretch'),
            background_color: template?.back_photo_config?.background_color ?? '',
        },
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
    const stepKeys = ['stepDetails', 'stepSize', 'stepBackground', 'stepLogos', 'stepDesign', 'stepReview'] as const;
    const [step, setStep] = useState(0);
    const [furthest, setFurthest] = useState(template ? stepKeys.length - 1 : 0);
    useEffect(() => {
        onStateChange({ dirty: form.isDirty, processing: form.processing });
    }, [form.isDirty, form.processing, onStateChange]);
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
    const logoPrimaryUrl = useBackgroundPreview(
        form.data.logo_primary,
        template?.logo_primary_url ?? null,
        form.data.remove_logo_primary,
    );
    const logoSecondaryUrl = useBackgroundPreview(
        form.data.logo_secondary,
        template?.logo_secondary_url ?? null,
        form.data.remove_logo_secondary,
    );
    const sealUrl = useBackgroundPreview(
        form.data.seal,
        template?.seal_url ?? null,
        form.data.remove_seal,
    );
    const signatureUrl = useBackgroundPreview(
        form.data.signature,
        template?.signature_url ?? null,
        form.data.remove_signature,
    );
    const presentation: TemplatePresentation = {
        employee_fields: form.data.employee_fields,
        orientation: form.data.orientation,
        width_mm: form.data.width_mm || 85.6,
        height_mm: form.data.height_mm || 54,
        front_background_url: frontUrl,
        back_background_url: backUrl,
        text_style_config: form.data.text_style_config,
        layout_config: form.data.layout_config,
        // Clearing an override must preview the current global default, not
        // the previously saved override in the template's resolved header.
        header_config: {
            city_name_en: form.data.header_config.city_name_en.trim() || getString('id_cards.city_name_en'),
            city_name_am: form.data.header_config.city_name_am.trim() || getString('id_cards.city_name_am'),
            bureau_name_en: form.data.header_config.bureau_name_en.trim() || getString('id_cards.bureau_name_en'),
            bureau_name_am: form.data.header_config.bureau_name_am.trim() || getString('id_cards.bureau_name_am'),
            show_logo: form.data.header_config.show_logo,
            show_secondary_logo: form.data.header_config.show_secondary_logo,
        },
        logo_primary_url: logoPrimaryUrl,
        logo_secondary_url: logoSecondaryUrl,
        seal_url: sealUrl,
        signature_url: signatureUrl,
        back_photo_config: {
            ...form.data.back_photo_config,
            // The form keeps a blank string so the colour input stays cleared;
            // the card treats "no colour" as null.
            background_color: form.data.back_photo_config.background_color || null,
        },
    };
    // Stand-in employee so every field on the card has something to show while
    // the admin is styling it. Values are translated, never real personal data.
    const sampleCard = {
        cardNumber: 'CARD-0001',
        employeeNumber: 'EMP-0001',
        fullName: enSettings.templateManager.sample_employee,
        fullNameAm: amSettings.templateManager.sample_employee,
        gender: 'male',
        dateOfBirth: enSettings.templateManager.sample_dob,
        dateOfBirthAm: amSettings.templateManager.sample_dob,
        nationality: enSettings.templateManager.sample_nationality,
        nationalityAm: amSettings.templateManager.sample_nationality,
        employmentStatus: enSettings.templateManager.sample_employment_type,
        phoneNumber: '+251 911 000 000',
        emergencyContactName: enSettings.templateManager.sample_emergency_name,
        issueDate: enSettings.templateManager.sample_issue_date,
        expiryDate: enSettings.templateManager.sample_expiry_date,
        // The front prints both calendars, so the preview carries both.
        issueDateAm: amSettings.templateManager.sample_issue_date,
        expiryDateAm: amSettings.templateManager.sample_expiry_date,
        status: 'active',
        // A neutral silhouette, inlined so the preview makes no request and
        // shows no real person. Lets the back-photo controls be judged by eye.
        photoUrl: SAMPLE_PHOTO,
        organizationName: enSettings.templateManager.sample_organization,
        organizationNameAm: amSettings.templateManager.sample_organization,
        positionTitle: 'Employee Position',
        positionTitleAm: 'የሰራተኛ የስራ መደብ',
    };

    const portrait = form.data.orientation === 'portrait';
    const activeStyleGroups = portrait
        ? STYLE_GROUPS
        : STYLE_GROUPS.filter(({ role }) => role !== 'employee_name' && role !== 'employee_position');
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

    /**
     * One PNG upload with its preview, replace and remove controls. Shared by
     * the background artwork and by each header logo, so a logo is managed
     * where it lives rather than in a separate step.
     *
     * Called as a function rather than rendered as <AssetUpload/>: a component
     * declared inside the render would be a new type each pass, remounting the
     * file input mid-interaction.
     */
    const assetUpload = (
        field: 'front_background' | 'back_background' | 'logo_primary' | 'logo_secondary' | 'seal' | 'signature',
        remove: 'remove_front_background' | 'remove_back_background' | 'remove_logo_primary' | 'remove_logo_secondary'
            | 'remove_seal' | 'remove_signature',
        url: string | null,
    ) => (
        <div
            key={field}
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
                            form.setError(field, label('upload_help').replace(':max', String(uploadLimitMb)));
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
                onSuccess: page => {
                    const saved = (page.props.templates as Template[] | undefined)?.find(item => item.code === form.data.code);
                    onSaved(saved?.id ?? template?.id ?? null);
                },
                onError: errors => {
                    const key = Object.keys(errors)[0] ?? '';
                    const errorStep = /^(name|code|description)$/.test(key) ? 0
                        : /^(orientation|width_mm|height_mm)$/.test(key) ? 1
                        : /^(front_background|back_background)/.test(key) ? 2
                        : /^(header_config|logo_|seal|signature)/.test(key) ? 3
                        : /^(layout_config|text_style_config|back_photo_config)/.test(key) ? 4 : 5;
                    goTo(errorStep);
                },
            },
        );
    }

    return (
        <form onSubmit={submit} className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(320px,400px)]">
            {/*
             * One editor panel: step bar, the current step's fields, and the
             * Back / Next footer. These used to be three separate bordered
             * cards stacked on top of each other, with each field group in a
             * fourth card inside the middle one.
             */}
            <div className="min-w-0 overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div className="border-b border-gray-200 px-3 py-2 dark:border-slate-800">
                    <TemplateWizardSteps
                        steps={stepKeys.map((key) => label(key))}
                        current={step}
                        furthest={furthest}
                        onSelect={next => { if (!form.processing) setStep(next); }}
                    />
                </div>

                {Object.keys(form.errors).length > 0 && <div role="alert" className="border-b border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
                    <p className="font-medium">{t('settings.idCardCleanup.saveErrors')}</p>
                    <ul className="mt-2 list-inside list-disc">{Object.entries(form.errors).map(([key, message]) => <li key={key}>{message}</li>)}</ul>
                </div>}
                <fieldset disabled={!editable || form.processing} className="min-w-0">

                {/* Identity — the fields an admin always fills in. */}
                {step === 0 && <TemplateEditorSection title={label(template ? 'edit' : 'create')}>
                {form.data.orientation === 'landscape' && <fieldset className="rounded-lg border p-3"><legend className="px-2 text-sm font-semibold">{label('printedFields')}</legend><p className="mb-3 text-xs text-gray-500">{label('printedFieldsHelp')}</p><div className="grid grid-cols-2 gap-2">{Object.entries({ name: 'fullName', sex: 'gender', dob: 'dateOfBirth', nationality: 'nationality', employment: 'employmentType', phone: 'phone', idNumber: 'employeeNumber', email: 'email', address: 'address', emergency_contact_name: 'emergencyContactName', emergency_contact_phone: 'emergencyContactPhone' }).map(([key, textKey]) => <label key={key} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.employee_fields.includes(key)} onChange={e => form.setData('employee_fields', e.target.checked ? [...form.data.employee_fields, key] : form.data.employee_fields.filter(item => item !== key))} />{t(`employees.${textKey}`)}</label>)}</div></fieldset>}
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
                {([
                    { field: 'front_background', remove: 'remove_front_background', url: frontUrl },
                    { field: 'back_background', remove: 'remove_back_background', url: backUrl },
                ] as const).map(({ field, remove, url }) => assetUpload(field, remove, url))}
                </TemplateEditorSection>}

                {/* Header logos — each slot is its own panel, side by side so the
                    left/right split on screen matches the card itself. */}
                {step === 3 && <>
                {/* Sections are flush inside the editor panel, so the two
                    logo slots are split by a rule rather than a gap. */}
                <div className="grid border-b border-gray-100 md:grid-cols-2 md:divide-x md:divide-gray-100 dark:border-slate-800 md:dark:divide-slate-800 [&>section]:border-b-0">
                {([
                    { slot: 'left', show: 'show_logo', field: 'logo_primary', remove: 'remove_logo_primary', url: logoPrimaryUrl },
                    { slot: 'right', show: 'show_secondary_logo', field: 'logo_secondary', remove: 'remove_logo_secondary', url: logoSecondaryUrl },
                ] as const).map(({ slot, show, field, remove, url }) => (
                    <TemplateEditorSection
                        key={slot}
                        title={label(`headerLogo_${slot}`)}
                        description={portrait && slot === 'left' ? t('settings.idCardCleanup.portraitLogo') : label(`headerLogo_${slot}Help`)}
                    >
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300"
                                checked={form.data.header_config[show]}
                                disabled={!editable || form.processing}
                                onChange={(e) =>
                                    form.setData('header_config', {
                                        ...form.data.header_config,
                                        [show]: e.target.checked,
                                    })
                                }
                            />
                            {label(`headerLogo_${slot}Show`)}
                        </label>
                        {assetUpload(field, remove, url)}
                    </TemplateEditorSection>
                ))}
                </div>

                {/* Back-face marks. Portrait omits the signature entirely. */}
                <div className="grid border-b border-gray-100 md:grid-cols-2 md:divide-x md:divide-gray-100 dark:border-slate-800 md:dark:divide-slate-800 [&>section]:border-b-0">
                {(portrait ? [
                    { mark: 'seal', field: 'seal', remove: 'remove_seal', url: sealUrl },
                ] as const : [
                    { mark: 'seal', field: 'seal', remove: 'remove_seal', url: sealUrl },
                    { mark: 'signature', field: 'signature', remove: 'remove_signature', url: signatureUrl },
                ] as const).map(({ mark, field, remove, url }) => (
                    <TemplateEditorSection
                        key={mark}
                        title={label(`${mark}Section`)}
                        description={label(`${mark}SectionHelp`)}
                    >
                        {assetUpload(field, remove, url)}
                    </TemplateEditorSection>
                ))}
                </div>

                {/* Institution text — shown beside the left logo only. */}
                {portrait ? <p role="note" className="px-4 py-4 text-sm leading-6 text-gray-600 dark:text-slate-400">{t('settings.idCardCleanup.portraitHeader')}</p> : <TemplateEditorSection
                    title={label('headerSection')}
                    description={label('headerSectionHelp')}
                >
                    {([
                        'city_name_am', 'city_name_en', 'bureau_name_am', 'bureau_name_en',
                    ] as const).map((field) => (
                        <label key={field} className="block text-xs font-medium">
                            {label(`header_${field}`)}
                            <input
                                type="text"
                                className="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-slate-700 dark:bg-slate-900"
                                value={form.data.header_config[field]}
                                disabled={!editable || form.processing}
                                // Blank inherits, so the system value is the placeholder.
                                placeholder={getString(`id_cards.${field}`)}
                                onChange={(e) =>
                                    form.setData('header_config', {
                                        ...form.data.header_config,
                                        [field]: e.target.value,
                                    })
                                }
                            />
                            {form.errors[`header_config.${field}` as keyof typeof form.errors] && (
                                <p className="mt-1 text-sm text-red-600" role="alert">
                                    {form.errors[`header_config.${field}` as keyof typeof form.errors]}
                                </p>
                            )}
                        </label>
                    ))}
                    <p className="text-xs text-gray-500">{label('header_inherit_help')}</p>
                </TemplateEditorSection>}
                </>}

                {step === 4 && <>{/* Back photo — the employee photo as a security watermark. */}
                <TemplateEditorSection
                    title={label('backPhotoSection')}
                    description={label('backPhotoSectionHelp')}
                    defaultOpen={false}
                >
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="rounded border-gray-300"
                            checked={form.data.back_photo_config.show}
                            disabled={!editable || form.processing}
                            onChange={(e) =>
                                form.setData('back_photo_config', {
                                    ...form.data.back_photo_config,
                                    show: e.target.checked,
                                })
                            }
                        />
                        {label('back_photo_show')}
                    </label>
                    {([
                        ['opacity', 0, 100] as const,
                        ['contrast', 0, 300] as const,
                    ]).map(([key, min, max]) => (
                        <label key={key} className="block text-xs font-medium">
                            {label(`back_photo_${key}`)} — {form.data.back_photo_config[key]}%
                            <input
                                type="range"
                                className="mt-1 w-full"
                                min={min}
                                max={max}
                                value={form.data.back_photo_config[key]}
                                disabled={!editable || form.processing}
                                onChange={(e) =>
                                    form.setData('back_photo_config', {
                                        ...form.data.back_photo_config,
                                        [key]: Number(e.target.value),
                                    })
                                }
                            />
                        </label>
                    ))}
                    <label className="block text-xs font-medium">
                        {label('back_photo_fit')}
                        <select
                            className={inputClass}
                            value={form.data.back_photo_config.fit}
                            disabled={!editable || form.processing}
                            onChange={(e) =>
                                form.setData('back_photo_config', {
                                    ...form.data.back_photo_config,
                                    fit: e.target.value as 'cover' | 'contain' | 'stretch',
                                })
                            }
                        >
                            {(['cover', 'contain', 'stretch'] as const).map((fit) => (
                                <option key={fit} value={fit}>{label(`back_photo_fit_${fit}`)}</option>
                            ))}
                        </select>
                    </label>
                    <div className="flex items-end gap-2">
                        <label className="flex-1 text-xs font-medium">
                            {label('back_photo_background')}
                            <input
                                type="color"
                                className="mt-1 h-9 w-full rounded border border-gray-300 dark:border-slate-700"
                                value={form.data.back_photo_config.background_color || '#FFFFFF'}
                                disabled={!editable || form.processing}
                                onChange={(e) =>
                                    form.setData('back_photo_config', {
                                        ...form.data.back_photo_config,
                                        background_color: e.target.value,
                                    })
                                }
                            />
                        </label>
                        {form.data.back_photo_config.background_color && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    form.setData('back_photo_config', {
                                        ...form.data.back_photo_config,
                                        background_color: '',
                                    })
                                }
                            >
                                {label('back_photo_background_clear')}
                            </Button>
                        )}
                    </div>
                </TemplateEditorSection>

                {/* Text Style Management — every text role on both card sides. */}
                <TemplateEditorSection
                    title={label('text_style_management')}
                    description={label('text_styling_help')}
                    defaultOpen={false}
                    badge={<span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-slate-800 dark:text-slate-300">{activeStyleGroups.length}</span>}
                >
                    {activeStyleGroups.map(({ side, role }) => {
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

                {step === 5 && <TemplateEditorSection title={label('publishingSection')} description={label('publishingSectionHelp')}>
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

                {form.progress && (
                    <progress className="block h-1 w-full" value={form.progress.percentage} max="100" />
                )}
                {editable && (
                    /*
                     * Footer of the same panel, pinned to the viewport bottom so
                     * Back / Next stay reachable on the long steps. Status on the
                     * left, actions on the right — the primary action ends the row.
                     */
                    <div className="sticky bottom-0 flex flex-wrap items-center gap-3 border-t border-gray-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
                        <span className="text-xs tabular-nums text-gray-500 dark:text-slate-400">
                            {label('stepCounter')
                                .replace(':current', String(step + 1))
                                .replace(':total', String(stepKeys.length))}
                        </span>
                        {form.isDirty && !form.processing && (
                            <span className="text-xs text-amber-700 dark:text-amber-400">{label('unsavedChanges')}</span>
                        )}
                        <div className="ms-auto flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={step === 0 || form.processing}
                                onClick={() => setStep((current) => Math.max(0, current - 1))}
                            >
                                {label('back')}
                            </Button>
                            {step < stepKeys.length - 1 ? (
                                <Button key="next-step" type="button" disabled={form.processing} onClick={event => { event.preventDefault(); goTo(step + 1); }}>
                                    {label('next')}
                                </Button>
                            ) : (
                                <Button key="save-template" type="submit" disabled={form.processing}>
                                    {form.processing ? label('saving') : label('save')}
                                </Button>
                            )}
                        </div>
                    </div>
                )}
                </fieldset>
            </div>
            {/* Preview follows the admin down the form. */}
            <div className="self-start rounded-panel border border-gray-200 bg-white xl:sticky xl:top-4 dark:border-slate-800 dark:bg-slate-900">
                <div className="border-b border-gray-200 px-4 py-2.5 dark:border-slate-800">
                    <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{label('live_preview')}</h2>
                    <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{label('preview_help')}</p>
                </div>
                <div className="bg-gray-50 p-4 dark:bg-slate-950/40">
                <IdCardTemplateContext.Provider value={presentation}>
                    <div className="space-y-4">
                        {/* The designer overlays the real preview, so the box an
                            admin drags is the region that prints. */}
                        <div style={{ width: '100%', maxWidth: portrait ? 260 : 400 }}>
                            <TemplateLayoutDesigner
                                side="front"
                                elements={portrait ? PORTRAIT_FRONT_LAYOUT_ELEMENTS : LANDSCAPE_FRONT_LAYOUT_ELEMENTS}
                                value={form.data.layout_config.front}
                                onChange={(front) =>
                                    form.setData('layout_config', { ...form.data.layout_config, front })
                                }
                                disabled={!editable || form.processing}
                                labels={
                                    Object.fromEntries(
                                        (portrait ? PORTRAIT_FRONT_LAYOUT_ELEMENTS : LANDSCAPE_FRONT_LAYOUT_ELEMENTS)
                                            .map((element) => [element, label(`layout_${element}`)]),
                                    ) as Record<LayoutElement, string>
                                }
                                text={{
                                    reset: label('layout_reset'),
                                    resetAll: label('layout_reset_all'),
                                    hint: label('layout_hint'),
                                }}
                            >
                                <Front {...sampleCard} />
                            </TemplateLayoutDesigner>
                        </div>
                        <div style={{ width: '100%', maxWidth: portrait ? 260 : 400 }}>
                            <TemplateLayoutDesigner
                                side="back"
                                elements={portrait ? PORTRAIT_BACK_LAYOUT_ELEMENTS : BACK_LAYOUT_ELEMENTS}
                                value={form.data.layout_config.back}
                                onChange={(back) =>
                                    form.setData('layout_config', { ...form.data.layout_config, back })
                                }
                                disabled={!editable || form.processing}
                                labels={Object.fromEntries(
                                    (portrait ? PORTRAIT_BACK_LAYOUT_ELEMENTS : BACK_LAYOUT_ELEMENTS)
                                        .map((element) => [element, label(`layout_${element}`)]),
                                )}
                                text={{
                                    reset: label('layout_reset'),
                                    resetAll: label('layout_reset_all'),
                                    /* Already stated under the front card. */
                                    hint: '',
                                }}
                            >
                                <Back
                                    cardNumber={sampleCard.cardNumber}
                                    qrValue="https://example.invalid/id-card-preview"
                                    emergencyContactName={sampleCard.emergencyContactName}
                                    emergencyContactPhone={sampleCard.phoneNumber}
                                    photoUrl={sampleCard.photoUrl}
                                />
                            </TemplateLayoutDesigner>
                        </div>
                    </div>
                </IdCardTemplateContext.Provider>
                </div>
            </div>
        </form>
    );
}

export default function IdCardTemplates({ templates, can, uploadLimitMb }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const label = (key: string) => t(`settings.templateManager.${key}`);
    const [selected, setSelected] = useState<string | null>(templates[0]?.id ?? null);
    const [revision, setRevision] = useState(0);
    const [editorState, setEditorState] = useState({ dirty: false, processing: false });
    const template = templates.find((item) => item.id === selected) ?? null;
    async function mayLeaveEditor() {
        if (editorState.processing) return false;
        return !editorState.dirty || (await confirm({ title: t('settings.idCardCleanup.discard'), variant: 'warning' })).confirmed;
    }
    async function selectTemplate(id: string | null) {
        if (id === selected && id !== null) return;
        if (!await mayLeaveEditor()) return;
        setSelected(id);
        setRevision(value => value + 1);
    }
    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    /*
                     * `backHref` replaces the "Settings → ID Cards" link that
                     * used to sit in the page body. The breadcrumb bar above
                     * already states the same trail, so the link was a third
                     * copy of the same navigation.
                     */
                    backHref={route('system-settings.index', { tab: 'id_cards' })}
                    title={label('title')}
                    description={label('help')}
                    actions={
                        <div className="flex items-center gap-2">
                            {can.create && (
                                <Button
                                    type="button"
                                    disabled={editorState.processing}
                                    onClick={() => selectTemplate(null)}
                                >
                                    {label('create')}
                                </Button>
                            )}
                            {/*
                             * Per-template actions. `set_default` and `delete`
                             * used to render as full buttons above the editor —
                             * which made "Delete template" the largest, reddest
                             * thing on a page whose purpose is editing. They are
                             * occasional actions, so they live behind the menu.
                             */}
                            {template && (
                                <AppActionMenu
                                    label={template.name}
                                    items={[
                                        {
                                            label: label('set_default'),
                                            show:
                                                can.set_default &&
                                                !template.is_default &&
                                                template.status === 'active',
                                            onClick: async () => {
                                                if (!await mayLeaveEditor()) return;
                                                router.post(
                                                    route('id-card-templates.set-default', template.id),
                                                    {},
                                                    { onSuccess: () => setRevision((value) => value + 1) },
                                                );
                                            },
                                        },
                                        {
                                            label: label('delete'),
                                            variant: 'danger',
                                            show: can.delete && (!template.is_default || can.set_default),
                                            onClick: async () => {
                                                if (editorState.processing) return;
                                                const result = await confirm({ title: label('confirm_delete'), variant: 'danger' });
                                                if (result.confirmed) {
                                                    router.delete(route('id-card-templates.destroy', template.id), {
                                                        onSuccess: () => setSelected(null),
                                                    });
                                                }
                                            },
                                        },
                                    ]}
                                />
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={label('title')} />

            <div className="space-y-4">
                {/*
                 * Template selector — a row above the editor, not a column
                 * beside it. Most institutions keep one to three templates, so
                 * a 240px sidebar was nearly empty while squeezing the editor
                 * and preview into what was left; the six-step bar no longer
                 * fit and cut off its last step.
                 */}
                {templates.length > 0 && (
                    <nav aria-label={label('title')} className="flex flex-wrap gap-2">
                        {templates.map((item) => {
                            const active = selected === item.id;

                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    aria-current={active ? 'true' : undefined}
                                    disabled={editorState.processing}
                                    onClick={() => selectTemplate(item.id)}
                                    className={[
                                        'flex min-w-0 max-w-xs items-center gap-2 rounded-card border px-3 py-2 text-left transition-colors',
                                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]',
                                        active
                                            ? 'border-[color:var(--color-primary)] bg-white ring-1 ring-[color:var(--color-primary)] dark:bg-slate-900'
                                            : 'border-gray-200 bg-white hover:border-gray-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-slate-700',
                                    ].join(' ')}
                                >
                                    <span className="truncate text-sm font-medium text-gray-900 dark:text-slate-100">
                                        {item.name}
                                    </span>
                                    {item.is_default && <StatusBadge status="active" label={label('default')} />}
                                    {/* Only worth a badge when it is the exception. */}
                                    {item.status !== 'active' && (
                                        <StatusBadge status={item.status} label={label(item.status)} />
                                    )}
                                </button>
                            );
                        })}
                    </nav>
                )}

                <div className="min-w-0">
                    {template || can.create ? (
                        <TemplateForm
                            key={`${selected}-${revision}-${template?.front_background_url}-${template?.back_background_url}`}
                            template={template}
                            can={can}
                            uploadLimitMb={uploadLimitMb}
                            onStateChange={setEditorState}
                            onSaved={id => { setSelected(id); setRevision(value => value + 1); }}
                        />
                    ) : (
                        <p className="text-sm text-gray-500 dark:text-slate-400">{label('empty')}</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
