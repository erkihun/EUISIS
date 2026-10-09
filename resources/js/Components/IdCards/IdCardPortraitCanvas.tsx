import type { CSSProperties, ReactNode } from 'react';

export const PORTRAIT_CANVAS_WIDTH  = 540;
export const PORTRAIT_CANVAS_HEIGHT = 856;

type Props = {
    children: ReactNode;
    style?: CSSProperties;
    className?: string;
};

export default function IdCardPortraitCanvas({ children, style, className }: Props) {
    return (
        <div
            className={className}
            style={{
                width: PORTRAIT_CANVAS_WIDTH,
                height: PORTRAIT_CANVAS_HEIGHT,
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
