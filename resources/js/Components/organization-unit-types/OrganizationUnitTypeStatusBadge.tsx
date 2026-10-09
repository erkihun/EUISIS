import { useLocale } from '@/hooks/useLocale';

interface Props {
    isActive: boolean;
}

export default function OrganizationUnitTypeStatusBadge({ isActive }: Props) {
    const { t } = useLocale();

    return (
        <span
            className={[
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                isActive
                    ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                    : 'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400',
            ].join(' ')}
        >
            {isActive
                ? t('organizationUnitTypes.activeUnitType')
                : t('organizationUnitTypes.inactiveUnitType')}
        </span>
    );
}
