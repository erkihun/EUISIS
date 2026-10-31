import type { ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { CourtSeal } from '@/court/public/motion';

/*
 * The court's inner public pages (publications, news, terms, the manual):
 * the page grid and the title band in the court's colours
 * (resources/css/court-public.css), under the same names the pages use.
 */

export function CourtPublicContainer({ children, narrow = false, className = '' }: {
    children: ReactNode;
    /** Reading width for long text. */
    narrow?: boolean;
    className?: string;
}) {
    return (
        <div className={`mx-auto min-w-0 w-full px-4 sm:px-6 lg:px-8 ${narrow ? 'max-w-3xl' : 'max-w-6xl'} ${className}`}>
            {children}
        </div>
    );
}

export type CourtCrumb = { label: string; href?: string };

/** Home → Page, on the title band; plain anchors, as the header's links. */
export function CourtPublicBreadcrumbs({ items }: { items: CourtCrumb[] }) {
    const { t } = useLocale();

    return (
        <nav aria-label={t('publicSite.breadcrumb')} className="mb-5">
            <ol className="flex flex-wrap items-center gap-1.5 text-sm text-slate-300">
                <li><a href={route('court.landing.home')} className="hover:text-white hover:underline">{t('court.publicSite.nav.home')}</a></li>
                {items.map((item, index) => {
                    const last = index === items.length - 1;
                    return (
                        <li key={`${item.label}-${index}`} className="flex min-w-0 items-center gap-1.5">
                            <span aria-hidden="true" className="text-(--court-brass)">›</span>
                            {item.href && !last ? (
                                <a href={item.href} className="hover:text-white hover:underline">{item.label}</a>
                            ) : (
                                <span aria-current={last ? 'page' : undefined} className="truncate text-white">{item.label}</span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

/** The page title block: the court's band, shorter than the home hero. */
export function CourtPublicPageHeader({ title, eyebrow, meta, breadcrumbs, actions }: {
    title: string;
    eyebrow?: string;
    meta?: ReactNode;
    breadcrumbs?: CourtCrumb[];
    actions?: ReactNode;
}) {
    return (
        <section aria-labelledby="page-heading" className="court-band pb-16 pt-10 sm:pb-20 sm:pt-14">
            {/* The home hero's motion, quieter: the passing light and the seal settling at the band's end. */}
            <div aria-hidden="true" className="court-hero-sweep" />
            <CourtSeal className="court-band-seal" />
            <CourtPublicContainer className="relative">
                {breadcrumbs && breadcrumbs.length > 0 && <CourtPublicBreadcrumbs items={breadcrumbs} />}
                <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div className="court-rise min-w-0 max-w-3xl">
                        {eyebrow && <p className="court-eyebrow court-eyebrow-light court-eyebrow-draw mb-4">{eyebrow}</p>}
                        <h1 id="page-heading" className="text-3xl font-semibold tracking-tight text-white wrap-anywhere sm:text-4xl">{title}</h1>
                        {meta && <div className="mt-3 text-sm leading-relaxed text-slate-300">{meta}</div>}
                    </div>
                    {actions && <div className="flex shrink-0 flex-wrap gap-3">{actions}</div>}
                </div>
            </CourtPublicContainer>
        </section>
    );
}

/** The reading card under the band, lifted onto it. */
export const courtPublicCardClass =
    'court-card relative -mt-8 min-w-0 overflow-hidden p-6 shadow-[0_24px_50px_-36px_rgba(12,26,49,0.6)] wrap-anywhere sm:-mt-10 sm:p-10';
