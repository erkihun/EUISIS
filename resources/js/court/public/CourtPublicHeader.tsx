import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { buttonClassName } from '@/Components/ui';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import { MenuIcon, X } from '@/Components/Icons';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { useBranding } from '@/hooks/useBranding';
import { useLocale } from '@/hooks/useLocale';
import { CourtEmblem } from '@/court/components/auth/CourtAuthLayout';
import { usePublicText } from '@/court/i18n/usePublicText';
import { useScrollProgress } from '@/court/public/motion';
import type { PageProps } from '@/types';

export interface CourtPublicLink {
    key: string;
    label: string;
    href: string;
}

/**
 * The public site's main navigation, as an administrative court's site lays
 * it out: the court, its hearings, news, publications and the registry. Plain
 * anchors (full visits); the home page's sections are reached by their anchors.
 */
export function useCourtPublicLinks(): CourtPublicLink[] {
    const { t } = usePublicText();
    const home = route('court.landing.home');
    const label = (key: string) => t(`court.publicSite.nav.${key}`);

    return [
        { key: 'home', label: label('home'), href: home },
        { key: 'about', label: label('about'), href: `${home}#about` },
        { key: 'hearings', label: label('hearings'), href: `${home}#hearings` },
        { key: 'news', label: label('news'), href: route('court.public.news') },
        { key: 'publications', label: label('publications'), href: route('court.public.resources') },
        { key: 'contact', label: label('contact'), href: `${home}#contact` },
    ];
}

/** The court's name in the page's language, as the staff sign-in shows it. */
export function useCourtName(): string {
    const { locale } = useLocale();
    const { brandName, brandNameEn, brandNameAm, officialNameEn, officialNameAm } = useBranding();

    return (locale === 'am' ? (officialNameAm || brandNameAm) : (officialNameEn || brandNameEn)) || brandName;
}

function pathOf(href: string): string {
    try {
        return new URL(href, window.location.origin).pathname;
    } catch {
        return href;
    }
}

/** The court's mark: the logo set in System Settings, else the scales of justice. */
export function CourtMark({ size = 'md' }: { size?: 'md' | 'lg' }) {
    const { getString } = useSystemSettings();
    const logoUrl = getString('general.identity_system_logo_url');
    const box = size === 'lg' ? 'h-14 w-14' : 'h-11 w-11';

    return logoUrl ? (
        <img src={logoUrl} alt="" decoding="async" className={`${box} shrink-0 rounded-full bg-white object-contain p-1 ring-1 ring-(--court-brass)/40`} />
    ) : (
        <span aria-hidden="true" className={`${box} flex shrink-0 items-center justify-center rounded-full bg-(--court-ink) text-(--court-brass) ring-1 ring-(--court-brass)/50`}>
            <CourtEmblem className={size === 'lg' ? 'h-9 w-9' : 'h-7 w-7'} />
        </span>
    );
}

/**
 * The public header: a slim bar naming the parent authority, then the court's
 * mark and name, the main navigation, language and theme, and My Portal for
 * the parties. Court staff reach their sign-in directly; it is not offered to
 * the public here. One breakpoint (`xl`) switches to the menu dialog.
 */
