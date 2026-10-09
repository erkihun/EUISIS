import { Link, router } from '@inertiajs/react';
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
                className="text-xs font-medium text-gray-600 hover:text-gray-900 dark:text-slate-400 dark:hover:text-slate-200"
            >
                {t('common.view')}
            </Link>
            {unit.can.update && (
                <Link
                    href={route('organization-units.edit', unit.id)}
                    className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                >
                    {t('common.edit')}
                </Link>
            )}
            {unit.can.archive && (
                <button
                    type="button"
                    onClick={handleDelete}
                    className="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                >
                    {t('common.delete')}
                </button>
            )}
        </div>
    );
}
