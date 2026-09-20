import { useState, type FormEvent, type SVGProps } from 'react';
import { Link, router } from '@inertiajs/react';
import { Button, Select, SearchInput } from '@euisis/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import {
    ArrowLeftRightIcon,
    CreditCard,
    Inbox,
    Layers,
    ShieldCheck,
    Store,
    Users,
} from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import {
    PublicCardDecor,
    publicCardClass,
    publicCardInteractiveClass,
    publicIconTileClass,
    publicButtonSecondary,
} from './PublicPage';
import { useBilingual } from './bilingual';

export type AnnouncementSummary = {
    id: string;
    slug: string;
    title_en: string;
    title_am: string | null;
    summary_en: string | null;
    summary_am: string | null;
    category: string | null;
    is_featured: boolean;
    published_at: string | null;
    image_url: string | null;
};

export type ServiceSummary = {
    id: string;
    slug: string;
    code: string;
    icon: string | null;
    name_en: string;
    name_am: string | null;
    short_description_en: string | null;
    short_description_am: string | null;
    is_featured: boolean;
    has_details: boolean;
};

/*
 * Service icons come from a fixed set, chosen in the admin by key. The CMS
 * cannot upload an SVG: an SVG is a document that can carry script.
 */
const SERVICE_ICONS: Record<string, (props: SVGProps<SVGSVGElement>) => JSX.Element> = {
    card: CreditCard,
    store: Store,
    transfer: ArrowLeftRightIcon,
    shield: ShieldCheck,
    users: Users,
    layers: Layers,
    inbox: Inbox,
};

export const SERVICE_ICON_KEYS = Object.keys(SERVICE_ICONS);

export function ServiceIcon({ name, className = 'h-5 w-5' }: { name: string | null; className?: string }) {
    const Icon = (name && SERVICE_ICONS[name]) || Layers;
    return <Icon className={className} aria-hidden="true" />;
}

/**
 * One announcement, in the same panel card the home page uses for its grids:
 * gradient rule at the corner, soft long shadow, raised on hover.
 */
export function AnnouncementItem({ item }: { item: AnnouncementSummary }) {
    const pick = useBilingual();
    const { t } = useLocale();
    const title = pick(item, 'title');
    const summary = pick(item, 'summary');

    return (
        <article className={`${publicCardInteractiveClass} h-full`}>
            <PublicCardDecor />
            <Link href={route('public.announcements.show', item.slug)} className="relative flex gap-4 focus:outline-none">
                {item.image_url && (
                    <img
                        src={item.image_url}
                        alt=""
                        loading="lazy"
                        className="hidden h-24 w-32 shrink-0 rounded-card border border-gray-200 object-cover sm:block dark:border-slate-800"
                    />
                )}
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-slate-400">
                        {item.published_at && <LocalizedDateDisplay value={item.published_at} />}
                        {item.category && <span>{item.category}</span>}
                        {item.is_featured && (
                            <span className="font-semibold text-[color:var(--color-primary)]">{t('publicSite.featured')}</span>
                        )}
                    </div>
                    <h3 className="mt-2 text-base font-semibold leading-snug text-gray-900 [overflow-wrap:anywhere] group-hover:text-[color:var(--color-primary)] dark:text-slate-100">
                        {title}
                    </h3>
                    {summary && (
                        <p className="mt-2 line-clamp-3 text-sm leading-relaxed text-gray-600 dark:text-slate-400">{summary}</p>
                    )}
                    <span className="mt-3 inline-block text-sm font-semibold text-[color:var(--color-primary)]">
                        {t('publicSite.readMore')}
                        <span className="sr-only">: {title}</span>
                    </span>
                </div>
            </Link>
        </article>
    );
}

/** One service, in the home page's panel card: icon tile, name, one line. */
export function ServiceItem({ item }: { item: ServiceSummary }) {
    const pick = useBilingual();
    const { t } = useLocale();
    const name = pick(item, 'name');
    const description = pick(item, 'short_description');

    const body = (
        <>
            <PublicCardDecor />
            <div className="relative flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold leading-snug text-gray-900 [overflow-wrap:anywhere] dark:text-slate-100">
                        {name}
                    </h3>
                    {description && (
                        <p className="mt-2 text-xs leading-relaxed text-gray-500 dark:text-slate-400">{description}</p>
                    )}
                    {item.has_details && (
                        <span className="mt-3 inline-block text-sm font-semibold text-[color:var(--color-primary)]">
                            {t('publicSite.viewDetails')}
                            <span className="sr-only">: {name}</span>
                        </span>
                    )}
                </div>
                <span className={publicIconTileClass}>
                    <ServiceIcon name={item.icon} className="h-5 w-5" />
                </span>
            </div>
        </>
    );

    // A service with nothing more to say is not a link to an empty page.
    return item.has_details ? (
        <Link href={route('public.services.show', item.slug)} className={`${publicCardInteractiveClass} block h-full`}>
            {body}
        </Link>
    ) : (
        <div className={`${publicCardClass} h-full`}>{body}</div>
    );
}

/**
 * The one search/filter row for public lists: [Search] [Category] [Apply] [Reset].
 * Submits as a normal GET so results are shareable and survive a reload.
 */
export function PublicSearchBar({ routeName, search, category, categories, searchLabel }: {
    routeName: string;
    search: string;
    category?: string;
    categories?: string[];
    searchLabel: string;
}) {
    const { t } = useLocale();
    const [term, setTerm] = useState(search);
    const [selected, setSelected] = useState(category ?? '');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const query: Record<string, string> = {};
        if (term.trim()) query.search = term.trim();
        if (selected) query.category = selected;
        router.get(route(routeName), query, { preserveScroll: true, preserveState: true });
    };

    const hasFilters = Boolean(search || category);

    return (
        <form role="search" onSubmit={submit} className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <SearchInput
                value={term}
                onChange={setTerm}
                label={searchLabel}
                aria-label={searchLabel}
                placeholder={searchLabel}
                className="sm:max-w-sm sm:flex-1"
            />
            {categories && categories.length > 0 && (
                <Select
                    aria-label={t('publicSite.category')}
                    value={selected}
                    onChange={(event) => setSelected(event.target.value)}
                    className="sm:w-48"
                >
                    <option value="">{t('publicSite.allCategories')}</option>
                    {categories.map((value) => <option key={value} value={value}>{value}</option>)}
                </Select>
            )}
            <div className="flex gap-2">
                <Button type="submit" variant="outline">{t('publicSite.search')}</Button>
                {hasFilters && (
                    <Link href={route(routeName)} className={`${publicButtonSecondary} px-4 py-2`}>
                        {t('publicSite.reset')}
                    </Link>
                )}
            </div>
        </form>
    );
}
