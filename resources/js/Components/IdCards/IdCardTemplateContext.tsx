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
export const FRONT_ROLES = ['header', 'label', 'value', 'footer'] as const;
export const BACK_ROLES = ['header', 'label', 'value', 'qr_instruction', 'footer'] as const;
export type FrontRole = (typeof FRONT_ROLES)[number];
export type BackRole = (typeof BACK_ROLES)[number];

export type TextStyleConfig = {
    front?: Partial<Record<FrontRole, TextStyle | null>> | null;
    back?: Partial<Record<BackRole, TextStyle | null>> | null;
};

/** Elements an admin can move, in the order the designer lists them. */
export const LAYOUT_ELEMENTS = ['header', 'photo', 'fields', 'emphasis', 'footer'] as const;
export const BACK_LAYOUT_ELEMENTS = ['qr', 'notes', 'seal', 'details', 'dates', 'emergency'] as const;
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
    header: { x: 0, y: 0, w: 100, h: 9 },
    photo: { x: 1.9, y: 9.6, w: 8.4, h: 17.8 },
    fields: { x: 11.7, y: 9.6, w: 86, h: 60 },
    emphasis: { x: 1.9, y: 76, w: 96, h: 12 },
    footer: { x: 0, y: 94, w: 100, h: 6 },
};

export const BACK_LAYOUT_DEFAULTS: Record<BackLayoutElement, LayoutBox> = {
    qr: { x: 1.9, y: 30, w: 22, h: 45 },
    notes: { x: 27, y: 5, w: 71, h: 25 },
    seal: { x: 55, y: 32, w: 22.4, h: 35.6 },
    details: { x: 27, y: 73, w: 71, h: 20 },
    dates: { x: 27, y: 88, w: 71, h: 10 },
    emergency: { x: 1.9, y: 78, w: 23, h: 18 },
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

export type TemplatePresentation = {
    id?: string;
    orientation: 'portrait' | 'landscape';
    width_mm: number;
    height_mm: number;
    front_background_url: string | null;
    back_background_url: string | null;
    text_style_config?: TextStyleConfig | null;
    layout_config?: LayoutConfig | null;
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

export function useIdCardTemplate() {
    const override = useContext(IdCardTemplateContext);
    const { idCardTemplate } = usePage<PageProps<{ idCardTemplate?: TemplatePresentation | null }>>().props;
    return override === undefined ? (idCardTemplate ?? null) : override;
}

export function useCardDimensions(orientation: 'portrait' | 'landscape') {
    const template = useIdCardTemplate();
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
