import type { CSSProperties, ReactNode } from 'react';

/**
 * Fixed pixel size for the ID card capture / print canvas.
 *
 * Physical card (ISO/IEC 7810 ID-1) is 85.6 × 54 mm. We render at
 * 10 px / mm so the offscreen capture node and the print node both
 * have the same intrinsic pixel size; this keeps the live preview,
 * the exported PNG and the printed paper output visually identical.
 */
export const CARD_CANVAS_WIDTH = 856;
export const CARD_CANVAS_HEIGHT = 540;

type Props = {
    children: ReactNode;
    style?: CSSProperties;
    className?: string;
};

/**
 * Thin wrapper that gives `IdCardFront` / `IdCardBack` a fixed
 * 856 × 540 px canvas with the print-color-adjust hints needed
 * to make printers reproduce the gradient backgrounds faithfully.
 *
 * The inner card components fill 100 % of this container — so they
 * stay pixel-accurate regardless of the outer transform / scaling
 * applied to the visible preview.
 */
export default function IdCardCanvas({ children, style, className }: Props) {
    return (
        <div
            className={className}
            style={{
                width: CARD_CANVAS_WIDTH,
                height: CARD_CANVAS_HEIGHT,
                position: 'relative',
                overflow: 'hidden',
                flexShrink: 0,
                printColorAdjust: 'exact',
                WebkitPrintColorAdjust: 'exact',
                ...style,
            }}
        >
            {children}
        </div>
    );
}
