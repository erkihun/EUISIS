import type { CSSProperties } from 'react';
import type { SrCategory } from '@/types/situationRoom';

/** One accent per area of the room, so the launcher and the wall say the same thing at a glance. */
export const SECTION_ACCENT: Record<SrCategory['section'], string> = {
    command: '#3b82f6',
    people: '#14b8a6',
    public_voice: '#f59e0b',
    operations: '#8b5cf6',
    governance: '#f43f5e',
};

/** The accent as a tint: coloured text on a translucent wash of the same hue, readable on both themes. */
export const tinted = (accent: string): CSSProperties => ({ color: accent, background: `color-mix(in srgb, ${accent} 14%, transparent)` });
