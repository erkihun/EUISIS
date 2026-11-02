import { useEffect, useState, type FormEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
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
    CARD_SURFACE,
    FRONT_ROLES,
    BACK_ROLES,
    BACK_FIELD_KEYS,
    BACK_LAYOUT_DEFAULTS,
    BACK_LAYOUT_ELEMENTS,
    FRONT_FIELD_KEYS,
    LANDSCAPE_BACK_LAYOUT_ELEMENTS,
    LANDSCAPE_FRONT_LAYOUT_ELEMENTS,
    LAYOUT_DEFAULTS,
    LAYOUT_ELEMENTS,
    PORTRAIT_BACK_LAYOUT_ELEMENTS,
    PORTRAIT_FIELD_DEFAULTS,
    PORTRAIT_FRONT_LAYOUT_ELEMENTS,
    fieldFace,
    layoutDefaults,
    printedFieldElements,
    type LayoutSide,
    type LayoutBox,
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

import { buttonClassName, cx, inputClassName } from '@/Components/ui';
import {
    CARD_SIZE_PRESETS,
    SAFE_MARGIN_MM,
    matchCardSizePreset,
    presetSize,
    readinessChecks,
    safeInset,
    type CardSizePresetKey,
} from '@/lib/idCardReadiness';

/** Front-face rows an admin can print, keyed as the server stores them, with their employee label key. */
const PRINTED_FIELDS = {
    name: 'fullName',
    sex: 'gender',
    dob: 'dateOfBirth',
    nationality: 'nationality',
    employment: 'employmentType',
    phone: 'phone',
    idNumber: 'employeeNumber',
    email: 'email',
    address: 'address',
    emergency_contact_name: 'emergencyContactName',
    emergency_contact_phone: 'emergencyContactPhone',
} as const;
type PrintedField = keyof typeof PRINTED_FIELDS;

type Orientation = 'landscape' | 'portrait';

/** The element a printed field is on the card: a portrait name and ID number are its large name and ID elements. */
const fieldElement = (key: string, orientation: Orientation) =>
    printedFieldElements([key], (FRONT_FIELD_KEYS as readonly string[]).includes(key) ? 'front' : 'back', orientation)[0]?.element ?? `field_${key}`;

/** Every element on one face: the fixed ones, then one per field printed there. */
function faceElements(side: 'front' | 'back', fields: readonly string[], orientation: Orientation, faces?: Record<string, LayoutSide> | null): string[] {
    const fixed: readonly string[] = orientation === 'portrait'
        ? (side === 'front'
            ? PORTRAIT_FRONT_LAYOUT_ELEMENTS.filter((element) => element !== 'employee_name' && element !== 'emphasis')
            : PORTRAIT_BACK_LAYOUT_ELEMENTS)
        : (side === 'front' ? LANDSCAPE_FRONT_LAYOUT_ELEMENTS : LANDSCAPE_BACK_LAYOUT_ELEMENTS);
    return [...fixed, ...printedFieldElements(fields, side, orientation, faces).map(({ element }) => element)];
}

/** Field boxes moved to an orientation's defaults, when a template switches orientation. */
function fieldBoxesFor(orientation: Orientation, side: 'front' | 'back'): Record<string, LayoutBox> {
    const source = orientation === 'portrait' ? PORTRAIT_FIELD_DEFAULTS[side] : layoutDefaults(side);
    return Object.fromEntries(Object.entries(source).filter(([element]) => element.startsWith('field_')));
}

const overlaps = (a: LayoutBox, b: LayoutBox) =>
    a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

/**
 * `box` where it is, if nothing else is there; otherwise the first spot of the
 * same size, top to bottom then left to right below the header, that touches
 * nothing. With no room anywhere the box stays put for the admin to move.
 */
function freeSpot(box: LayoutBox, others: LayoutBox[]): LayoutBox {
    if (!others.some((other) => overlaps(box, other))) return box;
    for (let y = 18; y + box.h <= 99; y += 1) {
        for (let x = 2; x + box.w <= 99; x += 1) {
            const candidate = { ...box, x, y };
            if (!others.some((other) => overlaps(candidate, other))) return candidate;
        }
    }
    return box;
}

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

/**
 * Mirrors IdCardTextStyle::ROLES so a new template previews as it will save:
 * dark ink on the neutral card surface. The old white-on-blue values dated
 * from the gradient card and printed white text on a white card.
 */
const ROLE_DEFAULTS: Record<'front' | 'back', Record<string, TextStyle>> = {
    front: {
        header: { color: CARD_SURFACE.ink, font_size: '9px', font_weight: '700' },
        label: { color: CARD_SURFACE.inkMuted, font_size: '7px', font_weight: '400' },
        value: { color: CARD_SURFACE.ink, font_size: '10px', font_weight: '600' },
        employee_name: { color: CARD_SURFACE.ink, font_size: '12px', font_weight: '700' },
        employee_position: { color: CARD_SURFACE.inkMuted, font_size: '9px', font_weight: '600' },
    },
    back: {
        label: { color: CARD_SURFACE.inkMuted, font_size: '7px', font_weight: '400' },
        value: { color: CARD_SURFACE.inkMuted, font_size: '8px', font_weight: '600' },
        footer: { color: CARD_SURFACE.inkMuted, font_size: '7px', font_weight: '400' },
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
    const { getString, getBoolean } = useSystemSettings();
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
            // Fields moved off their home face, as key => face.
            faces: { ...(template?.layout_config?.faces ?? {}) } as Record<string, LayoutSide>,
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
    const printedFields = form.data.employee_fields.filter((key): key is PrintedField => key in PRINTED_FIELDS);
    const [showGuides, setShowGuides] = useState(true);
    const [largePreview, setLargePreview] = useState(false);
    // One selection across both faces, so picking a field in the list
    // highlights its box on the card and the reverse.
    const [selectedBox, setSelectedBox] = useState<{ side: 'front' | 'back'; element: string } | null>(null);
    const fieldLabel = (key: PrintedField) => t(`employees.${PRINTED_FIELDS[key]}`);
    const toggleField = (key: PrintedField, on: boolean) => {
        form.setData((data) => {
            const fields = on ? [...printedFields, key] : printedFields.filter((item) => item !== key);
            if (!on) return { ...data, employee_fields: fields };
            // A field ticked on lands in the first free spot on its face, so
            // it never appears on top of a field the template already placed.
            // (A portrait name or ID number is a fixed element: it stays put.)
            const side = fieldFace(key, data.layout_config.faces, data.orientation);
            const element = fieldElement(key, data.orientation);
            if (!element.startsWith('field_')) return { ...data, employee_fields: fields };
            const boxes = data.layout_config[side];
            const others = faceElements(side, fields.filter((field) => field !== key), data.orientation, data.layout_config.faces)
                .map((name) => boxes[name]).filter((box): box is LayoutBox => Boolean(box));
            const free = freeSpot(boxes[element] ?? layoutDefaults(side, data.orientation)[element], others);
            return {
                ...data,
                employee_fields: fields,
                layout_config: { ...data.layout_config, [side]: { ...boxes, [element]: free } },
            };
        });
        if (!on && selectedBox?.element === fieldElement(key, form.data.orientation)) setSelectedBox(null);
    };
    /**
     * Moves a field to the other face. Dropped on a card, it lands where it
     * was dropped; moved from the list, it takes the first free spot there.
     * A portrait name and ID number are badge elements and stay on the front.
     */
    const moveField = (key: PrintedField, to: LayoutSide, point?: { x: number; y: number }) => {
        const element = fieldElement(key, form.data.orientation);
        if (!element.startsWith('field_')) return;
        form.setData((data) => {
            const boxes = data.layout_config[to];
            const size = boxes[element] ?? layoutDefaults(to, data.orientation)[element] ?? { x: 0, y: 0, w: 30, h: 10 };
            const placed = point
                ? {
                    ...size,
                    x: Math.round(Math.min(Math.max(point.x - size.w / 2, 0), 100 - size.w) * 2) / 2,
                    y: Math.round(Math.min(Math.max(point.y - size.h / 2, 0), 100 - size.h) * 2) / 2,
                }
                : freeSpot(size, faceElements(to, printedFields.filter((field) => field !== key), data.orientation, data.layout_config.faces)
                    .map((name) => boxes[name]).filter((box): box is LayoutBox => Boolean(box)));
            const home = fieldFace(key, null, data.orientation);
            const faces = { ...data.layout_config.faces };
            if (to === home) delete faces[key]; else faces[key] = to;
            return { ...data, layout_config: { ...data.layout_config, faces, [to]: { ...boxes, [element]: placed } } };
        });
        setSelectedBox({ side: to, element });
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
        // A code, not a label: the card translates it into both languages.
        employmentStatus: 'permanent',
        phoneNumber: '+251 911 000 000',
        // Long on purpose, so the shrink-to-fit rule for emails shows while designing.
        email: 'sample.employee@addisababa.gov.et',
        address: enSettings.templateManager.sample_address,
        emergencyContactName: enSettings.templateManager.sample_emergency_name,
        status: 'active',
        // A neutral silhouette, inlined so the preview makes no request and
        // shows no real person. Lets the back-photo controls be judged by eye.
        photoUrl: SAMPLE_PHOTO,
        organizationName: enSettings.templateManager.sample_organization,
        organizationNameAm: amSettings.templateManager.sample_organization,
        positionTitle: 'Employee Position',
        positionTitleAm: 'የሠራተኛ የሥራ መደብ',
    };

    const portrait = form.data.orientation === 'portrait';
    const activeStyleGroups = portrait
        ? STYLE_GROUPS
        : STYLE_GROUPS.filter(({ role }) => role !== 'employee_name' && role !== 'employee_position');
    const guideInset = showGuides ? safeInset(presentation.width_mm, presentation.height_mm) : null;
    const checks = readinessChecks({
        orientation: form.data.orientation,
        widthMm: presentation.width_mm,
        heightMm: presentation.height_mm,
        printedFields: printedFields.filter((key) => (FRONT_FIELD_KEYS as readonly string[]).includes(key)),
        hasFrontArtwork: Boolean(frontUrl),
        hasBackArtwork: Boolean(backUrl),
        hasSeal: Boolean(sealUrl),
        hasSignature: Boolean(signatureUrl),
        printsSignature: getBoolean('id_cards.show_signature', false),
        status: form.data.status,
        isDefault: form.data.is_default,
        headerLines: [presentation.header_config?.city_name_am ?? '', presentation.header_config?.city_name_en ?? ''],
        textColors: Object.fromEntries(activeStyleGroups.map(({ side, role }) => [
            `${side}.${role}`,
            (form.data.text_style_config[side][role as keyof object] as TextStyle).color,
        ])),
        surface: CARD_SURFACE.from,
    });
    const blocking = checks.filter((check) => check.level === 'error').length;
    const warnings = checks.filter((check) => check.level === 'warning').length;
    const checkMessage = (check: (typeof checks)[number]) =>
        Object.entries(check.params ?? {}).reduce(
            (text, [name, value]) => text.replace(
                `:${name}`,
                // A role reads as its style group's name, e.g. "Front values".
                name === 'role' ? label(`${value.replace('.', '_')}_text`) : value,
            ),
            label(`readiness_${check.key}`),
        );
    const Front = portrait ? IdCardPortraitFront : IdCardFront;
    const Back = portrait ? IdCardPortraitBack : IdCardBack;
    // Each face lists its fixed elements plus one per printed field, in both
    // orientations, so the designer shows exactly what the list ticks.
    const frontElements = faceElements('front', printedFields, form.data.orientation, form.data.layout_config.faces);
    const backElements = faceElements('back', printedFields, form.data.orientation, form.data.layout_config.faces);
    // Named as in the printed-fields list, including a portrait card's large
    // name and ID number elements.
    const elementLabel = (element: string) => {
        const field = (Object.keys(PRINTED_FIELDS) as PrintedField[]).find((key) => fieldElement(key, form.data.orientation) === element);
        return field ? fieldLabel(field) : label(`layout_${element}`);
    };
    // A larger card makes small fields easier to grab and place.
    const cardWidth = portrait ? (largePreview ? 400 : 260) : (largePreview ? 640 : 400);
    const cardRootStyle = { maxWidth: cardWidth };
    const inputClass =
        'mt-1 w-full rounded-lg border-(--app-border-strong) bg-(--app-surface) text-sm';
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
            className="space-y-2 rounded-lg border border-dashed border-(--app-border-strong) p-3"
        >
            <p className="text-sm font-semibold">{label(field)}</p>
            {url && (
                <img
                    src={url}
                    alt={label('background_preview')}
                    className="h-24 w-full rounded-sm object-contain"
                />
            )}
            <label className="inline-flex cursor-pointer items-center rounded-lg border border-(--app-border-strong) px-3 py-2 text-sm hover:bg-(--app-surface-muted)">
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
        <form
            onSubmit={submit}
            className={cx(
                'grid items-start gap-5',
                largePreview ? 'xl:grid-cols-[minmax(0,1fr)_minmax(320px,680px)]' : 'xl:grid-cols-[minmax(0,1fr)_minmax(320px,440px)]',
            )}
        >
            {/*
             * One editor panel: step bar, the current step's fields, and the
             * Back / Next footer. These used to be three separate bordered
             * cards stacked on top of each other, with each field group in a
             * fourth card inside the middle one.
             */}
            <div className="min-w-0 overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface)">
                <div className="border-b border-(--app-border) px-3 py-2">
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
                <fieldset className="rounded-lg border border-(--app-border) p-3">
                        <legend className="px-2 text-sm font-semibold">{label('printedFields')}</legend>
                        <p className="mb-3 text-xs text-(--app-muted-foreground)">
                            {label('printedFieldsHelp')} {label('printedFieldsPlaceHelp')}
                        </p>
                        {/* Grouped by the face each field prints on, so the list
                            reads the same as the two cards in the preview. */}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(['front', 'back'] as const).map((side) => (
                                <div key={side}>
                                    <p className="mb-1 px-2 text-xs font-semibold text-(--app-muted-foreground)">{label(`printedFields_${side}`)}</p>
                                    <ul className="space-y-0.5">
                                        {/* Each field is listed under the face it prints on now. */}
                                        {[...FRONT_FIELD_KEYS, ...BACK_FIELD_KEYS]
                                            .filter((key) => fieldFace(key, form.data.layout_config.faces, form.data.orientation) === side)
                                            .map((key) => {
                                            const on = printedFields.includes(key);
                                            const element = fieldElement(key, form.data.orientation);
                                            const picked = selectedBox?.element === element;
                                            const other: LayoutSide = side === 'front' ? 'back' : 'front';
                                            // A portrait name and ID number are badge elements on the front.
                                            const movable = element.startsWith('field_');
                                            return (
                                                <li
                                                    key={key}
                                                    className={cx(
                                                        'flex items-center gap-2 rounded-md px-2 py-1 text-sm',
                                                        picked ? 'bg-blue-500/10 ring-1 ring-primary' : 'hover:bg-(--app-surface-muted)',
                                                    )}
                                                >
                                                    <label className="flex min-w-0 flex-1 items-center gap-2">
                                                        <input
                                                            type="checkbox"
                                                            checked={on}
                                                            onChange={(e) => toggleField(key, e.target.checked)}
                                                        />
                                                        <span className="truncate">{fieldLabel(key)}</span>
                                                    </label>
                                                    {on && (
                                                        <button
                                                            type="button"
                                                            aria-pressed={picked}
                                                            aria-label={`${fieldLabel(key)} — ${label('fieldLocate')}`}
                                                            onClick={() => setSelectedBox(picked ? null : { side, element })}
                                                            className={buttonClassName({ variant: picked ? 'primary' : 'ghost', size: 'xs' })}
                                                        >
                                                            {label(picked ? 'fieldSelected' : 'fieldLocate')}
                                                        </button>
                                                    )}
                                                    {on && movable && (
                                                        <button
                                                            type="button"
                                                            aria-label={`${fieldLabel(key)} — ${label(`moveTo_${other}`)}`}
                                                            title={label(`moveTo_${other}`)}
                                                            onClick={() => moveField(key, other)}
                                                            className={buttonClassName({ variant: 'ghost', size: 'xs' })}
                                                        >
                                                            {label(`moveTo_${other}`)}
                                                        </button>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            ))}
                        </div>
                        {error('employee_fields')}
                    </fieldset>
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
                                // Field boxes are placed for one card shape; the
                                // other shape starts from its own arrangement.
                                layout_config: {
                                    ...data.layout_config,
                                    front: { ...data.layout_config.front, ...fieldBoxesFor(orientation, 'front') },
                                    back: { ...data.layout_config.back, ...fieldBoxesFor(orientation, 'back') },
                                },
                            }));
                        }}
                    >
                        <option value="landscape">{label('landscape')}</option>
                        <option value="portrait">{label('portrait')}</option>
                    </select>
                    {error('orientation')}
                </label>
                <label className="block text-sm font-medium">
                    {label('sizePreset')}
                    <select
                        className={inputClass}
                        value={matchCardSizePreset(form.data.width_mm, form.data.height_mm) ?? 'custom'}
                        onChange={(e) => {
                            if (e.target.value === 'custom') return;
                            const size = presetSize(e.target.value as CardSizePresetKey, form.data.orientation);
                            form.setData((data) => ({ ...data, width_mm: size.width, height_mm: size.height }));
                        }}
                    >
                        {CARD_SIZE_PRESETS.map((preset) => (
                            <option key={preset.key} value={preset.key}>
                                {label(`sizePreset_${preset.key}`)} — {preset.width} × {preset.height} mm
                            </option>
                        ))}
                        <option value="custom">{label('sizePreset_custom')}</option>
                    </select>
                    <span className="mt-1 block text-xs font-normal text-(--app-muted-foreground)">{label('sizePresetHelp')}</span>
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
                <p className="text-xs text-(--app-muted-foreground)">{label('upload_help').replace(':max', String(uploadLimitMb))}</p>
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
                <div className="grid border-b border-(--app-border) md:grid-cols-2 md:divide-x md:divide-(--app-border) md: [&>section]:border-b-0">
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
                                className="rounded-sm border-(--app-border-strong)"
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
                <div className="grid border-b border-(--app-border) md:grid-cols-2 md:divide-x md:divide-(--app-border) md: [&>section]:border-b-0">
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
                {portrait ? <p role="note" className="px-4 py-4 text-sm leading-6 text-(--app-muted-foreground)">{t('settings.idCardCleanup.portraitHeader')}</p> : <TemplateEditorSection
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
                                className={cx(inputClassName, 'mt-1 w-full')}
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
                    <p className="text-xs text-(--app-muted-foreground)">{label('header_inherit_help')}</p>
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
                            className="rounded-sm border-(--app-border-strong)"
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
                                className="mt-1 h-9 w-full rounded-sm border border-(--app-border-strong)"
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
                    badge={<span className="rounded-full bg-(--app-surface-muted) px-2 py-0.5 text-xs text-(--app-muted-foreground)">{activeStyleGroups.length}</span>}
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
                            <div key={groupKey} className="space-y-2 rounded-md bg-(--app-surface-muted) p-3">
                                <p className="text-sm font-semibold">{label(groupKey)}</p>
                                <div className="grid gap-2 sm:grid-cols-3">
                                    <label className="text-xs font-medium">
                                        {label('text_color')}
                                        <input
                                            type="color"
                                            className="mt-1 h-9 w-full rounded-sm border border-(--app-border-strong)"
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
                <section aria-labelledby="template-readiness" className="rounded-lg border border-(--app-border) p-3">
                    <h3 id="template-readiness" className="text-sm font-semibold text-(--app-foreground)">{label('readinessTitle')}</h3>
                    <p className="mt-0.5 text-xs text-(--app-muted-foreground)">
                        {blocking > 0
                            ? label('readinessBlocking').replace(':count', String(blocking))
                            : warnings > 0
                                ? label('readinessWarnings').replace(':count', String(warnings))
                                : label('readinessReady')}
                    </p>
                    <ul className="mt-2 space-y-1.5">
                        {checks.map((check, index) => (
                            <li key={`${check.key}-${index}`} data-readiness={check.level} className="flex gap-2 text-sm">
                                <span
                                    aria-hidden
                                    className={cx(
                                        'mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white',
                                        check.level === 'ok' && 'bg-emerald-600',
                                        check.level === 'warning' && 'bg-amber-500',
                                        check.level === 'error' && 'bg-red-600',
                                    )}
                                >
                                    {check.level === 'ok' ? '✓' : check.level === 'warning' ? '!' : '×'}
                                </span>
                                <span>
                                    <span className="sr-only">{label(`readinessLevel_${check.level}`)}: </span>
                                    {checkMessage(check)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
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
                    <div className="sticky bottom-0 flex flex-wrap items-center gap-3 border-t border-(--app-border) bg-(--app-surface) px-4 py-3">
                        <span className="text-xs tabular-nums text-(--app-muted-foreground)">
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
            <div className="self-start rounded-panel border border-(--app-border) bg-(--app-surface) xl:sticky xl:top-4">
                <div className="border-b border-(--app-border) px-4 py-2.5">
                    <h2 className="text-sm font-semibold text-(--app-foreground)">{label('live_preview')}</h2>
                    <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{label('preview_help')}</p>
                    <label className="mt-2 flex items-center gap-2 text-xs text-(--app-foreground)">
                        <input
                            type="checkbox"
                            className="rounded-sm border-(--app-border-strong)"
                            checked={showGuides}
                            onChange={(e) => setShowGuides(e.target.checked)}
                        />
                        <span>
                            {label('safeZoneToggle')}
                            <span className="text-(--app-muted-foreground)"> — {label('safeZoneHelp').replace(':mm', String(SAFE_MARGIN_MM))}</span>
                        </span>
                    </label>
                    <label className="mt-1.5 flex items-center gap-2 text-xs text-(--app-foreground)">
                        <input
                            type="checkbox"
                            className="rounded-sm border-(--app-border-strong)"
                            checked={largePreview}
                            onChange={(e) => setLargePreview(e.target.checked)}
                        />
                        {label('largePreview')}
                    </label>
                </div>
                <div className="overflow-x-auto bg-(--app-surface-muted) p-4">
                <IdCardTemplateContext.Provider value={presentation}>
                    <div className="space-y-6">
                        {/* The designer overlays the real preview, so the box an
                            admin drags is the region that prints. Every printed
                            field is its own box, named as in the fields list. */}
                        {([
                            { side: 'front' as const, elements: frontElements, card: <Front {...sampleCard} rootStyle={cardRootStyle} /> },
                            {
                                side: 'back' as const,
                                elements: backElements,
                                card: (
                                    <Back
                                        cardNumber={sampleCard.cardNumber}
                                        qrValue="https://example.invalid/id-card-preview"
                                        emergencyContactName={sampleCard.emergencyContactName}
                                        emergencyContactPhone={sampleCard.phoneNumber}
                                        photoUrl={sampleCard.photoUrl}
                                        rootStyle={cardRootStyle}
                                    />
                                ),
                            },
                        ]).map(({ side, elements, card }) => (
                            <div key={side} style={{ width: '100%', maxWidth: cardWidth }}>
                                <p className="mb-1.5 text-xs font-semibold text-(--app-muted-foreground)">{label(`printedFields_${side}`)}</p>
                                <TemplateLayoutDesigner
                                    side={side}
                                    orientation={form.data.orientation}
                                    guideInset={guideInset}
                                    elements={elements}
                                    value={form.data.layout_config[side]}
                                    onChange={(boxes) => form.setData('layout_config', { ...form.data.layout_config, [side]: boxes })}
                                    disabled={!editable || form.processing}
                                    labels={Object.fromEntries(elements.map((element) => [element, elementLabel(element)]))}
                                    selected={selectedBox?.side === side ? selectedBox.element : null}
                                    onSelect={(element) => setSelectedBox(element ? { side, element } : null)}
                                    // Drag a field onto the other card to print it on that face.
                                    canTransfer={(element) => element.startsWith('field_') && element.slice(6) in PRINTED_FIELDS}
                                    onTransfer={(element, point) => moveField(element.slice(6) as PrintedField, side === 'front' ? 'back' : 'front', point)}
                                    text={{
                                        reset: label('layout_reset'),
                                        resetAll: label('layout_reset_all'),
                                        // Stated once, under the front card.
                                        hint: side === 'front' ? label('layout_hint') : '',
                                        elements: label('layoutElements'),
                                        x: label('layoutX'),
                                        y: label('layoutY'),
                                        w: label('layoutW'),
                                        h: label('layoutH'),
                                        overflow: label('layoutOverflow'),
                                    }}
                                >
                                    {card}
                                </TemplateLayoutDesigner>
                            </div>
                        ))}
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
                                            label: label('duplicate'),
                                            show: can.create,
                                            onClick: async () => {
                                                if (!await mayLeaveEditor()) return;
                                                const known = new Set(templates.map((item) => item.id));
                                                router.post(route('id-card-templates.duplicate', template.id), {}, {
                                                    preserveScroll: true,
                                                    onSuccess: (page) => {
                                                        // Open the copy so the admin edits the variant, not the original.
                                                        const copy = (page.props.templates as Template[] | undefined)
                                                            ?.find((item) => !known.has(item.id));
                                                        setSelected(copy?.id ?? template.id);
                                                        setRevision((value) => value + 1);
                                                    },
                                                });
                                            },
                                        },
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
                                        'focus:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
                                        active
                                            ? 'border-primary bg-(--app-surface) ring-1 ring-primary'
                                            : 'border-(--app-border) bg-(--app-surface) hover:border-(--app-border-strong)',
                                    ].join(' ')}
                                >
                                    <span className="truncate text-sm font-medium text-(--app-foreground)">
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
                        <p className="text-sm text-(--app-muted-foreground)">{label('empty')}</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
