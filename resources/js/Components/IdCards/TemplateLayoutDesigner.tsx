import { useCallback, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';
import {
    BACK_LAYOUT_ELEMENTS,
    LAYOUT_ELEMENTS,
    layoutDefaults,
    type LayoutBox,
    type LayoutSide,
} from '@/Components/IdCards/IdCardTemplateContext';

type Props = {
    /** Which face this overlay is drawn on. */
    side: LayoutSide;
    /** Current boxes for every element on this side, always complete. */
    value: Record<string, LayoutBox>;
    onChange: (next: Record<string, LayoutBox>) => void;
    disabled?: boolean;
    /** Translated element names, keyed by element. */
    labels: Record<string, string>;
    /** Translated UI strings. */
    text: { reset: string; resetAll: string; hint: string };
};

/** Positions snap to this grid, in percent, so edges line up predictably. */
const SNAP = 0.5;

const clamp = (value: number, min: number, max: number) => Math.min(max, Math.max(min, value));
const snap = (value: number) => Math.round(value / SNAP) * SNAP;

/**
 * Drag-and-resize overlay for the card layout. It renders on top of the live
 * card preview, so the box an admin drags is exactly the region that prints.
 *
 * Boxes are percentages of the card, which is what both renderers consume, so
 * nothing here needs to know the card's pixel size.
 */
export default function TemplateLayoutDesigner({ side, value, onChange, disabled, labels, text }: Props) {
    const elements: readonly string[] = side === 'back' ? BACK_LAYOUT_ELEMENTS : LAYOUT_ELEMENTS;
    const defaults = layoutDefaults(side);
    const surfaceRef = useRef<HTMLDivElement>(null);
    const [selected, setSelected] = useState<string | null>(null);
    const dragRef = useRef<{
        element: string;
        mode: 'move' | 'resize';
        startX: number;
        startY: number;
        box: LayoutBox;
    } | null>(null);

    const update = useCallback(
        (element: string, box: LayoutBox) => onChange({ ...value, [element]: box }),
        [onChange, value],
    );

    function beginDrag(
        event: ReactPointerEvent<HTMLElement>,
        element: string,
        mode: 'move' | 'resize',
    ) {
        if (disabled) return;
        event.preventDefault();
        event.stopPropagation();
        setSelected(element);
        dragRef.current = {
            element,
            mode,
            startX: event.clientX,
            startY: event.clientY,
            box: value[element],
        };
        event.currentTarget.setPointerCapture(event.pointerId);
    }

    function onPointerMove(event: ReactPointerEvent<HTMLElement>) {
        const drag = dragRef.current;
        const surface = surfaceRef.current;
        if (!drag || !surface) return;

        const rect = surface.getBoundingClientRect();
        // Pointer travel converted to percentage of the card.
        const dx = ((event.clientX - drag.startX) / rect.width) * 100;
        const dy = ((event.clientY - drag.startY) / rect.height) * 100;
        const { box } = drag;

        const next =
            drag.mode === 'move'
                ? {
                      ...box,
                      x: snap(clamp(box.x + dx, 0, 100 - box.w)),
                      y: snap(clamp(box.y + dy, 0, 100 - box.h)),
                  }
                : {
                      ...box,
                      w: snap(clamp(box.w + dx, 2, 100 - box.x)),
                      h: snap(clamp(box.h + dy, 2, 100 - box.y)),
                  };

        update(drag.element, next);
    }

    function endDrag(event: ReactPointerEvent<HTMLElement>) {
        if (dragRef.current) {
            event.currentTarget.releasePointerCapture?.(event.pointerId);
            dragRef.current = null;
        }
    }

    /** Arrow keys nudge the selected box, so the designer is usable without a mouse. */
    function onKeyDown(event: React.KeyboardEvent<HTMLDivElement>, element: string) {
        if (disabled) return;
        const box = value[element];
        const step = event.shiftKey ? 2 : SNAP;
        const moves: Record<string, Partial<LayoutBox>> = {
            ArrowLeft: { x: clamp(box.x - step, 0, 100 - box.w) },
            ArrowRight: { x: clamp(box.x + step, 0, 100 - box.w) },
            ArrowUp: { y: clamp(box.y - step, 0, 100 - box.h) },
            ArrowDown: { y: clamp(box.y + step, 0, 100 - box.h) },
        };
        if (event.key === 'Escape') {
            setSelected(null);

            return;
        }
        const move = moves[event.key];
        if (move) {
            event.preventDefault();
            update(element, { ...box, ...move });
        }
    }

    const active = selected ? value[selected] : null;

    return (
        <>
            <div
                ref={surfaceRef}
                className="absolute inset-0"
                onPointerMove={onPointerMove}
                onPointerUp={endDrag}
                onPointerCancel={endDrag}
                style={{ touchAction: 'none' }}
            >
                {elements.map((element) => {
                    const box = value[element];
                    const isSelected = selected === element;

                    return (
                        <div
                            key={element}
                            role="button"
                            tabIndex={disabled ? -1 : 0}
                            aria-label={labels[element]}
                            aria-pressed={isSelected}
                            onPointerDown={(event) => beginDrag(event, element, 'move')}
                            onKeyDown={(event) => onKeyDown(event, element)}
                            onFocus={() => setSelected(element)}
                            className={[
                                'absolute rounded-sm transition-colors',
                                disabled ? 'cursor-not-allowed' : 'cursor-move',
                                isSelected
                                    ? 'border-2 border-[color:var(--color-primary)] bg-blue-500/10'
                                    : 'border border-dashed border-blue-400/60 hover:bg-blue-500/5',
                            ].join(' ')}
                            style={{
                                left: `${box.x}%`,
                                top: `${box.y}%`,
                                width: `${box.w}%`,
                                height: `${box.h}%`,
                            }}
                        >
                            <span className="pointer-events-none absolute -top-4 left-0 whitespace-nowrap rounded bg-[color:var(--color-primary)] px-1 text-[9px] font-medium text-white opacity-0 group-hover:opacity-100" style={{ opacity: isSelected ? 1 : undefined }}>
                                {labels[element]}
                            </span>
                            {isSelected && !disabled && (
                                <span
                                    role="button"
                                    aria-label={`${labels[element]} — resize`}
                                    onPointerDown={(event) => beginDrag(event, element, 'resize')}
                                    className="absolute -bottom-1 -right-1 h-3 w-3 cursor-nwse-resize rounded-sm border border-white bg-[color:var(--color-primary)]"
                                />
                            )}
                        </div>
                    );
                })}
            </div>

            {/* Readout and per-element reset sit below the card, not over it. */}
            <div className="absolute inset-x-0 top-full mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span className="text-gray-500">{text.hint}</span>
                {active && selected && (
                    <>
                        <span className="font-mono text-gray-700 dark:text-slate-300">
                            {labels[selected]} · x {active.x}% y {active.y}% · w {active.w}% h {active.h}%
                        </span>
                        <button
                            type="button"
                            disabled={disabled}
                            onClick={() => update(selected, defaults[selected])}
                            className="rounded border border-gray-300 px-2 py-0.5 hover:bg-gray-50 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
                        >
                            {text.reset}
                        </button>
                    </>
                )}
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => onChange({ ...defaults })}
                    className="rounded border border-gray-300 px-2 py-0.5 hover:bg-gray-50 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800"
                >
                    {text.resetAll}
                </button>
            </div>
        </>
    );
}
