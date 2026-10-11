import { Link, router } from '@inertiajs/react';
import { actionLinkClassName } from '@/Components/ui';
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
                    className={actionLinkClassName()}
                >
                    {t('common.edit')}
                </Link>
            )}
            {type.can.archive && type.is_active && !type.deleted_at && (
                <button
                    type="button"
                    onClick={archive}
                    className={actionLinkClassName({ tone: 'danger' })}
                >
                    {t('common.delete')}
                </button>
            )}
            {type.can.restore && (type.deleted_at !== null || !type.is_active) && (
                <button
                    type="button"
                    onClick={restore}
                    className={actionLinkClassName({ tone: 'success' })}
                >
                    {t('common.restore')}
                </button>
            )}
        </div>
    );
}
