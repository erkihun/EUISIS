import { cx, inputClassName } from '@euisis/ui';
import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type KeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import { useLocale } from '@/hooks/useLocale';

export type SearchableSelectOption = {
    value: string;
    label: string;
    /** Extra text the search also matches, e.g. the name in the other language. */
    keywords?: string;
};

type Row = { value: string; label: string; clear: boolean };

/**
 * A select with a search box, for option lists that are already on the page.
 *
 * Typing narrows the list in the browser; nothing is fetched. For lists that
 * must be searched on the server, use the Lookup component instead.
 *
 * Keyboard: ↓/↑ move through the options, Enter picks one, Escape closes the
 * list. Focus stays in the input (aria-activedescendant points at the
 * highlighted option), so a click on an option never closes the list first.
 */
export default function SearchableSelect({
    id,
    value,
    options,
    onChange,
    placeholder,
    searchPlaceholder,
    disabled = false,
}: {
    id?: string;
    value: string;
    options: SearchableSelectOption[];
    onChange: (value: string) => void;
    /** Shown while nothing is selected. */
    placeholder: string;
    /** Shown while the list is open and the search box is empty. */
    searchPlaceholder?: string;
    disabled?: boolean;
}) {
    const { t } = useLocale();
    const listId = useId();
    const listRef = useRef<HTMLUListElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const [position, setPosition] = useState<CSSProperties>({});
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    /** The row the user moved the highlight to; null while it is still where the list put it. */
    const [moved, setMoved] = useState<number | null>(null);

    const selected = options.find((option) => option.value === value) ?? null;

    const matches = useMemo(() => {
        const terms = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
        if (terms.length === 0) return options;

        return options.filter((option) => {
            const haystack = `${option.label} ${option.keywords ?? ''}`.toLowerCase();

            return terms.every((term) => haystack.includes(term));
        });
    }, [options, query]);

    const rows: Row[] = [
        ...(value ? [{ value: '', label: t('common.clear'), clear: true }] : []),
        ...matches.map((option) => ({ value: option.value, label: option.label, clear: false })),
    ];

    /*
     * The highlighted row. Until the user moves it, it is the current choice,
     * or the first match once they type. It is worked out while rendering, not
     * in an effect, so Enter pressed straight after the last keystroke already
     * picks the row the list shows as highlighted.
     */
    const offset = value ? 1 : 0;
    const current = query.trim() === '' ? matches.findIndex((option) => option.value === value) : -1;
    const startingRow = current >= 0 ? current + offset : matches.length > 0 ? offset : value ? 0 : -1;
    const active = moved !== null && moved < rows.length ? moved : startingRow;

    useEffect(() => {
        if (!open || active < 0) return;
        listRef.current?.children[active]?.scrollIntoView({ block: 'nearest' });
    }, [open, active]);

    /*
     * The list is drawn on the page body, not inside the field, so a card that
     * clips its content (overflow-hidden) cannot cut it off. It opens below the
     * input, or above when there is more room there, and follows the input as
     * the page scrolls or resizes.
     */
    useLayoutEffect(() => {
        if (!open) return;

        function updatePosition() {
            if (!inputRef.current) return;
            const anchor = inputRef.current.getBoundingClientRect();
            const gap = 4;
            const gutter = 8;
            const below = window.innerHeight - anchor.bottom - gap - gutter;
            const above = anchor.top - gap - gutter;
            const openAbove = below < 160 && above > below;

            setPosition({
                left: anchor.left,
                width: anchor.width,
                maxHeight: Math.max(96, Math.min(256, openAbove ? above : below)),
                ...(openAbove
                    ? { bottom: window.innerHeight - anchor.top + gap }
                    : { top: anchor.bottom + gap }),
            });
        }

        updatePosition();
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);

        return () => {
            window.removeEventListener('resize', updatePosition);
            window.removeEventListener('scroll', updatePosition, true);
        };
    }, [open]);

    function openList() {
        if (open) return;
        setQuery('');
        setMoved(null);
        setOpen(true);
    }

    function pick(row: Row) {
        onChange(row.value);
        setOpen(false);
        setQuery('');
        setMoved(null);
    }

    function onKeyDown(e: KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!open) openList();
            else setMoved(Math.min(rows.length - 1, active + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (open) setMoved(Math.max(0, active - 1));
        } else if (e.key === 'Enter') {
            // Never submit the surrounding form from the search box.
            e.preventDefault();
            if (!open) openList();
            else if (active >= 0 && rows[active]) pick(rows[active]);
        } else if (e.key === 'Escape' && open) {
            e.preventDefault();
            setOpen(false);
        }
    }

    const optionId = (index: number) => `${listId}-option-${index}`;

    return (
        <div className="relative">
            <input
                ref={inputRef}
                id={id}
                type="text"
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={open && active >= 0 ? optionId(active) : undefined}
                autoComplete="off"
                className={cx(inputClassName, 'w-full truncate pr-9')}
                disabled={disabled}
                placeholder={open ? (searchPlaceholder ?? t('common.search')) : placeholder}
                value={open ? query : (selected?.label ?? '')}
                title={!open && selected ? selected.label : undefined}
                onFocus={openList}
                onClick={openList}
                onBlur={() => setOpen(false)}
                onKeyDown={onKeyDown}
                onChange={(e) => { setQuery(e.target.value); setMoved(null); setOpen(true); }}
            />
            <svg
                aria-hidden="true"
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                className={cx(
                    'pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-(--app-muted-foreground)',
                    disabled && 'opacity-50',
                )}
            >
                {open
                    ? <path strokeLinecap="round" strokeLinejoin="round" d="m14.5 14.5 3 3M16 9.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
                    : <path strokeLinecap="round" strokeLinejoin="round" d="m6 8 4 4 4-4" />}
            </svg>
            {open && createPortal(
                <ul
                    ref={listRef}
                    id={listId}
                    role="listbox"
                    className="fixed z-1000 overflow-auto rounded-lg border border-(--app-border) bg-(--app-surface) py-1 text-sm text-(--app-foreground) shadow-lg"
                    style={position}
                >
                    {rows.map((row, index) => (
                        <li
                            key={row.clear ? '__clear' : row.value}
                            id={optionId(index)}
                            role="option"
                            aria-selected={!row.clear && row.value === value}
                            className={cx(
                                'cursor-pointer px-3 py-1.5',
                                index === active && 'bg-(--app-surface-muted)',
                                row.clear && 'text-(--app-muted-foreground)',
                                !row.clear && row.value === value && 'font-medium',
                            )}
                            onMouseDown={(e) => e.preventDefault()}
                            // Movement, not entry: a list scrolling under a still
                            // pointer must not steal the keyboard highlight.
                            onMouseMove={() => { if (index !== active) setMoved(index); }}
                            onClick={() => pick(row)}
                        >
                            {row.label}
                        </li>
                    ))}
                    {matches.length === 0 && (
                        <li className="px-3 py-1.5 text-(--app-muted-foreground)" aria-live="polite">
                            {t('common.noResults')}
                        </li>
                    )}
                </ul>,
                document.body,
            )}
        </div>
    );
}
