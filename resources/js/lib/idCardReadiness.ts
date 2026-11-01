/**
 * Print readiness for an ID card template: the standard card sizes and the
 * checks an admin sees before publishing. Pure functions, so the editor and
 * its tests share one definition.
 */

/** Standard card formats (ISO/IEC 7810 and the CR79 printer stock), landscape mm. */
export const CARD_SIZE_PRESETS = [
    { key: 'id1', width: 85.6, height: 54 },
    { key: 'cr79', width: 83.9, height: 52.1 },
    { key: 'id2', width: 105, height: 74 },
    { key: 'id3', width: 125, height: 88 },
] as const;

export type CardSizePresetKey = (typeof CARD_SIZE_PRESETS)[number]['key'];

/** Text and logos should stay this far inside the trim; printers cut within it. */
export const SAFE_MARGIN_MM = 3;

/** The preset a size matches in either orientation, or null for a custom size. */
export function matchCardSizePreset(widthMm: number, heightMm: number): CardSizePresetKey | null {
    const long = Math.max(widthMm, heightMm);
    const short = Math.min(widthMm, heightMm);
    const preset = CARD_SIZE_PRESETS.find(
        (candidate) => Math.abs(candidate.width - long) < 0.05 && Math.abs(candidate.height - short) < 0.05,
    );

    return preset?.key ?? null;
}

/** A preset's size in the given orientation. */
export function presetSize(key: CardSizePresetKey, orientation: 'landscape' | 'portrait'): { width: number; height: number } {
    const preset = CARD_SIZE_PRESETS.find((candidate) => candidate.key === key) ?? CARD_SIZE_PRESETS[0];

    return orientation === 'portrait'
        ? { width: preset.height, height: preset.width }
        : { width: preset.width, height: preset.height };
}

/** The safe-zone inset as a percentage of the card, per axis. */
export function safeInset(widthMm: number, heightMm: number): { x: number; y: number } {
    return {
        x: (SAFE_MARGIN_MM / Math.max(widthMm, 1)) * 100,
        y: (SAFE_MARGIN_MM / Math.max(heightMm, 1)) * 100,
    };
}

/** WCAG relative luminance of a #rgb or #rrggbb colour; null when unparseable. */
function luminance(hex: string): number | null {
    const match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex.trim());
    if (!match) return null;
    const digits = match[1].length === 3 ? match[1].replace(/./g, (c) => c + c) : match[1];
    const [r, g, b] = [0, 2, 4].map((offset) => {
        const channel = parseInt(digits.slice(offset, offset + 2), 16) / 255;
        return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio between two colours; null when either is unparseable. */
export function contrastRatio(foreground: string, background: string): number | null {
    const a = luminance(foreground);
    const b = luminance(background);
    if (a === null || b === null) return null;

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

export type ReadinessLevel = 'ok' | 'warning' | 'error';

export type ReadinessCheck = {
    /** Translation key under settings.templateManager.readiness. */
    key: string;
    level: ReadinessLevel;
    /** Values substituted into the message, as :name placeholders. */
    params?: Record<string, string>;
};

export type ReadinessInput = {
    orientation: 'landscape' | 'portrait';
    widthMm: number;
    heightMm: number;
    printedFields: string[];
    hasFrontArtwork: boolean;
    hasBackArtwork: boolean;
    hasSeal: boolean;
    hasSignature: boolean;
    /** The install prints a signature line (id_cards.show_signature). */
    printsSignature: boolean;
    status: 'active' | 'inactive';
    isDefault: boolean;
    /** Resolved header lines; blank when neither template nor settings set one. */
    headerLines: string[];
    /** Text colour per role, keyed `${side}.${role}`, as the card will print it. */
    textColors: Record<string, string>;
    /** Plain card surface used when a side has no artwork. */
    surface: string;
};

/** Body text needs 4.5:1; small captions are held to the same bar on a card. */
const MIN_CONTRAST = 4.5;

/**
 * Every check, in the order the review step lists them. Errors block a sound
 * print run; warnings are worth a second look; ok rows confirm the basics.
 */
export function readinessChecks(input: ReadinessInput): ReadinessCheck[] {
    const checks: ReadinessCheck[] = [];
    const preset = matchCardSizePreset(input.widthMm, input.heightMm);

    checks.push(preset
        ? { key: 'size_standard', level: 'ok', params: { size: `${input.widthMm} × ${input.heightMm} mm` } }
        : { key: 'size_custom', level: 'warning', params: { size: `${input.widthMm} × ${input.heightMm} mm` } });

    if (input.orientation === 'landscape') {
        checks.push(input.printedFields.length > 0
            ? { key: 'fields_ok', level: 'ok', params: { count: String(input.printedFields.length) } }
            : { key: 'fields_none', level: 'error' });
        if (!input.printedFields.includes('name')) {
            checks.push({ key: 'fields_no_name', level: 'warning' });
        }
        if (!input.printedFields.includes('idNumber')) {
            checks.push({ key: 'fields_no_id', level: 'warning' });
        }
    }

    // Portrait headers print the employer's own name, not these lines.
    if (input.orientation === 'landscape' && input.headerLines.every((line) => line.trim() === '')) {
        checks.push({ key: 'header_blank', level: 'warning' });
    }

    checks.push(input.hasSeal ? { key: 'seal_ok', level: 'ok' } : { key: 'seal_missing', level: 'warning' });

    if (input.orientation === 'landscape' && input.printsSignature && !input.hasSignature) {
        checks.push({ key: 'signature_missing', level: 'warning' });
    }

    // Contrast is only knowable against the plain surface; artwork varies.
    for (const [role, color] of Object.entries(input.textColors)) {
        const side = role.startsWith('back.') ? 'back' : 'front';
        const hasArtwork = side === 'front' ? input.hasFrontArtwork : input.hasBackArtwork;
        if (hasArtwork) continue;
        const ratio = contrastRatio(color, input.surface);
        if (ratio !== null && ratio < MIN_CONTRAST) {
            checks.push({ key: 'contrast_low', level: 'warning', params: { role, ratio: ratio.toFixed(1) } });
        }
    }
    if (input.hasFrontArtwork || input.hasBackArtwork) {
        checks.push({ key: 'contrast_artwork', level: 'ok' });
    }

    if (input.isDefault && input.status !== 'active') {
        checks.push({ key: 'default_inactive', level: 'error' });
    }

    return checks;
}
