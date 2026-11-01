import { Link } from '@inertiajs/react';
import { cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';

export type AccountTab = 'profile' | 'security' | 'sessions';

const tabClass = (active: boolean) => cx(
    'rounded-control px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
    active ? 'bg-primary text-white' : 'border border-(--app-border) bg-(--app-surface) text-(--app-foreground) hover:border-primary/40',
);

/**
 * Profile · Security · Sessions above the applicant's account pages (as the
 * Blade profile had them). On the profile page the first two switch in place
 * (`onSelect`); elsewhere they link back to the profile at #profile / #security.
 */
export default function AccountTabs({ active, onSelect }: { active: AccountTab; onSelect?: (tab: 'profile' | 'security') => void }) {
    const { t } = useLocale();
    const inPlace = (tab: 'profile' | 'security', label: string) => (onSelect
        ? <a href={`#${tab}`} onClick={(event) => { event.preventDefault(); onSelect(tab); }} aria-current={active === tab ? 'page' : undefined} className={tabClass(active === tab)}>{label}</a>
        : <Link href={`${route('court.applicant.profile.edit')}#${tab}`} className={tabClass(false)}>{label}</Link>);

    return (
        <nav className="mb-6 flex flex-wrap gap-2 border-b border-(--app-border) pb-4" aria-label={t('cm.auth.profile_section')}>
            {inPlace('profile', t('cm.auth.profile_section'))}
            {inPlace('security', t('cm.auth.security_section'))}
            <Link href={route('court.applicant.profile.sessions.index')} aria-current={active === 'sessions' ? 'page' : undefined} className={tabClass(active === 'sessions')}>{t('cm.auth.sessions_title')}</Link>
        </nav>
    );
}
