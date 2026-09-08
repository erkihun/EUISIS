import type { BackRole, TemplatePresentation, TextStyle } from './IdCardTemplateContext';
import { CARD_SURFACE } from './IdCardTemplateContext';

export const BACK_STYLE_DEFAULTS: Record<BackRole, TextStyle> = {
    header: { color: CARD_SURFACE.inkMuted, font_size: '9px', font_weight: '700' },
    label: { color: CARD_SURFACE.inkMuted, font_size: '7px', font_weight: '400' },
    value: { color: CARD_SURFACE.inkMuted, font_size: '8px', font_weight: '600' },
    qr_instruction: { color: CARD_SURFACE.inkMuted, font_size: '9px', font_weight: '500' },
    footer: { color: CARD_SURFACE.inkMuted, font_size: '7px', font_weight: '400' },
};

export type BackTextRow = { role: BackRole; text: string | null | undefined };
type StyledRow = { role: BackRole; text: string; style: TextStyle };
const FONT = "'Abyssinica SIL','Noto Sans Ethiopic','Noto Serif Ethiopic','DejaVu Sans',Arial,sans-serif";

/** Conservative glyph widths, mirrored by IdCardBackTextLayout for server export. */
function textWidth(text: string, size: number): number {
    return Array.from(text).reduce((sum, char) => sum + (/\s/u.test(char) ? 0.33 : char.codePointAt(0)! >= 0x1100 ? 1 : 0.65), 0) * size;
}

function wrap(text: string, width: number, size: number): string[] {
    const lines: string[] = [];
    for (const paragraph of text.split(/\r?\n/)) {
        let line = '';
        for (const word of paragraph.trim().split(/\s+/u)) {
            if (!word) continue;
            if (line && textWidth(`${line} ${word}`, size) > width) {
                lines.push(line);
                line = '';
            }
            for (const char of Array.from((line ? ' ' : '') + word)) {
                if (line && textWidth(line + char, size) > width) {
                    lines.push(line);
                    line = '';
                }
                line += char;
            }
        }
        if (line) lines.push(line);
    }
    return lines;
}

/** Wrap first, then fit the complete text to its box. Saved sizes are the upper limit. */
export function layoutBackText(rows: StyledRow[], width: number, height: number) {
    let scale = 1;
    for (let attempt = 0; attempt < 16; attempt++) {
        let y = 0;
        const lines = rows.flatMap((row) => {
            const size = Number.parseFloat(row.style.font_size) * 2 * scale;
            const result = wrap(row.text, Math.max(1, width - 2), size).map((text) => {
                const line = { ...row, text, size, y: y + size };
                y += size * 1.25;
                return line;
            });
            y += 2 * scale;
            return result;
        });
        if (y <= height || attempt === 15) return lines;
        scale *= Math.min(0.95, height / y);
    }
    return [];
}

/** SVG text keeps the preview, browser capture and server export in the same units. */
export default function BackTextBlock({ rows, width, height, template, center = false }: {
    rows: BackTextRow[];
    width: number;
    height: number;
    template?: TemplatePresentation | null;
    center?: boolean;
}) {
    const styled = rows.filter((row) => row.text?.trim()).map((row) => ({
        ...row,
        text: row.text!,
        style: { ...BACK_STYLE_DEFAULTS[row.role], ...template?.text_style_config?.back?.[row.role] },
    }));
    return (
        <svg data-back-text-block="" width="100%" height="100%" viewBox={`0 0 ${width} ${height}`}
            xmlns="http://www.w3.org/2000/svg" style={{ display: 'block', overflow: 'hidden' }}>
            {layoutBackText(styled, width, height).map((line, index) => (
                <text key={index} data-back-role={line.role} x={center ? width / 2 : 0} y={line.y}
                    textAnchor={center ? 'middle' : 'start'} fontFamily={FONT}
                    fill={line.style.color} fontSize={line.size} fontWeight={line.style.font_weight}>
                    {line.text}
                </text>
            ))}
        </svg>
    );
}