export default function CourtPublicHeader({ portalUrl = null, authorityName = null, officeHours = null }: {
    portalUrl?: string | null;
    authorityName?: string | null;
    officeHours?: string | null;
}) {
    const { t } = usePublicText();
    const page = usePage<PageProps>();
    const [menuOpen, setMenuOpen] = useState(false);
    const headerRef = useRef<HTMLElement>(null);
    // A shadow once the page scrolls, and the brass reading-progress line along the header's foot.
    useScrollProgress(headerRef);
    const links = useCourtPublicLinks();
    const courtName = useCourtName();
    const text = (key: string) => t(`court.publicSite.${key}`);
    const path = page.url.split('?')[0].split('#')[0] || '/';
    // With the portal still open here (EUISIS_PORTAL_IN_EUISIS off), parties sign in to it.
    const portal = (page.props as { courtPortal?: { closed: boolean } }).courtPortal;
    const partiesUrl = portalUrl ?? (portal?.closed ? null : route('court.applicant.login'));

    useEffect(() => router.on('navigate', () => setMenuOpen(false)), []);

    useEffect(() => {
        const desktop = window.matchMedia('(min-width: 1280px)');
        const closeOnDesktop = () => { if (desktop.matches) setMenuOpen(false); };
        desktop.addEventListener('change', closeOnDesktop);
        return () => desktop.removeEventListener('change', closeOnDesktop);
    }, []);

    // An anchor of the home page is never "the current page"; the page itself is.
    const isActive = (link: CourtPublicLink) => !link.href.includes('#') && (pathOf(link.href) === path || (link.key === 'news' && path.startsWith('/court/news')));

    const portalButton = (block = false) => partiesUrl ? (
        <a
            href={partiesUrl}
            className={`inline-flex min-h-10 items-center justify-center gap-2 rounded-md bg-(--court-navy) px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-(--court-navy-hover) ${block ? 'w-full' : ''}`}
            title={text('portal.hint')}
        >
            {text('portal.action')}
            <span aria-hidden="true">›</span>
        </a>
    ) : null;

    // Lawyers sign in at /lawyer/login whether or not the parties' portal is open here.
    const lawyerButton = (block = false) => (
        <a
            href={route('court.lawyer.login')}
            className={`inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-(--court-line) px-4 text-sm font-semibold text-(--court-ink) transition hover:bg-(--court-parchment) dark:text-white ${block ? 'w-full' : ''}`}
            title={text('lawyer.hint')}
        >
            {text('lawyer.action')}
        </a>
    );

    return (
        <header ref={headerRef} className="public-header court-header sticky top-0 z-40">
            <span aria-hidden="true" className="court-progress" />
            <div className="bg-(--court-ink) text-[13px] text-slate-300">
                <div className="mx-auto flex h-9 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <span className="truncate font-medium tracking-wide text-(--court-brass-soft)">{authorityName || text('authority')}</span>
                    {officeHours && <span className="hidden truncate sm:block">{text('hero.officeHours')}: {officeHours}</span>}
                </div>
            </div>

            <div className="border-b border-(--court-line) bg-(--court-paper)">
                <div className="mx-auto flex h-20 max-w-7xl items-center gap-4 px-4 sm:px-6 lg:px-8">
                    <a href={route('court.landing.home')} className="flex min-h-11 min-w-0 shrink items-center gap-3 rounded-md xl:max-w-[20rem] 2xl:max-w-[26rem]">
                        <CourtMark />
                        <span className="min-w-0">
                            <span className="block truncate text-[15px] font-semibold leading-tight text-(--court-ink) dark:text-white">{courtName}</span>
                            <span className="mt-0.5 block truncate text-[11px] font-semibold uppercase tracking-[0.18em] text-(--court-brass-strong)">{text('courtKind')}</span>
                        </span>
                    </a>

                    <nav aria-label={text('nav.label')} className="hidden shrink-0 grow justify-center xl:flex">
                        <ul className="flex items-center whitespace-nowrap">
                            {links.map((link) => {
                                const active = isActive(link);
                                return (
                                    <li key={link.key}>
                                        <a
                                            href={link.href}
                                            aria-current={active ? 'page' : undefined}
                                            className={`group relative inline-flex h-20 items-center px-3 text-sm font-medium whitespace-nowrap transition-colors 2xl:px-4 ${active ? 'text-(--court-ink) dark:text-white' : 'text-(--court-muted) hover:text-(--court-ink) dark:hover:text-white'}`}
                                        >
                                            {link.label}
                                            <span aria-hidden="true" className={`court-nav-rule absolute inset-x-3 bottom-0 h-[3px] rounded-t bg-(--court-brass) ${active ? 'is-active' : ''}`} />
                                        </a>
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>

                    <div className="ms-auto hidden shrink-0 items-center gap-2 xl:flex">
                        <LanguageSwitcher />
                        <ThemeToggle />
                        {lawyerButton()}
                        {portalButton()}
                    </div>

                    <button
                        type="button"
                        onClick={() => setMenuOpen(true)}
                        aria-label={text('nav.menu')}
                        aria-haspopup="dialog"
                        aria-expanded={menuOpen}
                        aria-controls="court-public-mobile-menu"
                        className={buttonClassName({ variant: 'ghost', size: 'icon', className: 'ms-auto h-11! w-11! shrink-0 xl:hidden' })}
                    >
                        <MenuIcon className="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <Dialog open={menuOpen} onClose={setMenuOpen} className="relative z-50 xl:hidden">
                <div className="fixed inset-0 bg-black/50" aria-hidden="true" />
                <DialogPanel
                    id="court-public-mobile-menu"
                    className="court-site fixed inset-y-0 right-0 flex w-full max-w-xs flex-col overflow-y-auto overscroll-contain bg-(--court-paper) pb-[env(safe-area-inset-bottom)] shadow-xl"
                >
                    <div className="flex h-20 shrink-0 items-center justify-between gap-2 border-b border-(--court-line) px-4">
                        <DialogTitle className="flex min-w-0 items-center gap-3 text-sm font-semibold text-(--court-ink) dark:text-white">
                            <CourtMark />
                            <span className="truncate">{courtName}</span>
                        </DialogTitle>
                        <button
                            type="button"
                            onClick={() => setMenuOpen(false)}
                            aria-label={text('nav.closeMenu')}
                            className={buttonClassName({ variant: 'ghost', size: 'icon', className: 'h-11! w-11! shrink-0' })}
                        >
                            <X className="h-5 w-5" aria-hidden="true" />
                        </button>
                    </div>

                    <nav aria-label={text('nav.label')} className="flex-1 px-2 py-3">
                        <ul className="space-y-0.5">
                            {links.map((link) => {
                                const active = isActive(link);
                                return (
                                    <li key={link.key}>
                                        <a
                                            href={link.href}
                                            onClick={() => setMenuOpen(false)}
                                            aria-current={active ? 'page' : undefined}
                                            className={`flex min-h-11 items-center rounded-md border-s-[3px] px-3 py-2 text-sm font-medium ${active ? 'border-(--court-brass) bg-(--court-parchment) text-(--court-ink) dark:text-white' : 'border-transparent text-(--court-text) hover:bg-(--court-parchment)'}`}
                                        >
                                            {link.label}
                                        </a>
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>

                    <div className="shrink-0 space-y-3 border-t border-(--court-line) p-4">
                        <div className="flex items-center justify-between gap-2">
                            <LanguageSwitcher />
                            <ThemeToggle />
                        </div>
                        {portalButton(true)}
                        {lawyerButton(true)}
                    </div>
                </DialogPanel>
            </Dialog>
        </header>
    );
}
