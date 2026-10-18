import { useEffect, useRef, type JSX } from 'react';

/** Emoji are message content here, never interface icons. */
export const COMPOSER_EMOJI = [
    '😀', '😄', '😊', '🙂', '😉', '😍', '🤝', '🙏',
    '👍', '👎', '👏', '🙌', '💪', '👌', '✅', '❌',
    '❤️', '🎉', '🔥', '⭐', '💡', '📌', '📎', '📅',
    '😂', '😢', '😮', '🤔', '😅', '😐', '🙁', '😴',
    '☕', '🌍', '🇪🇹', '📣', '⏰', '📝', '📞', '🚗',
];

type Props = {
    emoji: readonly string[];
    label: string;
    onPick: (emoji: string) => void;
    onClose: () => void;
    align?: 'start' | 'end';
    placement?: 'above' | 'below';
    columns?: number;
};

/**
 * A small popover grid. Arrow keys move between emoji, Escape or a click
 * outside closes it, and focus returns to whatever opened it.
 */
export default function EmojiPicker({ emoji, label, onPick, onClose, align = 'start', placement = 'above', columns = 8 }: Props): JSX.Element {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const opener = document.activeElement as HTMLElement | null;
        ref.current?.querySelector<HTMLButtonElement>('button')?.focus();
        const outside = (event: MouseEvent) => {
            if (ref.current && !ref.current.contains(event.target as Node)) onClose();
        };
        document.addEventListener('mousedown', outside);

        return () => {
            document.removeEventListener('mousedown', outside);
            opener?.focus?.();
        };
    }, [onClose]);

    const move = (event: React.KeyboardEvent<HTMLDivElement>) => {
        const buttons = Array.from(ref.current?.querySelectorAll<HTMLButtonElement>('button') ?? []);
        const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
        const step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: columns, ArrowUp: -columns }[event.key];
        if (event.key === 'Escape') {
            event.stopPropagation();
            onClose();
        } else if (step !== undefined && index >= 0) {
            event.preventDefault();
            buttons[Math.min(buttons.length - 1, Math.max(0, index + step))]?.focus();
        }
    };

    return (
        <div
            ref={ref}
            role="dialog"
            aria-label={label}
            onKeyDown={move}
            className={`absolute z-30 rounded-control border border-(--app-border) bg-(--app-surface) p-1.5 shadow-lg ${placement === 'above' ? 'bottom-full mb-2' : 'top-full mt-1'} ${align === 'end' ? 'inset-e-0' : 'inset-s-0'}`}
        >
            <div className="grid gap-0.5" style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}>
                {emoji.map((item) => (
                    <button
                        key={item}
                        type="button"
                        onClick={() => onPick(item)}
                        className="flex h-9 w-9 items-center justify-center rounded-md text-lg leading-none hover:bg-(--app-surface-muted) focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-primary"
                    >
                        <span aria-hidden="true">{item}</span>
                        <span className="sr-only">{item}</span>
                    </button>
                ))}
            </div>
        </div>
    );
}
