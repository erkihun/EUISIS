import { router } from '@inertiajs/react';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import type { OrganizationUnit } from '@/types/organizationUnit';

interface Props {
    unit: OrganizationUnit;
}

/** The ⋮ menu on an organization-unit row: view, edit, and delete (archives after confirmation). */
export default function OrganizationUnitActionsMenu({ unit }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();

    async function handleDelete() {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (!confirmed) return;
        router.post(route('organization-units.archive', unit.id));
    }

    return (
        <div className="flex justify-end">
            <AppActionMenu
                items={[
                    { label: t('common.view'), href: route('organization-units.show', unit.id) },
                    { label: t('common.edit'), href: route('organization-units.edit', unit.id), show: unit.can.update },
                    { label: t('common.delete'), onClick: handleDelete, variant: 'danger', show: unit.can.archive },
                ]}
            />
        </div>
    );
}
