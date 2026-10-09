import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import { toast as showToast } from '@/lib/toast';
import { useLocale } from '@/hooks/useLocale';
import OrganizationSearchSelect from '@/Components/hierarchy/OrganizationSearchSelect';
import { toDateInput } from '@/lib/dateUtils';
import type { HierarchyEdge, OrganizationOption } from '@/Components/hierarchy/types';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';

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
            <div className="border-b border-gray-200 px-6 py-4 dark:border-slate-800">
                <h3 className="text-lg font-semibold text-gray-900 dark:text-slate-100">
                    {mode === 'create' ? t('hierarchyVersions.addRelation') : t('hierarchyVersions.editRelation')}
                </h3>
                <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
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
                        <label className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">
                            {t('hierarchyVersions.relationshipType')}
                        </label>
                        <select
                            value={form.data.relationship_type}
                            onChange={(event) => form.setData('relationship_type', event.target.value)}
                            className="w-full rounded-card border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
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
                        <label className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">
                            {t('hierarchyVersions.effectiveFrom')}
                        </label>
                        <LocalizedDatePicker
                            value={form.data.effective_from}
                            onChange={(iso) => form.setData('effective_from', iso)}
                            className="w-full rounded-card border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                        />
                        {form.errors.effective_from && <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">{form.errors.effective_from}</p>}
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">
                            {t('hierarchyVersions.effectiveTo')}
                        </label>
                        <LocalizedDatePicker
                            value={form.data.effective_to}
                            onChange={(iso) => form.setData('effective_to', iso)}
                            className="w-full rounded-card border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                        />
                        {form.errors.effective_to && <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">{form.errors.effective_to}</p>}
                    </div>
                </div>
            </div>

            <div className="flex items-center justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-slate-800">
                <button
                    type="button"
                    onClick={closeModal}
                    className="rounded-card border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('common.cancel')}
                </button>
                <button
                    type="button"
                    onClick={submit}
                    disabled={form.processing}
                    className="rounded-card bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60"
                >
                    {form.processing ? t('common.saving') : t('hierarchyVersions.saveRelation')}
                </button>
            </div>
        </Modal>
    );
}
