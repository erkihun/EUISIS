import ApplicationLogo from '@/Components/ApplicationLogo';
import AppToaster from '@/Components/ui/AppToaster';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

export default function GuestLayout({ children }: PropsWithChildren) {
    const { locale } = useLocale();
    const { getString } = useSystemSettings();
    const appName = getString('app.short_name', 'AA Employee ID');
    const organizationName = getString('general.organization_name', 'Addis Ababa City Administration');
    const loginMessage = locale === 'am'
        ? getString('general.login_page_message_am')
        : getString('general.login_page_message_en');

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 py-12 dark:bg-slate-950">
            <AppToaster />
            <Link href="/" className="mb-8 flex items-center gap-2.5">
                <ApplicationLogo className="h-8 w-8 fill-slate-800 dark:fill-white" />
                <span className="text-base font-bold tracking-tight text-slate-900 dark:text-white">
                    {appName}
                </span>
            </Link>

            <div className="w-full max-w-sm rounded-panel border border-gray-200 bg-white px-8 py-8 dark:border-slate-800 dark:bg-slate-900">
                {children}
            </div>

            <div className="mt-8 space-y-1 text-center">
                <p className="text-xs text-gray-400 dark:text-slate-600">{organizationName}</p>
                {loginMessage && (
                    <p className="max-w-md text-xs text-gray-500 dark:text-slate-500">{loginMessage}</p>
                )}
            </div>
        </div>
    );
}
