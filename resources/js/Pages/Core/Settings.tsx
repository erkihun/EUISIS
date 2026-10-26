import { Head, Link } from '@inertiajs/react';
import type { ComponentType, SVGProps } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FieldRow from '@/Components/settings/FieldRow';
import SettingsCard from '@/Components/settings/SettingsCard';
import SettingsNav, { type SettingsNavGroup } from '@/Components/settings/SettingsNav';
import { CreditCard, GlobeIcon, HashIcon, KeyIcon, PaletteIcon, SettingsIcon, ShieldCheck } from '@/Components/Icons';
import { buttonClassName } from '@/Components/ui';
import { EmptyState } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    sections: { key: string; href: string }[];
}

type NavGroupKey = 'organization' | 'communication' | 'security';

/** Each Core settings section in the System Settings page's groups, with that page's icon for it. */
const SECTIONS: Record<string, { icon: ComponentType<SVGProps<SVGSVGElement>>; group: NavGroupKey }> = {
    general: { icon: SettingsIcon, group: 'organization' },
    localization: { icon: GlobeIcon, group: 'organization' },
    appearance: { icon: PaletteIcon, group: 'organization' },
    id_cards: { icon: CreditCard, group: 'organization' },
    card_templates: { icon: CreditCard, group: 'organization' },
    code_rules: { icon: HashIcon, group: 'organization' },
    security: { icon: ShieldCheck, group: 'security' },
    api: { icon: KeyIcon, group: 'security' },
};

const NAV_GROUPS: NavGroupKey[] = ['organization', 'communication', 'security'];

/**
 * Core › Settings: the settings Core owns, laid out as the System Settings
 * page lays out its sections (the same section list beside the same cards);
 * each row opens the screen that holds the fields.
 */
export default function CoreSettings({ sections }: Props) {
    const { t } = useLocale();
    const known = sections.filter((section) => SECTIONS[section.key] !== undefined);

    const navGroups: SettingsNavGroup[] = NAV_GROUPS.map((group) => ({
        label: t(`settings.navGroups.${group}`),
        items: known.filter((section) => SECTIONS[section.key].group === group).map((section) => ({
            id: section.key,
            label: t(`core.settings.sections.${section.key}.title`),
            icon: SECTIONS[section.key].icon,
            href: section.href,
        })),
    })).filter((group) => group.items.length > 0);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('core.settings.title')} description={t('core.settings.description')} />}>
            <Head title={t('core.settings.title')} />

            {known.length === 0 ? (
                <EmptyState icon={<SettingsIcon className="h-8 w-8" />} title={t('settings.noPermission')} />
            ) : (
                <div className="grid items-start gap-5 lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-8 xl:grid-cols-[14.5rem_minmax(0,1fr)]">
                    <SettingsNav groups={navGroups} active="" onSelect={() => undefined} />
                    <div className="min-w-0 max-w-5xl space-y-6">
                        {navGroups.map((group) => (
                            <SettingsCard key={group.label} title={group.label}>
                                {group.items.map((item) => (
                                    <FieldRow key={item.id} labelId={`core-setting-${item.id}`} label={item.label} description={t(`core.settings.sections.${item.id}.description`)} inline>
                                        <Link href={item.href ?? '#'} aria-labelledby={`core-setting-${item.id}`} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                            {t('core.settings.open')}
                                        </Link>
                                    </FieldRow>
                                ))}
                            </SettingsCard>
                        ))}
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
