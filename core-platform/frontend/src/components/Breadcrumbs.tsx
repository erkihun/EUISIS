import { ChevronRight } from './NavIcons';
import { useLocale } from '../i18n';
import { navLocation } from '../navigation';
import { Link } from '../router';

type Crumb = { label: string; href?: string };

/**
 * EUISIS Components/Breadcrumbs.tsx, in the Core workspace: the trail starts at
 * the Core dashboard; the group names the menu section (never a link); a record
 * page under a list ends in "Details".
 */
export default function Breadcrumbs({ route, className = '' }: { route: string; className?: string }) {
    const { t } = useLocale();
    if (route === '/') return null;

    const location = navLocation(route);
    if (!location) return null;
    const { group, item } = location;
    const isCurrent = route === item.path;
    const crumbs: Crumb[] = [
        { label: t('core.nav.dashboard'), href: '/' },
        { label: t(group.labelKey) },
        { label: t(item.labelKey), href: isCurrent ? undefined : item.path },
    ];
    if (!isCurrent) crumbs.push({ label: t('common.details') });

    return (
        <nav aria-label="Breadcrumb" className={'min-w-0 overflow-x-auto text-xs text-(--app-muted-foreground) ' + className}>
            <ol className="flex min-w-max items-center gap-1.5 whitespace-nowrap">
                {crumbs.map((crumb, index) => {
                    const last = index === crumbs.length - 1;
                    return (
                        <li key={index} className="flex min-w-0 items-center gap-1.5">
                            {index > 0 && <ChevronRight className="h-3 w-3 shrink-0 opacity-60" aria-hidden="true" />}
                            {last ? (
                                <span aria-current="page" className="max-w-64 truncate font-medium text-(--app-foreground)">{crumb.label}</span>
                            ) : crumb.href ? (
                                <Link href={crumb.href} className="max-w-48 truncate rounded-sm transition-colors hover:text-primary focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary">
                                    {crumb.label}
                                </Link>
                            ) : (
                                <span className="max-w-48 truncate">{crumb.label}</span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
