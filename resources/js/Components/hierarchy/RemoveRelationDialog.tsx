import Modal from '@/Components/Modal';
import { useForm } from '@inertiajs/react';
import { toast as showToast } from '@/lib/toast';
import { useLocale } from '@/hooks/useLocale';
import type { HierarchyEdge } from '@/Components/hierarchy/types';

import { buttonClassName } from '@/Components/ui';
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
            <div className="border-b border-(--app-border) px-6 py-4">
                <h3 className="text-lg font-semibold text-(--app-foreground)">
                    {t('hierarchyVersions.confirmRemoveRelation')}
                </h3>
            </div>

            <div className="space-y-3 px-6 py-5">
                <p className="text-sm text-(--app-muted-foreground)">
                    {t('hierarchyVersions.removeRelationWarning')}
                </p>
                {relation && (
                    <div className="rounded-panel border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-200">
                        <div>{relation.parent_organization?.name_en ?? '-'}</div>
                        <div className="mt-1">{relation.child_organization?.name_en ?? '-'}</div>
                    </div>
                )}
            </div>

            <div className="flex items-center justify-end gap-3 border-t border-(--app-border) px-6 py-4">
                <button
                    type="button"
                    onClick={onClose}
                    className={buttonClassName({ variant: 'outline' })}
                >
                    {t('common.cancel')}
                </button>
                <button
                    type="button"
                    onClick={confirmRemove}
                    disabled={form.processing}
                    className={buttonClassName({ variant: 'destructive' })}
                >
                    {form.processing ? t('common.saving') : t('hierarchyVersions.removeRelation')}
                </button>
            </div>
        </Modal>
    );
}
