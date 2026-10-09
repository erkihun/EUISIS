import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

/**
 * The public page grid, shared by every public page so width, gutters and
 * rhythm match the home page exactly.
 *
 * Width and gutters are the home page's own (`max-w-7xl px-4 sm:px-6 lg:px-8`)
 * so the header, hero, content and footer all line up on one set of edges.
 */
export function PublicContainer({ children, narrow = false, className = '' }: {
    children: ReactNode;
    /** Reading width for long text (announcement body, service detail). */
    narrow?: boolean;
    className?: string;
}) {
    return (
        <div className={`mx-auto min-w-0 w-full px-4 sm:px-6 lg:px-8 ${narrow ? 'max-w-3xl' : 'max-w-7xl'} ${className}`}>
            {children}
        </div>
    );
}

export type Crumb = { label: string; href?: string };

/** Home → Section → Page. Never shown on Home itself. */
export function PublicBreadcrumbs({ items, onDark = false }: { items: Crumb[]; onDark?: boolean }) {
    const { t } = useLocale();

    const linkClass = onDark
        ? 'text-blue-100 hover:text-white hover:underline'
        : 'hover:text-gray-900 hover:underline dark:hover:text-white';
    const currentClass = onDark ? 'truncate text-white' : 'truncate text-gray-700 dark:text-slate-300';

    return (
        <nav aria-label={t('publicSite.breadcrumb')} className="mb-4">
            <ol className={`flex flex-wrap items-center gap-1 text-sm ${onDark ? 'text-blue-200' : 'text-gray-500 dark:text-slate-400'}`}>
                <li>
                    <Link href="/" className={linkClass}>{t('publicSite.home')}</Link>
                </li>
                {items.map((item, index) => {
                    const last = index === items.length - 1;
                    return (
                        <li key={`${item.label}-${index}`} className="flex min-w-0 items-center gap-1">
                            <span aria-hidden="true" className={onDark ? 'text-blue-300/60' : 'text-gray-300 dark:text-slate-600'}>/</span>
                            {item.href && !last ? (
                                <Link href={item.href} className={linkClass}>{item.label}</Link>
                            ) : (
                                <span aria-current={last ? 'page' : undefined} className={currentClass}>{item.label}</span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

/**
 * The page title block: the home hero's face (navy gradient, grid, glow and
 * accent line; styles in resources/css/public-site.css), shorter than home.
 * On phones the breadcrumb is hidden and the band stays short so the page's
 * task is visible without scrolling; `compact` keeps it short everywhere for
 * task pages such as Verify.
 */
export function PublicPageHeader({ title, description, breadcrumbs, actions, meta, eyebrow, compact = false }: {
    title: string;
    description?: string;
    breadcrumbs?: Crumb[];
    actions?: ReactNode;
    /** Small metadata line under the title (e.g. a publication date). */
    meta?: ReactNode;
    /** Optional pill above the title, as on the home hero. */
    eyebrow?: string;
    /** A shorter band for task pages. */
    compact?: boolean;
}) {
    return (
        <section aria-labelledby="page-heading" className={`public-hero ${compact ? 'py-6 sm:py-10' : 'py-8 sm:py-14'}`}>
            <div className="public-hero-grid" aria-hidden="true" />
            <div className="public-hero-orb" aria-hidden="true" />

            <PublicContainer className="relative">
                {breadcrumbs && breadcrumbs.length > 0 && (
                    <div className="hidden sm:block">
                        <PublicBreadcrumbs items={breadcrumbs} onDark />
                    </div>
                )}
                <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div className="min-w-0 max-w-3xl">
                        {eyebrow && (
                            <span className="public-hero-enter mb-4 inline-block rounded-full border border-orange-400/30 bg-orange-500/20 px-3 py-1 text-xs font-semibold text-orange-200">
                                {eyebrow}
                            </span>
                        )}
                        <h1
                            id="page-heading"
                            className={`public-hero-enter font-extrabold text-white [overflow-wrap:anywhere] ${compact ? 'text-2xl sm:text-3xl' : 'text-[28px] sm:text-4xl'}`}
                        >
                            {title}
                        </h1>
                        {meta && <div className="public-hero-enter mt-3 text-sm text-blue-200">{meta}</div>}
                        {description && <p className="public-hero-enter mt-3 text-base leading-relaxed text-blue-100 sm:mt-4 sm:text-lg">{description}</p>}
                    </div>
                    {actions && <div className="public-hero-enter flex shrink-0 flex-wrap gap-3">{actions}</div>}
                </div>
            </PublicContainer>
        </section>
    );
}

/** Section heading, at the home page's size and weight, with its accent bar. */
export const publicHeadingClass = 'public-heading-accent text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl dark:text-slate-100';

/**
 * A band of content, at the home page's rhythm. Bands alternate `default`
 * (white) and `muted` (gray-50) down the page, as on home.
 *
 * A section with an `action` puts its heading on the left beside that link;
 * otherwise the heading is centred, as on home.
 */
export function PublicSection({ title, description, action, children, id, tone = 'default', className = '' }: {
    title?: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    id?: string;
    tone?: 'default' | 'muted';
    className?: string;
}) {
    const headingId = id ? `${id}-heading` : undefined;
    const background = tone === 'muted' ? 'bg-gray-50 dark:bg-slate-900' : 'bg-white dark:bg-slate-950';

    return (
        <section aria-labelledby={title ? headingId : undefined} className={`${background} border-b border-slate-400/10 py-12 sm:py-20 ${className}`}>
            <PublicContainer>
                {(title || action) && (
                    action ? (
                        <div className="mb-8 flex flex-wrap items-end justify-between gap-3 sm:mb-10">
                            <div className="min-w-0">
                                {title && <h2 id={headingId} className={publicHeadingClass}>{title}</h2>}
                                {description && <p className="mt-3 text-base text-gray-500 dark:text-slate-400">{description}</p>}
                            </div>
                            {action}
                        </div>
                    ) : (
                        <div className="mb-10 text-center sm:mb-12">
                            {title && <h2 id={headingId} className={publicHeadingClass}>{title}</h2>}
                            {description && <p className="mt-3 text-base text-gray-500 dark:text-slate-400">{description}</p>}
                        </div>
                    )
                )}
                {children}
            </PublicContainer>
        </section>
    );
}

/**
 * The home page's trust/module card: a soft shadow, a gradient rule across
 * the top-left corner and a faint glow behind the icon (PublicCardDecor), and
 * a lift on hover. Every card on the public site is this card.
 */
export const publicCardClass =
    'public-card group relative min-w-0 overflow-hidden border border-gray-200/80 bg-white/95 p-5 [overflow-wrap:anywhere] dark:border-slate-800/80 dark:bg-slate-900/95';

/** The same card as a link: it lifts and its border darkens on hover. */
export const publicCardInteractiveClass =
    `${publicCardClass} hover:border-gray-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:hover:border-slate-700`;

/** The decoration inside a public card, as on home. Render first among its children. */
export function PublicCardDecor({ glow = true }: { glow?: boolean }) {
    return (
        <>
            <div
                className="absolute left-0 top-0 h-1 w-1/2 bg-gradient-to-r from-[var(--color-primary)] via-[color:var(--color-primary)]/45 to-transparent"
                style={{ borderTopLeftRadius: 'inherit' }}
                aria-hidden="true"
            />
            {glow && (
                <div
                    className="pointer-events-none absolute -right-10 -top-12 h-28 w-28 rounded-full bg-[color:var(--color-primary)]/10 blur-3xl"
                    aria-hidden="true"
                />
            )}
        </>
    );
}

/** The square icon tile on a card, as on the home grids. */
export const publicIconTileClass =
    'inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-panel bg-[color:var(--color-primary)]/10 text-[color:var(--color-primary)] shadow-sm ring-1 ring-[color:var(--color-primary)]/15 transition duration-200 group-hover:scale-105';

/*
 * Buttons, as on the home page: chunkier than the admin controls, with the
 * white-on-navy pair used inside the hero. At least 44px tall for touch.
 */
const publicButtonBase =
    'inline-flex min-h-[44px] items-center justify-center gap-2 rounded-card px-6 py-3 text-sm font-bold transition-all focus-visible:outline-none focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-50';

export const publicButtonPrimary =
    `${publicButtonBase} bg-[color:var(--color-primary)] text-white shadow-md hover:bg-[color:var(--color-primary-hover)] focus-visible:ring-[color:var(--color-primary)]`;

export const publicButtonSecondary =
    `${publicButtonBase} border border-gray-300 bg-white font-semibold text-gray-700 hover:bg-gray-50 focus-visible:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800`;

/** Filled white button, for use on the hero band (as the home hero's). */
export const publicButtonOnDark =
    `${publicButtonBase} bg-white text-[color:var(--color-primary-700)] shadow-lg hover:bg-blue-50 focus-visible:ring-white`;

/** Outlined button, for use on the hero band (as the home hero's). */
export const publicButtonOnDarkGhost =
    `${publicButtonBase} border border-white/30 bg-white/10 font-semibold text-white backdrop-blur-sm hover:bg-white/20 focus-visible:ring-white`;

/**
 * Renders HTML that PublicSiteContent produced through SafeContentRenderer
 * (Markdown with raw HTML stripped, then HTMLPurifier). That server-side
 * pipeline is the ONLY reason dangerouslySetInnerHTML is acceptable here —
 * never pass this component anything else.
 */
export function RichText({ html, className = '' }: { html: string; className?: string }) {
    if (!html) return null;

    return <div className={`public-prose ${className}`} dangerouslySetInnerHTML={{ __html: html }} />;
}

/**
 * Inline text link for "View all"-style actions. Not a button variant: the
 * button's size padding would indent it away from the text it sits beside.
 */
export const publicLinkClass =
    'inline-flex items-center text-sm font-semibold text-[color:var(--color-primary)] underline-offset-4 hover:underline focus:outline-none focus-visible:underline';

/**
 * A content section of a public page, as delivered by PublicSiteContent.
 * Both languages are always present; the browser picks one (useBilingual).
 */
export type PageSection = {
    key: string;
    title_en: string | null;
    title_am: string | null;
    subtitle_en: string | null;
    subtitle_am: string | null;
    body_html_en: string;
    body_html_am: string;
    options: Record<string, string | number | null>;
};
