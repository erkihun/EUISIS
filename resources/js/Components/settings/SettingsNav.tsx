import { ChevronRight } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { cx } from '@euisis/ui';
import { Link } from '@inertiajs/react';
import { useEffect, useRef, type ComponentType, type SVGProps } from 'react';

export type SettingsNavItem = {
    id: string;
    label: string;
    icon: ComponentType<SVGProps<SVGSVGElement>>;
    /** A separate page (such as API Management) rather than a section of this form. */
    href?: string;
    /** A short status shown beside the label, such as "Off" for a disabled channel. */
    status?: string;
};

export type SettingsNavGroup = { label: string; items: SettingsNavItem[] };

type Props = {
    groups: SettingsNavGroup[];
    active: string;
    onSelect: (id: string) => void;
};

/**
 * Section navigation: a grouped list beside the form on wide screens, and a
 * row of scrollable pills above it on phones and tablets.
 */
export default function SettingsNav({ groups, active, onSelect }: Props) {
    const { t } = useLocale();
    const pillsRef = useRef<HTMLUListElement>(null);

    // Keep the active pill in view when the row is scrollable.
    useEffect(() => {
        const list = pillsRef.current;
        const pill = list?.querySelector<HTMLElement>('[aria-current="page"]');
        if (!list || !pill || list.offsetParent === null) return;
        list.scrollTo({ left: pill.offsetLeft - (list.clientWidth - pill.offsetWidth) / 2, behavior: 'smooth' });
    }, [active]);

    const itemClass = (selected: boolean) => cx(
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] transition-colors',
        selected ? 'text-[color:var(--app-foreground)]' : 'text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)]',
    );

    const content = (item: SettingsNavItem, selected: boolean) => (
        <>
            <item.icon aria-hidden="true" className={cx('h-4 w-4 shrink-0', selected && 'text-[color:var(--color-primary)]')} />
            <span className="min-w-0 flex-1 truncate">{item.label}</span>
            {item.status && <span className="shrink-0 rounded px-1.5 text-[11px] font-medium text-[color:var(--app-muted-foreground)] ring-1 ring-inset ring-[color:var(--app-border)]">{item.status}</span>}
            {item.href && <ChevronRight aria-hidden="true" className="h-3.5 w-3.5 shrink-0 opacity-60" />}
        </>
    );

    const render = (item: SettingsNavItem, variant: 'pill' | 'row') => {
        const selected = item.id === active;
        const className = variant === 'pill'
            ? cx(itemClass(selected), 'flex h-9 items-center gap-2 whitespace-nowrap rounded-full border px-3.5 text-sm font-medium',
                selected ? 'border-[color:var(--color-primary)] bg-[color:var(--app-surface)] shadow-sm' : 'border-[color:var(--app-border)] bg-[color:var(--app-surface)]')
            : cx(itemClass(selected), 'relative flex w-full items-center gap-2.5 rounded-[var(--radius-control)] px-3 py-2 text-left text-sm',
                selected ? 'bg-[color:var(--app-surface)] font-semibold shadow-sm ring-1 ring-[color:var(--app-border)]' : 'font-medium hover:bg-[color:var(--app-surface-muted)]');
        return (
            <li key={item.id}>
                {item.href
                    ? <Link href={item.href} className={className}>{content(item, false)}</Link>
                    : <button type="button" onClick={() => onSelect(item.id)} aria-current={selected ? 'page' : undefined} className={className}>{content(item, selected)}</button>}
            </li>
        );
    };

    return (
        <nav aria-label={t('settings.sectionsNav')} className="min-w-0 lg:sticky lg:top-20 lg:self-start">
            <ul ref={pillsRef} className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:-mx-6 sm:px-6 lg:hidden [&::-webkit-scrollbar]:hidden">
                {groups.flatMap((group) => group.items).map((item) => render(item, 'pill'))}
            </ul>
            <div className="hidden space-y-5 lg:block">
                {groups.map((group) => (
                    <div key={group.label}>
                        <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[0.06em] text-[color:var(--app-muted-foreground)]">{group.label}</p>
                        <ul className="space-y-0.5">{group.items.map((item) => render(item, 'row'))}</ul>
                    </div>
                ))}
            </div>
        </nav>
    );
}
