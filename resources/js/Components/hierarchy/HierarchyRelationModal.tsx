import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import { toast as showToast } from '@/lib/toast';
import { useLocale } from '@/hooks/useLocale';
import OrganizationSearchSelect from '@/Components/hierarchy/OrganizationSearchSelect';
import { toDateInput } from '@/lib/dateUtils';
import type { HierarchyEdge, OrganizationOption } from '@/Components/hierarchy/types';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

import { buttonClassName, cx, selectClassName } from '@/Components/ui';
type RelationFormData = {
    parent_organization_id: string;
    child_organization_id: string;
    relationship_type: string;
    effective_from: string;
    effective_to: string;
};

export default function HierarchyRelationModal({
    show,
    mode,
    hierarchyVersionId,
    relationshipTypes,
    organizationOptions,
    relation,
    initialParentId = '',
    onClose,
}: {
    show: boolean;
    mode: 'create' | 'edit';
    hierarchyVersionId: string;
    relationshipTypes: string[];
    organizationOptions: OrganizationOption[];
    relation: HierarchyEdge | null;
    initialParentId?: string;
    onClose: () => void;
}) {
    const { t } = useLocale();
    const form = useForm<RelationFormData>({
        parent_organization_id: '',
        child_organization_id: '',
        relationship_type: relationshipTypes[0] ?? 'reports_to',
        effective_from: '',
        effective_to: '',
    });

    useEffect(() => {
        if (!show) {
            return;
        }

        form.clearErrors();
        form.setData({
            parent_organization_id: relation?.parent_organization_id ?? initialParentId,
            child_organization_id: relation?.child_organization_id ?? '',
            relationship_type: relation?.relationship_type ?? relationshipTypes[0] ?? 'reports_to',
            effective_from: toDateInput(relation?.effective_from),
            effective_to: toDateInput(relation?.effective_to),
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, relation?.id, initialParentId, relationshipTypes]);

    function closeModal(): void {
        form.reset();
        form.clearErrors();
        onClose();
    }

    function submit(): void {
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                closeModal();
            },
            onError: () => {
                showToast.error(t('hierarchyVersions.failedToSaveRelation'));
            },
        };

        if (mode === 'create') {
            form.post(route('hierarchy-versions.edges.store', { hierarchyVersion: hierarchyVersionId }), options);

            return;
        }

        if (relation === null) {
            return;
        }

        form.patch(route('hierarchy-versions.edges.update', {
            hierarchyVersion: hierarchyVersionId,
            organizationEdge: relation.id,
        }), options);
    }

    return (
        <Modal show={show} onClose={closeModal} maxWidth="2xl">
            <div className="border-b border-(--app-border) px-6 py-4">
                <h3 className="text-lg font-semibold text-(--app-foreground)">
                    {mode === 'create' ? t('hierarchyVersions.addRelation') : t('hierarchyVersions.editRelation')}
                </h3>
                <p className="mt-1 text-sm text-(--app-muted-foreground)">
                    {t('hierarchyVersions.previewUpdatesBeforeSaving')}
                </p>
            </div>

            <div className="space-y-4 px-6 py-5">
                <div className="grid gap-4 md:grid-cols-2">
                    <OrganizationSearchSelect
                        hierarchyVersionId={hierarchyVersionId}
                        label={t('hierarchyVersions.parentOrganization')}
                        value={form.data.parent_organization_id}
                        onChange={(value) => form.setData('parent_organization_id', value)}
                        initialOptions={organizationOptions}
                        error={form.errors.parent_organization_id}
                        placeholder={t('hierarchyVersions.selectParentOrganization')}
                    />

                    <OrganizationSearchSelect
                        hierarchyVersionId={hierarchyVersionId}
                        label={t('hierarchyVersions.childOrganization')}
                        value={form.data.child_organization_id}
                        onChange={(value) => form.setData('child_organization_id', value)}
                        initialOptions={organizationOptions}
                        error={form.errors.child_organization_id}
                        placeholder={t('hierarchyVersions.selectChildOrganization')}
                    />
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-(--app-foreground)">
                            {t('hierarchyVersions.relationshipType')}
                        </label>
                        <select
                            value={form.data.relationship_type}
                            onChange={(event) => form.setData('relationship_type', event.target.value)}
                            className={cx(selectClassName, 'w-full')}
                        >
                            {relationshipTypes.map((type) => (
                                <option key={type} value={type}>
                                    {t(`hierarchyVersions.relationshipTypes.${type === 'reports_to' ? 'reportsTo' : type === 'geographically_under' ? 'geographicallyUnder' : type === 'service_scope' ? 'serviceScope' : 'oversight'}`)}
                                </option>
                            ))}
                        </select>
                        {form.errors.relationship_type && <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">{form.errors.relationship_type}</p>}
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-(--app-foreground)">
                            {t('hierarchyVersions.effectiveFrom')}
                        </label>
                        <LocalizedDatePicker
                            value={form.data.effective_from}
                            onChange={(iso) => form.setData('effective_from', iso)}
                            className="w-full rounded-card border border-(--app-border-strong) bg-(--app-surface) px-3 py-2.5 text-sm text-(--app-foreground) focus:border-primary focus:outline-hidden focus:ring-2 focus:ring-primary/20"
                        />
                        {form.errors.effective_from && <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">{form.errors.effective_from}</p>}
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-(--app-foreground)">
                            {t('hierarchyVersions.effectiveTo')}
                        </label>
                        <LocalizedDatePicker
                            value={form.data.effective_to}
                            onChange={(iso) => form.setData('effective_to', iso)}
                            className="w-full rounded-card border border-(--app-border-strong) bg-(--app-surface) px-3 py-2.5 text-sm text-(--app-foreground) focus:border-primary focus:outline-hidden focus:ring-2 focus:ring-primary/20"
                        />
                        {form.errors.effective_to && <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">{form.errors.effective_to}</p>}
                    </div>
                </div>
            </div>

            <div className="flex items-center justify-end gap-3 border-t border-(--app-border) px-6 py-4">
                <button
                    type="button"
                    onClick={closeModal}
                    className={buttonClassName({ variant: 'outline' })}
                >
                    {t('common.cancel')}
                </button>
                <button
                    type="button"
                    onClick={submit}
                    disabled={form.processing}
                    className={buttonClassName({ variant: 'primary' })}
                >
                    {form.processing ? t('common.saving') : t('hierarchyVersions.saveRelation')}
                </button>
            </div>
        </Modal>
    );
}
