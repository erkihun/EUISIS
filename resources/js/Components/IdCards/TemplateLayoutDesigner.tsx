import { useCallback, useLayoutEffect, useRef, useState, type PointerEvent as ReactPointerEvent, type ReactNode } from 'react';
import {
    BACK_LAYOUT_ELEMENTS,
    LAYOUT_ELEMENTS,
    layoutDefaults,
    type LayoutBox,
    type LayoutSide,
} from '@/Components/IdCards/IdCardTemplateContext';

import { buttonClassName } from '@/Components/ui';
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
    text: { reset: string; resetAll: string; hint: string; elements?: string; x?: string; y?: string; w?: string; h?: string; overflow?: string };
    /**
     * The selected element, when the page drives selection (for example from
     * the printed-fields list). Left out, the designer tracks it itself.
     */
    selected?: string | null;
    onSelect?: (element: string | null) => void;
    /**
     * Drop a box on the other card to move it there. Called with the element
     * and the drop point in percent of the other card; only elements for
     * which `canTransfer` is true can cross.
     */
    onTransfer?: (element: string, point: { x: number; y: number }) => void;
    canTransfer?: (element: string) => boolean;
    /** Orientation-specific elements to expose; omitted for the legacy full set. */
    elements?: readonly string[];
    /**
     * Safe-zone inset, in percent of the card per axis. When set, a dashed line
     * marks where text and logos are safe from the printer's cut.
     */
    guideInset?: { x: number; y: number } | null;
    /** The card's shape, so a reset puts each element back where that shape has it. */
    orientation?: 'landscape' | 'portrait';
    /**
     * The card being designed. The designer wraps it so the drag surface is
     * sized to the card alone: box positions are percentages of the surface,
     * and when the surface shared a wrapper with the controls below the card,
     * every box was scaled against the card plus the controls.
     */
    children: ReactNode;
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
export default function TemplateLayoutDesigner({
    side, value, onChange, disabled, labels, text, elements: requestedElements, guideInset, orientation = 'landscape',
    selected: controlledSelected, onSelect, onTransfer, canTransfer, children,
}: Props) {
    const elements: readonly string[] = requestedElements ?? (side === 'back' ? BACK_LAYOUT_ELEMENTS : LAYOUT_ELEMENTS);
    const defaults = layoutDefaults(side, orientation);
    const surfaceRef = useRef<HTMLDivElement>(null);
    const [ownSelected, setOwnSelected] = useState<string | null>(null);
    const selected = controlledSelected !== undefined ? controlledSelected : ownSelected;
    const setSelected = (element: string | null) => {
        setOwnSelected(element);
        onSelect?.(element);
    };
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
            box: value[element] ?? defaults[element],
        };
        event.currentTarget.setPointerCapture(event.pointerId);
    }

    /** The other card under the pointer, with the point in percent of it. */
    function dropTarget(clientX: number, clientY: number): { surface: HTMLElement; x: number; y: number } | null {
        for (const surface of document.querySelectorAll<HTMLElement>('[data-layout-surface]')) {
            if (surface === surfaceRef.current || surface.dataset.layoutSurface === side) continue;
            const rect = surface.getBoundingClientRect();
            if (clientX >= rect.left && clientX <= rect.right && clientY >= rect.top && clientY <= rect.bottom) {
                return { surface, x: ((clientX - rect.left) / rect.width) * 100, y: ((clientY - rect.top) / rect.height) * 100 };
            }
        }
        return null;
    }
    const highlighted = useRef<HTMLElement | null>(null);
    function highlight(surface: HTMLElement | null) {
        if (highlighted.current === surface) return;
        highlighted.current?.removeAttribute('data-drop-target');
        surface?.setAttribute('data-drop-target', 'true');
        highlighted.current = surface;
    }

    function onPointerMove(event: ReactPointerEvent<HTMLElement>) {
        const drag = dragRef.current;
        const surface = surfaceRef.current;
        if (!drag || !surface) return;

        // Dragging a field off this card over the other one offers it there.
        if (drag.mode === 'move' && onTransfer && canTransfer?.(drag.element)) {
            highlight(dropTarget(event.clientX, event.clientY)?.surface ?? null);
        }

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
        const drag = dragRef.current;
        if (drag) {
            event.currentTarget.releasePointerCapture?.(event.pointerId);
            dragRef.current = null;
            highlight(null);
            const target = drag.mode === 'move' && onTransfer && canTransfer?.(drag.element)
                ? dropTarget(event.clientX, event.clientY)
                : null;
            if (target) {
                // Put the box back where it started on this card, then hand it over.
                update(drag.element, drag.box);
                onTransfer?.(drag.element, { x: target.x, y: target.y });
            }
        }
    }

    /** Arrow keys nudge the selected box, so the designer is usable without a mouse. */
    function onKeyDown(event: React.KeyboardEvent<HTMLDivElement>, element: string) {
        if (disabled) return;
        const box = value[element] ?? defaults[element];
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

    const active = selected ? (value[selected] ?? defaults[selected] ?? null) : null;

    // Fields whose printed text is taller than their box, measured on the card
    // underneath (every `field_<key>` box draws `[data-card-field=<key>]`). The
    // card is drawn at the printed proportions, so overflow here is overflow
    // on the plastic.
    const cardRef = useRef<HTMLDivElement>(null);
    const [overflowing, setOverflowing] = useState<string[]>([]);
    useLayoutEffect(() => {
        const found = elements.filter((element) => {
            if (!element.startsWith('field_')) return false;
            const field = cardRef.current?.querySelector<HTMLElement>(`[data-card-field="${element.slice(6)}"]`);
            return field !== null && field !== undefined && field.scrollHeight > field.clientHeight + 1;
        });
        setOverflowing((current) => (current.join() === found.join() ? current : found));
    });

    return (
        <>
            <div ref={cardRef} className="relative">
            {children}
            <div
                ref={surfaceRef}
                data-layout-surface={side}
                className="absolute inset-0 rounded-sm data-[drop-target=true]:bg-blue-500/10 data-[drop-target=true]:outline-2 data-[drop-target=true]:outline-dashed data-[drop-target=true]:outline-primary"
                onPointerMove={onPointerMove}
                onPointerUp={endDrag}
                onPointerCancel={endDrag}
                style={{ touchAction: 'none' }}
            >
                {guideInset && (
                    <div
                        aria-hidden
                        data-print-guide="safe-zone"
                        className="pointer-events-none absolute border border-dashed border-rose-500/70"
                        style={{
                            left: `${guideInset.x}%`,
                            right: `${guideInset.x}%`,
                            top: `${guideInset.y}%`,
                            bottom: `${guideInset.y}%`,
                        }}
                    />
                )}
                {elements.map((element) => {
                    const box = value[element] ?? defaults[element];
                    const isSelected = selected === element;
                    const overflows = overflowing.includes(element);
                    if (!box) return null;

                    return (
                        <div
                            key={element}
                            role="button"
                            tabIndex={disabled ? -1 : 0}
                            aria-label={overflows && text.overflow ? `${labels[element]} — ${text.overflow}` : labels[element]}
                            title={overflows ? text.overflow : undefined}
                            aria-pressed={isSelected}
                            onPointerDown={(event) => beginDrag(event, element, 'move')}
                            onKeyDown={(event) => onKeyDown(event, element)}
                            onFocus={() => setSelected(element)}
                            data-layout-element={element}
                            className={[
                                // `group` lets the name tag show on hover.
                                'group absolute rounded-xs transition-colors',
                                disabled ? 'cursor-not-allowed' : 'cursor-move',
                                isSelected
                                    // Lifted so a box under a neighbour stays draggable once picked.
                                    ? 'z-10 border-2 border-primary bg-blue-500/10'
                                    : overflows
                                        ? 'border-2 border-amber-500 bg-amber-400/10 hover:z-10'
                                        : 'border border-dashed border-blue-400/60 hover:z-10 hover:border-primary hover:bg-blue-500/5',
                            ].join(' ')}
                            style={{
                                left: `${box.x}%`,
                                top: `${box.y}%`,
                                width: `${box.w}%`,
                                height: `${box.h}%`,
                            }}
                        >
                            <span className="pointer-events-none absolute -top-4 left-0 z-20 whitespace-nowrap rounded-sm bg-primary px-1 text-[9px] font-medium text-white opacity-0 group-hover:opacity-100" style={{ opacity: isSelected ? 1 : undefined }}>
                                {labels[element]}
                            </span>
                            {overflows && (
                                <span aria-hidden className="pointer-events-none absolute -right-1.5 -top-1.5 z-20 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-amber-500 text-[9px] font-bold text-white">!</span>
                            )}
                            {isSelected && !disabled && (
                                <span
                                    role="button"
                                    aria-label={`${labels[element]} — resize`}
                                    onPointerDown={(event) => beginDrag(event, element, 'resize')}
                                    className="absolute -bottom-2 -right-2 h-4 w-4 cursor-nwse-resize rounded-full border-2 border-white bg-primary shadow-sm"
                                />
                            )}
                        </div>
                    );
                })}
            </div>

            </div>

            {/*
             * Readout and resets sit below the card, in normal flow. They used
             * to be `absolute top-full`, which took them out of layout: the
             * front card's controls were drawn over the back card, and the back
             * card's hung out of the bottom of the preview panel.
             */}
            {text.hint && <p className="mt-2 text-xs text-(--app-muted-foreground)">{text.hint}</p>}
            {overflowing.length > 0 && text.overflow && (
                <p role="status" className="mt-2 text-xs text-amber-700 dark:text-amber-300">
                    {overflowing.map((element) => labels[element]).join(', ')}: {text.overflow}
                </p>
            )}
            {/* Every element on this face by name, so a small or covered box is
                one click away rather than a hunt across the card. */}
            <div role="group" aria-label={text.elements ?? undefined} className="mt-2 flex flex-wrap gap-1">
                {elements.map((element) => (
                    <button
                        key={element}
                        type="button"
                        aria-pressed={selected === element}
                        onClick={() => setSelected(selected === element ? null : element)}
                        className={[
                            'rounded-full border px-2 py-0.5 text-[11px] transition-colors',
                            selected === element
                                ? 'border-primary bg-primary text-white'
                                : overflowing.includes(element)
                                    ? 'border-amber-500 text-amber-700 hover:border-primary dark:text-amber-300'
                                    : 'border-(--app-border-strong) text-(--app-foreground) hover:border-primary',
                        ].join(' ')}
                        title={overflowing.includes(element) ? text.overflow : undefined}
                    >
                        {overflowing.includes(element) && <span aria-hidden>! </span>}
                        {labels[element]}
                    </button>
                ))}
            </div>
            <div className="mt-2 flex flex-wrap items-end gap-2 text-xs">
                {active && selected && (
                    <>
                        <span className="w-full font-medium text-(--app-foreground)">{labels[selected]}</span>
                        {/* Exact placement, in percent of the card, for alignment the mouse cannot give. */}
                        {(['x', 'y', 'w', 'h'] as const).map((axis) => {
                            const max = axis === 'x' ? 100 - active.w : axis === 'y' ? 100 - active.h : 100 - (axis === 'w' ? active.x : active.y);
                            const min = axis === 'w' || axis === 'h' ? 1 : 0;
                            return (
                                <label key={axis} className="flex flex-col gap-0.5 text-(--app-muted-foreground)">
                                    {text[axis] ?? axis.toUpperCase()} %
                                    <input
                                        type="number"
                                        inputMode="decimal"
                                        step={SNAP}
                                        min={min}
                                        max={max}
                                        disabled={disabled}
                                        value={active[axis]}
                                        onChange={(event) => {
                                            const next = Number(event.target.value);
                                            if (Number.isFinite(next)) update(selected, { ...active, [axis]: clamp(next, min, max) });
                                        }}
                                        className="w-16 rounded-md border border-(--app-border-strong) bg-(--app-surface) px-1.5 py-1 font-mono text-(--app-foreground)"
                                    />
                                </label>
                            );
                        })}
                        <button
                            type="button"
                            disabled={disabled}
                            onClick={() => update(selected, defaults[selected])}
                            className={buttonClassName({ variant: 'outline', size: 'sm' })}
                        >
                            {text.reset}
                        </button>
                    </>
                )}
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => onChange({
                        ...value,
                        ...Object.fromEntries(elements.map((element) => [element, defaults[element]])),
                    })}
                    className={buttonClassName({ variant: 'outline', size: 'sm' })}
                >
                    {text.resetAll}
                </button>
            </div>
        </>
    );
}
