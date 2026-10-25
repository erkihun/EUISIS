import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import ApplicationLogo from './ApplicationLogo';
import { ChevronDown, ChevronRight, SearchIcon, X } from './NavIcons';
import { useAuth } from '../auth';
import { BRAND, useLocale } from '../i18n';
import { HOME, activeItem, visibleSections, type NavGroup, type NavItem } from '../navigation';
import { Link } from '../router';

const SIDEBAR_GROUPS_STORAGE_KEY = 'core-sidebar-open-groups';

const focusRing = 'focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-(--sidebar-accent)';
const hoverSurface = 'hover:bg-(--sidebar-hover)';
const selectedSurface = 'bg-(--sidebar-accent-wash) text-(--sidebar-accent)';

/**
 * EUISIS Components/AppSidebar.tsx in its Core-workspace mode: brand block,
 * menu filter ("/"), the Core home, labelled sections of collapsible groups
 * (open by default, as in Core), an icon rail with flyouts when collapsed.
 */
export default function AppSidebar({ route, onClose, collapsed = false, onToggleCollapse }: {
    route: string;
    onClose?: () => void;
    collapsed?: boolean;
    onToggleCollapse?: () => void;
}) {
    const { t, locale } = useLocale();
    const { user } = useAuth();
    const instanceId = useId();
    const searchRef = useRef<HTMLInputElement>(null);
    const navRef = useRef<HTMLElement>(null);
    const focusSearchAfterExpand = useRef(false);
    const [query, setQuery] = useState('');
    const normalizedQuery = query.trim().toLocaleLowerCase();

    const sections = visibleSections(Boolean(user?.can_change_master_data));
    const allGroups = sections.flatMap((section) => section.groups);
    const currentItem = activeItem(route, [HOME, ...allGroups.flatMap((group) => group.items)]);
    const activeGroupKeys = allGroups.filter((group) => currentItem && group.items.includes(currentItem)).map((group) => group.key);
    const activeGroupSignature = activeGroupKeys.join(',');

    const [openGroups, setOpenGroups] = useState<Record<string, boolean>>(() => {
        try {
            const parsed: unknown = JSON.parse(window.localStorage.getItem(SIDEBAR_GROUPS_STORAGE_KEY) ?? '{}');
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                return Object.fromEntries(Object.entries(parsed).filter(([, value]) => typeof value === 'boolean'));
            }
        } catch { /* Storage is optional. */ }
        return {};
    });

    useEffect(() => {
        setOpenGroups((previous) => {
            const next = { ...previous };
            for (const key of activeGroupKeys) next[key] = true;
            return next;
        });
        setQuery('');
        // The stable signature avoids reopening a manually collapsed group on every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [route, activeGroupSignature]);

    useEffect(() => {
        navRef.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    }, [route, collapsed]);

    useEffect(() => {
        try {
            window.localStorage.setItem(SIDEBAR_GROUPS_STORAGE_KEY, JSON.stringify(openGroups));
        } catch { /* Navigation remains usable without storage. */ }
    }, [openGroups]);

    useEffect(() => {
        if (collapsed) setQuery('');
        if (!collapsed && focusSearchAfterExpand.current) {
            focusSearchAfterExpand.current = false;
            searchRef.current?.focus();
        }
    }, [collapsed]);

    const matches = (item: NavItem) => t(item.labelKey).toLocaleLowerCase().includes(normalizedQuery);
    const matchingItems = (group: NavGroup) => !normalizedQuery || t(group.labelKey).toLocaleLowerCase().includes(normalizedQuery)
        ? group.items : group.items.filter(matches);
    const matchingGroups = allGroups.filter((group) => matchingItems(group).length > 0);

    // "/" jumps to the menu filter (desktop sidebar only; ignored while typing elsewhere).
    useEffect(() => {
        if (!onToggleCollapse) return;
        function onKey(event: KeyboardEvent) {
            if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
            const target = event.target as HTMLElement | null;
            if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
            if (!navRef.current?.offsetParent) return;
            event.preventDefault();
            if (collapsed) {
                focusSearchAfterExpand.current = true;
                onToggleCollapse?.();
            } else {
                searchRef.current?.focus();
            }
        }
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [collapsed, onToggleCollapse]);

    /** Marks the part of a label that matched the filter, so it is clear why an item is listed. */
    function highlight(text: string): ReactNode {
        const index = normalizedQuery ? text.toLocaleLowerCase().indexOf(normalizedQuery) : -1;
        if (index < 0) return text;
        return <>{text.slice(0, index)}<mark className="rounded-xs bg-(--sidebar-accent-wash) font-semibold text-(--sidebar-fg)">{text.slice(index, index + normalizedQuery.length)}</mark>{text.slice(index + normalizedQuery.length)}</>;
    }

    function renderLink(item: NavItem, { nested = false, compact = collapsed, onNavigate }: { nested?: boolean; compact?: boolean; onNavigate?: () => void } = {}) {
        const selected = currentItem === item;
        const Icon = item.icon;
        const label = t(item.labelKey);
        return (
            <li key={item.path}>
                <Link
                    href={item.path}
                    onClick={() => { onClose?.(); onNavigate?.(); }}
                    aria-current={selected ? 'page' : undefined}
                    aria-label={compact ? label : undefined}
                    title={compact ? label : undefined}
                    className={[
                        'relative flex scroll-my-12 items-center gap-2.5 rounded-md text-sm transition-colors duration-150',
                        focusRing, hoverSurface,
                        compact ? 'mx-auto h-10 w-10 justify-center' : nested ? 'min-h-8 px-2.5 py-1.5' : 'min-h-9 px-2.5 py-2',
                        selected ? selectedSurface + ' font-semibold'
                            : nested ? 'text-(--sidebar-muted) hover:text-(--sidebar-fg)' : 'font-medium text-(--sidebar-fg)',
                    ].join(' ')}
                >
                    {selected && <span aria-hidden="true" className="absolute inset-y-1.5 inset-s-0 w-0.5 rounded-full bg-(--sidebar-accent)" />}
                    <Icon aria-hidden="true" className={nested && !compact ? ['h-4 w-4 shrink-0', selected ? '' : 'opacity-80'].join(' ') : 'h-[18px] w-[18px] shrink-0'} />
                    {!compact && <span className="min-w-0 flex-1 whitespace-normal wrap-break-word leading-snug">{highlight(label)}</span>}
                </Link>
            </li>
        );
    }

    function renderSection(labelKey: string, groups: NavGroup[]) {
        if (!groups.some((group) => matchingItems(group).length)) return null;
        return (
            <div key={labelKey} className="mb-4 last:mb-0">
                {collapsed
                    ? <div className="mx-3 my-2 border-t border-(--sidebar-border)" />
                    : <p className="px-2.5 pb-1.5 pt-1 text-[11px] font-semibold uppercase leading-relaxed tracking-[0.08em] text-(--sidebar-muted)">{t(labelKey)}</p>}
                <div className="space-y-0.5">{groups.map(renderGroup)}</div>
            </div>
        );
    }

    function renderGroup(group: NavGroup) {
        const items = matchingItems(group);
        if (!items.length) return null;
        const Icon = group.icon;
        const selected = activeGroupKeys.includes(group.key);
        // Core is short enough to show every group open until the person collapses one.
        const isOpen = Boolean(normalizedQuery || (openGroups[group.key] ?? true));
        const panelId = instanceId + '-group-' + group.key;
        const label = t(group.labelKey);
        if (group.items.length === 1) {
            return <ul key={group.key}>{renderLink(group.items[0])}</ul>;
        }
        if (collapsed) {
            // The rail stays compact: a group opens as a flyout of its pages instead of expanding the sidebar.
            return (
                <Popover key={group.key}>
                    <PopoverButton title={label} aria-label={label}
                        className={['mx-auto flex h-10 w-10 items-center justify-center rounded-md transition-colors data-open:bg-(--sidebar-hover)', focusRing, hoverSurface,
                            selected ? selectedSurface : 'text-(--sidebar-fg)'].join(' ')}>
                        <Icon className="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
                    </PopoverButton>
                    <PopoverPanel anchor="right start" transition
                        className="z-50 w-64 rounded-lg border border-(--sidebar-border) bg-(--sidebar-bg) p-2 text-(--sidebar-fg) shadow-xl outline-hidden transition duration-100 ease-out [--anchor-gap:12px] [--anchor-padding:8px] data-closed:-translate-x-1 data-closed:opacity-0">
                        {({ close }) => (
                            <>
                                <p className="px-2.5 pb-1.5 pt-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-(--sidebar-muted)">{label}</p>
                                <ul className="space-y-px">{items.map((item) => renderLink(item, { nested: true, compact: false, onNavigate: close }))}</ul>
                            </>
                        )}
                    </PopoverPanel>
                </Popover>
            );
        }
        return (
            <div key={group.key}>
                <button
                    type="button"
                    aria-expanded={isOpen}
                    aria-controls={panelId}
                    onClick={() => { if (!normalizedQuery) setOpenGroups((previous) => ({ ...previous, [group.key]: !isOpen })); }}
                    className={[
                        'flex min-h-9 w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm text-(--sidebar-fg) transition-colors duration-150',
                        focusRing, hoverSurface,
                        selected ? 'font-semibold' : 'font-medium',
                    ].join(' ')}
                >
                    <Icon className={['h-[18px] w-[18px] shrink-0', selected ? 'text-(--sidebar-accent)' : ''].join(' ')} aria-hidden="true" />
                    <span className="min-w-0 flex-1 whitespace-normal wrap-break-word leading-snug">{highlight(label)}</span>
                    <ChevronDown className={['h-3.5 w-3.5 shrink-0 text-(--sidebar-muted) transition-transform', isOpen ? '' : '-rotate-90'].join(' ')} aria-hidden="true" />
                </button>
                <div id={panelId} hidden={!isOpen}>
                    <div className="ms-[19px] mt-0.5 mb-1.5 border-s border-(--sidebar-border) ps-2">
                        <ul className="space-y-px">{items.map((item) => renderLink(item, { nested: true, compact: false }))}</ul>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div data-sidebar className="flex h-full min-h-0 w-full flex-col border-e border-(--sidebar-border) bg-(--sidebar-bg) text-(--sidebar-fg)">
            <div className={collapsed ? 'flex h-16 shrink-0 items-center justify-center border-b border-(--sidebar-border)' : 'app-dot-grid shrink-0 border-b border-(--sidebar-border) px-4 py-3.5'}>
                <div className="flex items-start gap-2">
                    <Link
                        href="/"
                        onClick={onClose}
                        aria-label={BRAND[locale]}
                        title={collapsed ? BRAND[locale] : undefined}
                        className={['flex min-w-0 flex-1 items-center gap-3 rounded-md', focusRing].join(' ')}
                    >
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-(--sidebar-bg) p-1 ring-1 ring-(--sidebar-border)">
                            <ApplicationLogo className="h-full w-full object-contain fill-current" />
                        </span>
                        {!collapsed && (
                            <div className="min-w-0">
                                <p className="whitespace-normal wrap-break-word text-[15px] font-medium leading-snug">{BRAND[locale]}</p>
                                <p className="whitespace-normal wrap-break-word text-[11px] font-semibold uppercase tracking-wide text-(--sidebar-muted)">{t('core.workspaceName')}</p>
                                <p className="mt-1 whitespace-normal wrap-break-word text-xs leading-relaxed text-(--sidebar-muted)">{t('platform.orgName')}</p>
                            </div>
                        )}
                    </Link>
                    {onClose && <button type="button" onClick={onClose} aria-label={t('nav.closeMenu')} className={['flex h-9 w-9 shrink-0 items-center justify-center rounded-md', focusRing, hoverSurface].join(' ')}><X className="h-[18px] w-[18px]" aria-hidden="true" /></button>}
                </div>
            </div>

            <div className={collapsed ? 'px-2 pt-3' : 'px-3 pt-3'}>
                {collapsed ? (
                    <button
                        type="button"
                        aria-label={t('nav.filterMenu')}
                        title={t('nav.filterMenu')}
                        onClick={() => { focusSearchAfterExpand.current = true; onToggleCollapse?.(); }}
                        className={['mx-auto flex h-10 w-10 items-center justify-center rounded-md text-(--sidebar-muted)', focusRing, hoverSurface].join(' ')}
                    ><SearchIcon className="h-[18px] w-[18px]" aria-hidden="true" /></button>
                ) : (
                    <div className="flex items-center gap-2 rounded-md border border-(--sidebar-border) bg-(--sidebar-hover) px-2.5 transition-colors focus-within:border-(--sidebar-accent) focus-within:ring-2 focus-within:ring-(--sidebar-accent-wash)">
                        <SearchIcon className="h-4 w-4 shrink-0 text-(--sidebar-muted)" aria-hidden="true" />
                        <input
                            ref={searchRef}
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={(event) => { if (event.key === 'Escape' && query) { event.preventDefault(); event.stopPropagation(); setQuery(''); } }}
                            aria-label={t('nav.filterMenu')}
                            placeholder={t('nav.filterMenu')}
                            aria-keyshortcuts={onToggleCollapse ? '/' : undefined}
                            className="peer h-9 w-full min-w-0 border-0 bg-transparent p-0 text-sm text-(--sidebar-fg) placeholder:text-(--sidebar-muted) focus:ring-0"
                        />
                        {onToggleCollapse && !query && <kbd aria-hidden="true" className="shrink-0 rounded-sm border border-(--sidebar-border) px-1.5 font-sans text-[10px] font-medium leading-4 text-(--sidebar-muted) peer-focus:hidden">/</kbd>}
                    </div>
                )}
            </div>

            <nav ref={navRef} className="sidebar-scroll min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pb-6 pt-3 [-webkit-mask-image:linear-gradient(to_bottom,transparent,#000_12px,#000_94%,transparent)] mask-[linear-gradient(to_bottom,transparent,#000_12px,#000_94%,transparent)]" aria-label={t('publicSite.mainNavigation')}>
                {(!normalizedQuery || matches(HOME)) && (
                    <ul className="mb-4 space-y-px">{renderLink(HOME)}</ul>
                )}
                {sections.map((section) => renderSection(section.labelKey, section.groups))}
                {normalizedQuery && !matchingGroups.length && !matches(HOME) && (
                    <p role="status" className="px-3 py-5 text-sm leading-relaxed text-(--sidebar-muted)">{t('nav.noResults')}</p>
                )}
            </nav>

            <div className="shrink-0 border-t border-(--sidebar-border) p-2">
                {onToggleCollapse ? (
                    <button type="button" onClick={onToggleCollapse} aria-label={t(collapsed ? 'nav.expandSidebar' : 'nav.collapseSidebar')} title={collapsed ? t('nav.expandSidebar') : undefined}
                        className={['flex min-h-10 items-center gap-3 rounded-md text-sm text-(--sidebar-muted)', focusRing, hoverSurface, collapsed ? 'mx-auto w-10 justify-center' : 'w-full px-2.5'].join(' ')}>
                        <ChevronRight className={collapsed ? 'h-4 w-4' : 'h-4 w-4 rotate-180'} aria-hidden="true" />
                        {!collapsed && <span>{t('nav.collapseSidebar')}</span>}
                    </button>
                ) : <p className="px-3 py-2 text-xs text-(--sidebar-muted)">{t('core.workspaceName')}</p>}
            </div>
        </div>
    );
}
