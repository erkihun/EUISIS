import { createContext, useContext, type CSSProperties } from 'react';
import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

/** Per-template typography for one text role on one card side. */
export type TextStyle = {
    color: string;
    font_size: string;
    font_weight: string;
};

/**
 * Card colour belongs to the template. A card without background artwork gets
 * this neutral surface so the template's own text colours stay readable, rather
 * than inheriting legacy per-install gradient settings.
 */
export const CARD_SURFACE = {
    from: '#FFFFFF',
    to: '#F1F5F9',
    ink: '#0F172A',
    inkMuted: '#475569',
} as const;

/** Text roles that can be styled per card side. */
// The front has no footer of its own; the dates close the card face.
export const FRONT_ROLES = ['header', 'label', 'value', 'employee_name', 'employee_position'] as const;
// The back has no heading of its own; its notes lead the text column.
export const BACK_ROLES = ['label', 'value', 'footer'] as const;
export type FrontRole = (typeof FRONT_ROLES)[number];
export type BackRole = (typeof BACK_ROLES)[number];

export type TextStyleConfig = {
    front?: Partial<Record<FrontRole, TextStyle | null>> | null;
    back?: Partial<Record<BackRole, TextStyle | null>> | null;
};

/** Elements an admin can move, in the order the designer lists them. */
export const LAYOUT_ELEMENTS = ['header', 'logo_primary', 'logo_secondary', 'photo', 'employee_name', 'employee_position', 'fields', 'emphasis', 'dates'] as const;
export const BACK_LAYOUT_ELEMENTS = ['qr', 'notes', 'seal', 'emergency', 'card_number', 'signature', 'signature_label', 'photo'] as const;
export const LANDSCAPE_FRONT_LAYOUT_ELEMENTS = ['header', 'logo_primary', 'logo_secondary', 'photo', 'fields', 'emphasis', 'dates'] as const;
export const PORTRAIT_FRONT_LAYOUT_ELEMENTS = ['header', 'logo_primary', 'logo_secondary', 'photo', 'employee_name', 'employee_position', 'emphasis'] as const;
export const PORTRAIT_BACK_LAYOUT_ELEMENTS = ['qr', 'seal', 'photo'] as const;
export type LayoutElement = (typeof LAYOUT_ELEMENTS)[number];
export type BackLayoutElement = (typeof BACK_LAYOUT_ELEMENTS)[number];
export type AnyLayoutElement = LayoutElement | BackLayoutElement;
export type LayoutSide = 'front' | 'back';

/** A box on the card, as a percentage of card width and height. */
export type LayoutBox = { x: number; y: number; w: number; h: number };

export type LayoutConfig = {
    front?: Partial<Record<LayoutElement, LayoutBox | null>> | null;
    back?: Partial<Record<BackLayoutElement, LayoutBox | null>> | null;
};

/** Mirrors IdCardLayoutElement::ELEMENTS — the built-in arrangement. */
export const LAYOUT_DEFAULTS: Record<LayoutElement, LayoutBox> = {
    // The band and its text. Each logo is positioned separately so an admin can
    // move one mark without disturbing the other.
    header: { x: 0, y: 0, w: 85, h: 16 },
    logo_primary: { x: 1.5, y: 2.5, w: 10.5, h: 14 },
    logo_secondary: { x: 72, y: 0, w: 27, h: 15.5 },
    // Both clear the header band rather than starting underneath it.
    photo: { x: 2, y: 22, w: 25, h: 49 },
    employee_name: { x: 28, y: 22, w: 72, h: 10 },
    employee_position: { x: 28, y: 32, w: 72, h: 8 },
    fields: { x: 28, y: 22, w: 72, h: 68 },
    emphasis: { x: 2.5, y: 74.5, w: 96, h: 12 },
    // Issue and expiry sit on the front, between the emphasised ID number and
    // the footer, so validity reads without turning the card.
    dates: { x: 2.5, y: 86.5, w: 96, h: 6 },
};

export const BACK_LAYOUT_DEFAULTS: Record<BackLayoutElement, LayoutBox> = {
    qr: { x: 0, y: 4.5, w: 38.5, h: 57 },
    notes: { x: 2.5, y: 83, w: 70.5, h: 15.5 },
    seal: { x: 76.5, y: 66.5, w: 15, h: 26 },
    // Two contacts, each a four-line bilingual field.
    emergency: { x: 41.5, y: 6, w: 58.5, h: 43.5 },
    card_number: { x: 7, y: 62, w: 35, h: 7 },
    signature: { x: 55, y: 78.5, w: 34, h: 7 },
    // The caption naming the signing line, movable on its own.
    signature_label: { x: 55, y: 73.5, w: 34, h: 5 },
    // The employee photo as a watermark; off unless a template enables it.
    photo: { x: 60, y: 20, w: 25, h: 45 },
};

/** Mirrors IdCardBackPhoto — the back photo is off until a template enables it. */
export const BACK_PHOTO_DEFAULTS: BackPhotoConfig = {
    show: false,
    opacity: 15,
    contrast: 100,
    fit: 'cover',
    background_color: null,
};

/** Defaults for either side, so callers stay side-agnostic. */
export function layoutDefaults(side: LayoutSide): Record<string, LayoutBox> {
    return side === 'back' ? BACK_LAYOUT_DEFAULTS : LAYOUT_DEFAULTS;
}

