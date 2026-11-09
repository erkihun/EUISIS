import { Head } from '@inertiajs/react';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardTabs, { useDashboardTab, type DashboardTab } from '@/Components/dashboard/DashboardTabs';
import { KeyIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';
import AccountSection from './Partials/AccountSection';
import DevicesSection from './Partials/DevicesSection';
import SectionCard from './Partials/SectionCard';
import TwoFactorCard from './Partials/TwoFactorCard';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import type { DevicesInfo, Profile, SecurityInfo } from './Partials/types';

/** Remembered across visits, separately from the dashboard's tab. */
const TAB_STORAGE_KEY = 'euisis-profile-tab-v1';

/**
 * Profile settings in three tabs — Account, Security, Devices — so a person
 * lands on what they came for instead of scrolling past the other two. The
 * tab survives the reload that follows a device action, so ending a session
 * leaves the list on screen.
 */
export default function Edit({
    mustVerifyEmail, status, profile, security, devices,
}: PageProps<{ mustVerifyEmail: boolean; status?: string; profile: Profile; security: SecurityInfo; devices: DevicesInfo }>) {
    const { t } = useLocale();

    const deviceCount = devices.supported && devices.sessions.length > 0 ? ` (${devices.sessions.length})` : '';
    const tabs: DashboardTab[] = [
        { id: 'account', label: t('profile.tabAccount') },
        { id: 'security', label: t('profile.tabSecurity') },
        { id: 'devices', label: `${t('profile.tabDevices')}${deviceCount}` },
    ];
    const [activeTab, setActiveTab] = useDashboardTab(tabs, TAB_STORAGE_KEY);

    const panelProps = (id: string) => ({
        role: 'tabpanel' as const,
        id: `profile-panel-${id}`,
        'aria-labelledby': `profile-tab-${id}`,
        tabIndex: 0,
    });

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('profile.title')} description={t('profile.description')} />}>
            <Head title={t('profile.title')} />

            <div className="space-y-6">
                <DashboardTabs tabs={tabs} activeId={activeTab} onChange={setActiveTab} label={t('profile.sectionsLabel')} idPrefix="profile" />

                {activeTab === 'account' && (
                    <div {...panelProps('account')}>
                        <AccountSection profile={profile} security={security} devices={devices} mustVerifyEmail={mustVerifyEmail} status={status} />
                    </div>
                )}

                {activeTab === 'security' && (
                    <div {...panelProps('security')} className="space-y-6">
                        <SectionCard
                            id="profile-password-title"
                            icon={<KeyIcon className="h-4.5 w-4.5" />}
                            title={t('profile.securityPassword')}
                            description={t('profile.passwordHelp')}
                        >
                            <UpdatePasswordForm />
                        </SectionCard>
                        <TwoFactorCard security={security} />
                    </div>
                )}

                {activeTab === 'devices' && (
                    <div {...panelProps('devices')}>
                        <DevicesSection devices={devices} />
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
