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
        <div className={`mx-auto w-full px-4 sm:px-6 lg:px-8 ${narrow ? 'max-w-3xl' : 'max-w-7xl'} ${className}`}>
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
 * The page title block: the same blue gradient band the home page opens with,
 * so every public page has one recognisable face. Home keeps its taller,
 * two-column hero; inner pages get the shorter version of the same thing.
 */
export function PublicPageHeader({ title, description, breadcrumbs, actions, meta, eyebrow }: {
    title: string;
    description?: string;
    breadcrumbs?: Crumb[];
    actions?: ReactNode;
    /** Small metadata line under the title (e.g. a publication date). */
    meta?: ReactNode;
    /** Optional pill above the title, as on the home hero. */
    eyebrow?: string;
}) {
    return (
        <section
            aria-labelledby="page-heading"
            className="relative overflow-hidden bg-gradient-to-br from-blue-700 via-blue-600 to-blue-800 py-10 sm:py-14 dark:from-blue-900 dark:via-blue-800 dark:to-slate-900"
        >
            <div
                className="pointer-events-none absolute inset-0 opacity-[0.04]"
                style={{
                    backgroundImage:
                        'linear-gradient(to right, white 1px, transparent 1px), linear-gradient(to bottom, white 1px, transparent 1px)',
                    backgroundSize: '48px 48px',
                }}
                aria-hidden="true"
            />
            <div className="pointer-events-none absolute -top-24 right-0 h-96 w-96 rounded-full bg-white/10 blur-3xl" aria-hidden="true" />

            <PublicContainer className="relative">
                {breadcrumbs && breadcrumbs.length > 0 && <PublicBreadcrumbs items={breadcrumbs} onDark />}
                <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div className="min-w-0 max-w-3xl">
                        {eyebrow && (
                            <span className="inline-block rounded-full border border-orange-400/30 bg-orange-500/20 px-3 py-1 text-xs font-semibold text-orange-200">
                                {eyebrow}
                            </span>
                        )}
                        <h1
                            id="page-heading"
                            className={`text-3xl font-extrabold leading-tight tracking-tight text-white [overflow-wrap:anywhere] sm:text-4xl ${eyebrow ? 'mt-4' : ''}`}
                        >
                            {title}
                        </h1>
                        {meta && <div className="mt-3 text-sm text-blue-200">{meta}</div>}
                        {description && <p className="mt-4 text-lg leading-relaxed text-blue-100">{description}</p>}
                    </div>
                    {actions && <div className="flex shrink-0 flex-wrap gap-3">{actions}</div>}
                </div>
            </PublicContainer>
        </section>
    );
}

/** Section heading, at the home page's size and weight. */
export const publicHeadingClass = 'text-2xl font-bold text-gray-900 sm:text-3xl dark:text-slate-100';

/**
 * A band of content. Bands alternate `default` (white) and `muted` (gray-50)
 * down the page, at the home page's `py-16 sm:py-20` rhythm.
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
        <section aria-labelledby={title ? headingId : undefined} className={`${background} py-16 sm:py-20 ${className}`}>
            <PublicContainer>
                {(title || action) && (
                    action ? (
                        <div className="mb-10 flex flex-wrap items-end justify-between gap-3">
                            <div className="min-w-0">
                                {title && <h2 id={headingId} className={publicHeadingClass}>{title}</h2>}
                                {description && <p className="mt-3 text-base text-gray-500 dark:text-slate-400">{description}</p>}
                            </div>
                            {action}
                        </div>
                    ) : (
                        <div className="mb-12 text-center">
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
 * The panel card the home page uses for its trust and module grids: a soft
 * long shadow, a gradient rule across the top-left corner and a faint glow
 * behind the icon. Every card on the public site is this card.
 */
export const publicCardClass =
    'group relative overflow-hidden rounded-panel border border-gray-200/80 bg-white/95 p-5 shadow-[0_18px_45px_-28px_rgba(15,23,42,0.45)] transition duration-200 dark:border-slate-800/80 dark:bg-slate-900/95';

/** The same card, raised on hover — for cards that are links. */
export const publicCardInteractiveClass =
    `${publicCardClass} hover:-translate-y-0.5 hover:border-gray-300 hover:shadow-[0_24px_55px_-30px_rgba(15,23,42,0.65)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:hover:border-slate-700`;

/** The decoration inside a public card. Render first among its children. */
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
 * Buttons. The home page's calls to action are chunkier than the admin
 * control size, so the public site gets its own set: filled, outlined, and
 * the white-on-blue pair used inside the gradient header.
 */
const publicButtonBase =
    'inline-flex items-center justify-center gap-2 rounded-card px-6 py-3 text-sm font-bold transition-all focus-visible:outline-none focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-50';

export const publicButtonPrimary =
    `${publicButtonBase} bg-[color:var(--color-primary)] text-white shadow-md hover:bg-[color:var(--color-primary-hover)] focus-visible:ring-[color:var(--color-primary)] dark:bg-blue-500 dark:hover:bg-[color:var(--color-primary-hover)]`;

export const publicButtonSecondary =
    `${publicButtonBase} border border-gray-300 bg-white font-semibold text-gray-700 hover:bg-gray-50 focus-visible:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800`;

/** Filled white button, for use on the gradient page header. */
export const publicButtonOnDark =
    `${publicButtonBase} bg-white text-blue-700 shadow-lg hover:bg-blue-50 focus-visible:ring-white`;

/** Outlined button, for use on the gradient page header. */
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
