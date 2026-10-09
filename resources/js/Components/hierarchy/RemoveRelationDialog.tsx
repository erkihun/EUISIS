import Modal from '@/Components/Modal';
import { useForm } from '@inertiajs/react';
import { toast as showToast } from '@/lib/toast';
import { useLocale } from '@/hooks/useLocale';
import type { HierarchyEdge } from '@/Components/hierarchy/types';

export default function RemoveRelationDialog({
    show,
    hierarchyVersionId,
    relation,
    onClose,
}: {
    show: boolean;
    hierarchyVersionId: string;
    relation: HierarchyEdge | null;
    onClose: () => void;
}) {
    const { t } = useLocale();
    const form = useForm({});

    function confirmRemove(): void {
        if (relation === null) {
            return;
        }

        form.delete(route('hierarchy-versions.edges.destroy', {
            hierarchyVersion: hierarchyVersionId,
            organizationEdge: relation.id,
        }), {
            preserveScroll: true,
            onSuccess: () => {
                onClose();
            },
            onError: () => {
                showToast.error(t('hierarchyVersions.failedToSaveRelation'));
            },
        });
    }

    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <div className="border-b border-gray-200 px-6 py-4 dark:border-slate-800">
                <h3 className="text-lg font-semibold text-gray-900 dark:text-slate-100">
                    {t('hierarchyVersions.confirmRemoveRelation')}
                </h3>
            </div>

            <div className="space-y-3 px-6 py-5">
                <p className="text-sm text-gray-600 dark:text-slate-300">
                    {t('hierarchyVersions.removeRelationWarning')}
                </p>
                {relation && (
                    <div className="rounded-panel border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-200">
                        <div>{relation.parent_organization?.name_en ?? '-'}</div>
                        <div className="mt-1">{relation.child_organization?.name_en ?? '-'}</div>
                    </div>
                )}
            </div>

            <div className="flex items-center justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-slate-800">
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-card border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('common.cancel')}
                </button>
                <button
                    type="button"
                    onClick={confirmRemove}
                    disabled={form.processing}
                    className="rounded-card bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
                >
                    {form.processing ? t('common.saving') : t('hierarchyVersions.removeRelation')}
                </button>
            </div>
        </Modal>
    );
}
