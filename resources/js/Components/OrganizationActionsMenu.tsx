import { useRef, useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { Link, useForm } from '@inertiajs/react';
import { MoreVertical, PencilIcon, TrashIcon, ArchiveIcon, XCircle, Plus } from '@/Components/Icons';
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

export default function OrganizationActionsMenu({ organizationId, can, deletionBlockers = [] }: Props) {
    const { t } = useLocale();
    const [open, setOpen] = useState(false);
    const [menuPosition, setMenuPosition] = useState<{ top: number; left: number } | null>(null);
    const ref = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const deleteForm = useForm({});
    const archiveForm = useForm({});
    const deactivateForm = useForm({});

    const isBlocked = deletionBlockers.length > 0;

    useEffect(() => {
        if (!open) return;

        function handler(e: MouseEvent) {
            const target = e.target as Node;

            if (
                ref.current &&
                !ref.current.contains(target) &&
                menuRef.current &&
                !menuRef.current.contains(target)
            ) {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [open]);

    useEffect(() => {
        if (!open) return;

        function updateMenuPosition() {
            const button = buttonRef.current;

            if (!button) return;

            const rect = button.getBoundingClientRect();
            const menuWidth = 192;
            const gutter = 8;
            const left = Math.max(gutter, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - gutter));

            setMenuPosition({
                top: rect.bottom + 6,
                left,
            });
        }

        updateMenuPosition();
        window.addEventListener('resize', updateMenuPosition);
        window.addEventListener('scroll', updateMenuPosition, true);

        return () => {
            window.removeEventListener('resize', updateMenuPosition);
            window.removeEventListener('scroll', updateMenuPosition, true);
        };
    }, [open]);

    const hasAny = can.update || can.delete || can.archive || can.deactivate || can.createChild;
    if (!hasAny) return null;

    function handleDelete() {
        if (isBlocked) return;
        if (!confirm(t('organizations.deleteConfirm'))) return;
        deleteForm.delete(route('organizations.destroy', organizationId), {
            onSuccess: () => setOpen(false),
        });
    }

    function handleArchive() {
        if (!confirm(t('organizations.archiveConfirm'))) return;
        archiveForm.delete(route('organizations.archive', organizationId), {
            onSuccess: () => setOpen(false),
        });
    }

    function handleDeactivate() {
        if (!confirm(t('organizations.deactivateConfirm'))) return;
        deactivateForm.patch(route('organizations.deactivate', organizationId), {
            onSuccess: () => setOpen(false),
        });
    }

    const blockerReasons = deletionBlockers
        .map((key) => t(`organizations.deletionBlockers.${key}`))
        .join(', ');

    return (
        <div ref={ref} className="relative">
            <button
                ref={buttonRef}
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-slate-500 dark:hover:bg-slate-700 dark:hover:text-slate-300"
                aria-label={t('common.actions')}
            >
                <MoreVertical className="h-4 w-4" />
            </button>

            {open && menuPosition && createPortal(
                <div
                    ref={menuRef}
                    className="fixed z-[1000] w-48 rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-800"
                    style={{ top: menuPosition.top, left: menuPosition.left }}
                >
                    {can.createChild && (
                        <Link
                            href={route('organizations.create') + `?parent=${organizationId}`}
                            className="flex items-center gap-2 whitespace-nowrap px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-700"
                            onClick={() => setOpen(false)}
                        >
                            <Plus className="h-3.5 w-3.5 shrink-0" />
                            {t('organizations.addChild')}
                        </Link>
                    )}
                    {can.update && (
                        <Link
                            href={route('organizations.edit', organizationId)}
                            className="flex items-center gap-2 whitespace-nowrap px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-700"
                            onClick={() => setOpen(false)}
                        >
                            <PencilIcon className="h-3.5 w-3.5 shrink-0" />
                            {t('common.edit')}
                        </Link>
                    )}
                    {can.deactivate && (
                        <button
                            type="button"
                            disabled={deactivateForm.processing}
                            onClick={handleDeactivate}
                            className="flex w-full items-center gap-2 whitespace-nowrap px-3 py-2 text-sm text-amber-700 hover:bg-amber-50 disabled:opacity-60 dark:text-amber-400 dark:hover:bg-amber-900/20"
                        >
                            <XCircle className="h-3.5 w-3.5 shrink-0" />
                            {t('organizations.deactivate')}
                        </button>
                    )}
                    {can.archive && (
                        <button
                            type="button"
                            disabled={archiveForm.processing}
                            onClick={handleArchive}
                            className="flex w-full items-center gap-2 whitespace-nowrap px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-60 dark:text-slate-200 dark:hover:bg-slate-700"
                        >
                            <ArchiveIcon className="h-3.5 w-3.5 shrink-0" />
                            {t('organizations.archive')}
                        </button>
                    )}
                    {can.delete && (
                        <button
                            type="button"
                            disabled={deleteForm.processing || isBlocked}
                            onClick={handleDelete}
                            title={isBlocked ? `${t('organizations.cannotBeDeleted')}: ${blockerReasons}` : undefined}
                            className="flex w-full items-center gap-2 whitespace-nowrap px-3 py-2 text-sm text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:text-red-400 dark:hover:bg-red-900/20"
                        >
                            <TrashIcon className="h-3.5 w-3.5 shrink-0" />
                            {t('common.delete')}
                        </button>
                    )}
                </div>,
                document.body,
            )}
        </div>
    );
}
