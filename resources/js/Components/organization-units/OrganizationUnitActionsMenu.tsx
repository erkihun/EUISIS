import { Link, router } from '@inertiajs/react';
import { actionLinkClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import type { OrganizationUnit } from '@/types/organizationUnit';

interface Props {
    unit: OrganizationUnit;
}

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
        <div className="flex items-center justify-end gap-3">
            <Link
                href={route('organization-units.show', unit.id)}
                className={actionLinkClassName({ tone: 'neutral' })}
            >
                {t('common.view')}
            </Link>
            {unit.can.update && (
                <Link
                    href={route('organization-units.edit', unit.id)}
                    className={actionLinkClassName()}
                >
                    {t('common.edit')}
                </Link>
            )}
            {unit.can.archive && (
                <button
                    type="button"
                    onClick={handleDelete}
                    className={actionLinkClassName({ tone: 'danger' })}
                >
                    {t('common.delete')}
                </button>
            )}
        </div>
    );
}
