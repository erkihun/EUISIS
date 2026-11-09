import ApplicationLogo from '@/Components/ApplicationLogo';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeToggle from '@/Components/ThemeToggle';
import { useBranding } from '@/hooks/useBranding';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';

/** The system's name, logo and city, as every provider-portal signed-out page shows them. */
export function usePortalBranding(): { systemName: string; systemLogoUrl: string; orgName: string } {
    const { locale } = useLocale();
    const { getString } = useSystemSettings();
    const { brandName: systemName } = useBranding();

    const orgName = locale === 'am'
        ? getString('id_cards.city_name_am', getString('general.organization_name', 'አዲስ አበባ ከተማ አስተዳደር'))
        : getString('id_cards.city_name_en', getString('general.organization_name', 'Addis Ababa City Administration'));

    return { systemName, systemLogoUrl: getString('general.identity_system_logo_url', ''), orgName };
}

export function SystemLogo({ size, onDark = false }: { size: 'sm' | 'lg'; onDark?: boolean }) {
    const { systemName, systemLogoUrl } = usePortalBranding();
    const box = size === 'lg' ? 'h-16 max-w-[180px]' : 'h-8 max-w-[80px]';

    return systemLogoUrl ? (
        <img
            src={systemLogoUrl}
            alt={systemName}
            width={size === 'lg' ? 180 : 80}
            height={size === 'lg' ? 64 : 32}
            decoding="async"
            className={`${box} w-auto object-contain ${size === 'lg' ? 'drop-shadow-lg' : ''}`}
        />
    ) : (
        <ApplicationLogo className={`${box} w-auto ${onDark ? 'fill-white drop-shadow-lg' : 'fill-slate-800 dark:fill-white'}`} />
    );
}

/** Logo (on small screens, where the branding panel is hidden) plus the language and theme switches. */
export function AuthTopBar() {
    const { systemName } = usePortalBranding();

    return (
        <div className="flex items-center justify-between gap-3 px-6 py-5">
            <div className="flex items-center gap-2.5 lg:invisible">
                <SystemLogo size="sm" />
                <span className="text-sm font-bold text-(--app-foreground)">{systemName}</span>
            </div>
            <div className="flex items-center gap-2">
                <LanguageSwitcher />
                <ThemeToggle />
            </div>
        </div>
    );
}