/** Where one element sits, falling back to the built-in arrangement. */
export function layoutBox(
    template: TemplatePresentation | null | undefined,
    element: AnyLayoutElement,
    side: LayoutSide = 'front',
): LayoutBox {
    const stored = template?.layout_config?.[side] as Record<string, LayoutBox | null> | null | undefined;

    return stored?.[element] ?? layoutDefaults(side)[element];
}

/** Absolute CSS for a layout box, so preview matches the exported card. */
export function layoutStyle(
    template: TemplatePresentation | null | undefined,
    element: AnyLayoutElement,
    side: LayoutSide = 'front',
): CSSProperties {
    const box = layoutBox(template, element, side);

    return {
        position: 'absolute',
        left: `${box.x}%`,
        top: `${box.y}%`,
        width: `${box.w}%`,
        height: `${box.h}%`,
    };
}

/**
 * Front-header content, already resolved server-side: every field carries the
 * template's own text, or the global setting it inherits.
 */
export type HeaderConfig = {
    city_name_en: string;
    city_name_am: string;
    bureau_name_en: string;
    bureau_name_am: string;
    show_logo: boolean;
    show_secondary_logo: boolean;
};

/** How the back face draws the employee photo, if at all. */
export type BackPhotoConfig = {
    show: boolean;
    /** Percent, 0-100. */
    opacity: number;
    /** Percent, 0-300, applied as a CSS contrast filter. */
    contrast: number;
    fit: 'cover' | 'contain' | 'stretch';
    /** Painted behind the photo; null leaves the card surface showing. */
    background_color: string | null;
};

export type TemplatePresentation = {
    id?: string;
    orientation: 'portrait' | 'landscape';
    width_mm: number;
    height_mm: number;
    front_background_url: string | null;
    back_background_url: string | null;
    text_style_config?: TextStyleConfig | null;
    layout_config?: LayoutConfig | null;
    header_config?: HeaderConfig | null;
    /**
     * The header's two logo slots. Null means the template uploaded none, so
     * the slot falls back to whatever the card already supplies.
     */
    logo_primary_url?: string | null;
    logo_secondary_url?: string | null;
    /**
     * The template's own seal and signature. A null seal falls back to the
     * global `general.seal` setting; a null signature leaves the ruled line to
     * be signed by hand.
     */
    seal_url?: string | null;
    signature_url?: string | null;
    back_photo_config?: BackPhotoConfig | null;
};

/**
 * Turns a stored style into inline CSS. Falls back to the card's own colour so
 * templates saved before per-template typography keep their appearance.
 *
 * Template font sizes are authored against a full-size card (85.6mm ≈ 856px in
 * the export renderer). On screen the card is drawn much smaller, so sizes are
 * expressed in `em` of the card's own font size — the card scales, the text
 * scales with it, and a long label cannot overflow its column.
 */
export function textStyleCss(style: TextStyle | null | undefined, fallbackColor: string): CSSProperties {
    const px = style?.font_size ? Number.parseFloat(style.font_size) : null;

    return {
        color: style?.color ?? fallbackColor,
        // The SVG renderer doubles these px values onto its 856px canvas, so a
        // size is that fraction of the card's width however wide it is drawn.
        fontSize: px !== null && Number.isFinite(px) ? `${(px * 2) / 856}em` : undefined,
        fontWeight: style?.font_weight as CSSProperties['fontWeight'],
    };
}

/**
 * Resolves one role's style from a template, so components stay agnostic about
 * how the config is shaped.
 */
export function roleStyle(
    template: TemplatePresentation | null | undefined,
    side: 'front' | 'back',
    role: FrontRole | BackRole,
): TextStyle | null {
    return template?.text_style_config?.[side]?.[role as keyof object] ?? null;
}

export const IdCardTemplateContext = createContext<TemplatePresentation | null | undefined>(undefined);

/**
 * The template to draw a card face with.
 *
 * A template is built for one orientation — its artwork, layout boxes and
 * millimetres all assume that shape — so a face asks for the template built
 * for the shape it is drawing. When that orientation has no template the hook
 * returns null and the card falls back to the built-in arrangement, rather
 * than borrowing the other orientation's layout.
 *
 * Omitting the orientation keeps the old meaning: whatever the default
 * template is. A context override (the template editor's live preview) always
 * wins, because there the administrator is looking at one specific template.
 */
export function useIdCardTemplate(orientation?: 'portrait' | 'landscape') {
    const override = useContext(IdCardTemplateContext);
    const { idCardTemplate, idCardTemplates } = usePage<PageProps<{
        idCardTemplate?: TemplatePresentation | null;
        idCardTemplates?: Partial<Record<'portrait' | 'landscape', TemplatePresentation | null>> | null;
    }>>().props;

    if (override !== undefined) {
        return override;
    }

    if (orientation !== undefined) {
        return idCardTemplates?.[orientation] ?? null;
    }

    return idCardTemplate ?? null;
}

export function useCardDimensions(orientation: 'portrait' | 'landscape') {
    const template = useIdCardTemplate(orientation);
    const portrait = orientation === 'portrait';
    const long = template ? Math.max(template.width_mm, template.height_mm) : 85.6;
    const short = template ? Math.min(template.width_mm, template.height_mm) : 54;
    const widthMm = portrait ? short : long;
    const heightMm = portrait ? long : short;
    return {
        widthMm,
        heightMm,
        width: widthMm * 5,
        height: heightMm * 5,
        printStyle: { '--id-card-width': `${widthMm}mm`, '--id-card-height': `${heightMm}mm` } as CSSProperties,
    };
}
