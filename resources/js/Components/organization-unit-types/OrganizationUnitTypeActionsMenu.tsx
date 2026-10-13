import { router } from '@inertiajs/react';
import AppActionMenu from '@/Components/ui/AppActionMenu';
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

/** The ⋮ menu on an organization-unit-type row: edit and delete while the type is live, restore once it is not. */
export default function OrganizationUnitTypeActionsMenu({ type }: Props) {
    const { t } = useLocale();

    function archive() {
        router.post(route('organization-unit-types.archive', type.id), {}, { preserveScroll: true });
    }

    function restore() {
        router.post(route('organization-unit-types.restore', type.id), {}, { preserveScroll: true });
    }

    return (
        <div className="flex justify-end">
            <AppActionMenu
                items={[
                    { label: t('common.edit'), href: route('organization-unit-types.edit', type.id), show: type.can.update && !type.deleted_at },
                    { label: t('common.delete'), onClick: archive, variant: 'danger', show: type.can.archive && type.is_active && !type.deleted_at },
                    { label: t('common.restore'), onClick: restore, show: type.can.restore && (type.deleted_at !== null || !type.is_active) },
                ]}
            />
        </div>
    );
}
