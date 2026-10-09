import { Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type UnitType = {
    id: string;
    is_active: boolean;
    deleted_at: string | null;
    can: { update: boolean; archive: boolean; restore: boolean };
};

interface Props {
    type: UnitType;
}

export default function OrganizationUnitTypeActionsMenu({ type }: Props) {
    const { t } = useLocale();

    function archive() {
        router.post(route('organization-unit-types.archive', type.id), {}, { preserveScroll: true });
    }

    function restore() {
        router.post(route('organization-unit-types.restore', type.id), {}, { preserveScroll: true });
    }

    return (
        <div className="flex items-center gap-3">
            {type.can.update && !type.deleted_at && (
                <Link
                    href={route('organization-unit-types.edit', type.id)}
                    className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                >
                    {t('common.edit')}
                </Link>
            )}
            {type.can.archive && type.is_active && !type.deleted_at && (
                <button
                    type="button"
                    onClick={archive}
                    className="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                >
                    {t('common.delete')}
                </button>
            )}
            {type.can.restore && (type.deleted_at !== null || !type.is_active) && (
                <button
                    type="button"
                    onClick={restore}
                    className="text-xs font-medium text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300"
                >
                    {t('common.restore')}
                </button>
            )}
        </div>
    );
}
