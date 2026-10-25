import type { ReactNode } from 'react';
import { useAuth } from '../auth';
import { useI18n } from '../i18n';
import type { MessageKey } from '../lang/en';

const links: { path: string; label: MessageKey; writer?: boolean }[] = [
    { path: '/', label: 'nav.dashboard' },
    { path: '/organizations', label: 'nav.organizations' },
    { path: '/units', label: 'nav.units' },
    { path: '/positions', label: 'nav.positions' },
    { path: '/employees', label: 'nav.employees' },
    { path: '/employees/new', label: 'nav.register', writer: true },
    { path: '/assignments', label: 'nav.assignments' },
    { path: '/cards', label: 'nav.cards' },
    { path: '/verify', label: 'nav.verify' },
    { path: '/audit', label: 'nav.audit' },
];

function isActive(path: string, route: string): boolean {
    if (path === '/') return route === '/';
    if (path === '/employees') return route === '/employees' || (route.startsWith('/employees/') && route !== '/employees/new');
    return route === path || route.startsWith(`${path}/`);
}

export function Layout({ route, children }: { route: string; children: ReactNode }) {
    const { user, logout } = useAuth();
    const { t, toggle } = useI18n();
    const visible = links.filter((link) => !link.writer || user?.can_change_master_data);

    return (
        <div className="min-h-screen bg-canvas text-ink md:flex">
            <aside className="border-b border-line bg-surface md:sticky md:top-0 md:h-screen md:w-60 md:shrink-0 md:border-r md:border-b-0">
                <div className="px-4 py-4">
                    <a href="#/" className="block text-lg font-semibold text-brand">
                        {t('app.name')}
                    </a>
                    <p className="mt-1 hidden text-xs text-muted md:block">{t('app.tagline')}</p>
                </div>
                <nav className="flex gap-1 overflow-x-auto px-2 pb-2 md:flex-col md:overflow-visible" aria-label="Main">
                    {visible.map((link) => (
                        <a
                            key={link.path}
                            href={`#${link.path}`}
                            aria-current={isActive(link.path, route) ? 'page' : undefined}
                            className="rounded-md px-3 py-2 text-sm whitespace-nowrap text-ink hover:bg-subtle aria-[current=page]:bg-brand/10 aria-[current=page]:font-semibold aria-[current=page]:text-brand"
                        >
                            {t(link.label)}
                        </a>
                    ))}
                </nav>
            </aside>

            <div className="min-w-0 flex-1">
                <header className="flex flex-wrap items-center justify-end gap-3 border-b border-line bg-surface px-4 py-2 text-sm">
                    {user && (
                        <span className="text-muted">
                            <span className="font-medium text-ink">{user.name}</span> · {t(`role.${user.role}`)} · {user.scope_organization_id ? t('common.scoped') : t('common.wholeCity')}
                        </span>
                    )}
                    <button type="button" onClick={toggle} className="rounded-md border border-line px-2 py-1 text-xs hover:bg-subtle">
                        {t('lang.switch')}
                    </button>
                    <button type="button" onClick={() => void logout()} className="rounded-md px-2 py-1 text-xs text-danger hover:bg-danger-soft">
                        {t('auth.signOut')}
                    </button>
                </header>
                <main className="mx-auto max-w-6xl px-4 py-6">{children}</main>
            </div>
        </div>
    );
}
