import { useForm } from '@inertiajs/react';
import { ArchiveIcon, PencilIcon, Plus, TrashIcon, XCircle } from '@/Components/Icons';
import { useConfirm } from '@/Components/ConfirmProvider';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import { useLocale } from '@/hooks/useLocale';

/** Keys returned by OrganizationDeletionGuard::reasons(), mapped to i18n labels below. */
type DeletionBlockerKey =
    | 'usedInPublishedHierarchy'
    | 'hasChildOrganizations'
    | 'hasOrganizationUnits'
    | 'hasPositions'
    | 'hasEmployeeAssignments'
    | 'hasOtherReferences';

type Props = {
    organizationId: string;
    can: {
        update: boolean;
        delete: boolean;
        archive: boolean;
        deactivate: boolean;
        createChild: boolean;
    };
    deletionBlockers?: DeletionBlockerKey[];
};

/** The ⋮ menu on an organization row: add child, edit, deactivate, archive and delete (blocked while references exist). */
export default function OrganizationActionsMenu({ organizationId, can, deletionBlockers = [] }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const deleteForm = useForm({});
    const archiveForm = useForm({});
    const deactivateForm = useForm({});

    const isBlocked = deletionBlockers.length > 0;
    const blockerReasons = deletionBlockers.map((key) => t(`organizations.deletionBlockers.${key}`)).join(', ');

    async function handleDelete() {
        if (isBlocked) return;
        if (!(await confirm({ title: t('organizations.deleteConfirm'), variant: 'danger' })).confirmed) return;
        deleteForm.delete(route('organizations.destroy', organizationId));
    }

    async function handleArchive() {
        if (!(await confirm({ title: t('organizations.archiveConfirm'), variant: 'danger' })).confirmed) return;
        archiveForm.delete(route('organizations.archive', organizationId));
    }

    async function handleDeactivate() {
        if (!(await confirm({ title: t('organizations.deactivateConfirm'), variant: 'danger' })).confirmed) return;
        deactivateForm.patch(route('organizations.deactivate', organizationId));
    }

    return (
        <AppActionMenu
            items={[
                { label: t('organizations.addChild'), icon: <Plus className="h-4 w-4" />, href: route('organizations.create') + `?parent=${organizationId}`, show: can.createChild },
                { label: t('common.edit'), icon: <PencilIcon className="h-4 w-4" />, href: route('organizations.edit', organizationId), show: can.update },
                { label: t('organizations.deactivate'), icon: <XCircle className="h-4 w-4" />, onClick: handleDeactivate, variant: 'warning', disabled: deactivateForm.processing, show: can.deactivate },
                { label: t('organizations.archive'), icon: <ArchiveIcon className="h-4 w-4" />, onClick: handleArchive, disabled: archiveForm.processing, show: can.archive },
                {
                    label: t('common.delete'),
                    icon: <TrashIcon className="h-4 w-4" />,
                    onClick: handleDelete,
                    variant: 'danger',
                    disabled: deleteForm.processing || isBlocked,
                    title: isBlocked ? `${t('organizations.cannotBeDeleted')}: ${blockerReasons}` : undefined,
                    show: can.delete,
                },
            ]}
        />
    );
}
