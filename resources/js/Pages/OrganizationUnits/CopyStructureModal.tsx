import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import { useLocale } from '@/hooks/useLocale';

import { cx, selectClassName, Button, FieldError, FormLabel, Input } from '@/Components/ui';
interface OrgOption {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
}

interface UnitOption {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    depth?: number;
    children?: UnitOption[];
}

interface Props {
    show: boolean;
    onClose: () => void;
    organizations: OrgOption[];
}

type FormData = {
    source_organization_id: string;
    source_unit_id: string;
    target_organization_id: string;
    target_parent_unit_id: string;
    copy_positions: boolean;
    copy_functional_relationships: boolean;
    name_prefix: string;
    name_suffix: string;
    status: string;
    effective_from: string;
};

/** Flatten a unit tree into a flat list with depth info for display. */
function flattenUnits(units: UnitOption[], depth = 0): Array<UnitOption & { depth: number }> {
    const result: Array<UnitOption & { depth: number }> = [];
    for (const unit of units) {
        result.push({ ...unit, depth });
        if (unit.children && unit.children.length > 0) {
            result.push(...flattenUnits(unit.children, depth + 1));
        }
    }
    return result;
}

export default function CopyStructureModal({ show, onClose, organizations }: Props) {
    const { t } = useLocale();

    const { data, setData, post, processing, errors, reset } = useForm<FormData>({
        source_organization_id: '',
        source_unit_id: '',
        target_organization_id: '',
        target_parent_unit_id: '',
        copy_positions: false,
        copy_functional_relationships: false,
        name_prefix: '',
        name_suffix: '',
        status: 'active',
        effective_from: '',
    });

    const [sourceUnits, setSourceUnits] = useState<Array<UnitOption & { depth: number }>>([]);
    const [targetUnits, setTargetUnits] = useState<Array<UnitOption & { depth: number }>>([]);
    const [loadingSourceUnits, setLoadingSourceUnits] = useState(false);
    const [loadingTargetUnits, setLoadingTargetUnits] = useState(false);

    // Load source units when source org changes
    useEffect(() => {
        if (!data.source_organization_id) {
            setSourceUnits([]);
            return;
        }
        setLoadingSourceUnits(true);
        fetch(route('organizations.units.tree', data.source_organization_id))
            .then((r) => r.json())
            .then((tree: UnitOption[]) => {
                setSourceUnits(flattenUnits(Array.isArray(tree) ? tree : []));
            })
            .catch(() => setSourceUnits([]))
            .finally(() => setLoadingSourceUnits(false));
    }, [data.source_organization_id]);

    // Load target units when target org changes
    useEffect(() => {
        if (!data.target_organization_id) {
            setTargetUnits([]);
            return;
        }
        setLoadingTargetUnits(true);
        fetch(route('organizations.units.tree', data.target_organization_id))
            .then((r) => r.json())
            .then((tree: UnitOption[]) => {
                setTargetUnits(flattenUnits(Array.isArray(tree) ? tree : []));
            })
            .catch(() => setTargetUnits([]))
            .finally(() => setLoadingTargetUnits(false));
    }, [data.target_organization_id]);

    function handleClose() {
        reset();
        onClose();
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(route('organization-units.copy-structure'), {
            onSuccess: handleClose,
        });
    }

    const inputCls = cx(selectClassName, 'w-full');

    // Units that will be previewed (root source units or selected source unit)
    const previewUnits = data.source_unit_id
        ? sourceUnits.filter((u) => u.id === data.source_unit_id)
        : sourceUnits.filter((u) => u.depth === 0);

    return (
        <Modal show={show} onClose={handleClose} maxWidth="2xl">
            <form onSubmit={handleSubmit} className="p-6">
                <h2 className="text-lg font-semibold text-[color:var(--app-foreground)] mb-5">
                    {t('organizationUnits.copyStructureTitle')}
                </h2>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {/* Source Organization */}
                    <div>
                        <FormLabel htmlFor="source_organization_id">{t('organizationUnits.sourceOrganization')}</FormLabel>
                        <select
                            id="source_organization_id"
                            className={inputCls}
                            value={data.source_organization_id}
                            onChange={(e) => {
                                setData('source_organization_id', e.target.value);
                                setData('source_unit_id', '');
                            }}
                        >
                            <option value="">{t('organizationUnits.selectOrganization')}</option>
                            {organizations.map((org) => (
                                <option key={org.id} value={org.id}>
                                    {org.name_en} ({org.code})
                                </option>
                            ))}
                        </select>
                        <FieldError className="mt-1">{errors.source_organization_id}</FieldError>
                    </div>

                    {/* Source Unit */}
                    <div>
                        <FormLabel htmlFor="source_unit_id">{t('organizationUnits.sourceUnit')}</FormLabel>
                        <select
                            id="source_unit_id"
                            className={inputCls}
                            value={data.source_unit_id}
                            onChange={(e) => setData('source_unit_id', e.target.value)}
                            disabled={!data.source_organization_id || loadingSourceUnits}
                        >
                            <option value="">{t('organizationUnits.allUnits')}</option>
                            {sourceUnits.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {' '.repeat(unit.depth)}{unit.name_en} ({unit.code})
                                </option>
                            ))}
                        </select>
                        <FieldError className="mt-1">{errors.source_unit_id}</FieldError>
                    </div>

                    {/* Target Organization */}
                    <div>
                        <FormLabel htmlFor="target_organization_id">{t('organizationUnits.targetOrganization')}</FormLabel>
                        <select
                            id="target_organization_id"
                            className={inputCls}
                            value={data.target_organization_id}
                            onChange={(e) => {
                                setData('target_organization_id', e.target.value);
                                setData('target_parent_unit_id', '');
                            }}
                        >
                            <option value="">{t('organizationUnits.selectOrganization')}</option>
                            {organizations.map((org) => (
                                <option key={org.id} value={org.id}>
                                    {org.name_en} ({org.code})
                                </option>
                            ))}
                        </select>
                        <FieldError className="mt-1">{errors.target_organization_id}</FieldError>
                    </div>

                    {/* Target Parent Unit */}
                    <div>
                        <FormLabel htmlFor="target_parent_unit_id">{t('organizationUnits.targetParentUnit')}</FormLabel>
                        <select
                            id="target_parent_unit_id"
                            className={inputCls}
                            value={data.target_parent_unit_id}
                            onChange={(e) => setData('target_parent_unit_id', e.target.value)}
                            disabled={!data.target_organization_id || loadingTargetUnits}
                        >
                            <option value="">{t('organizationUnits.noParentUnit')}</option>
                            {targetUnits.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {' '.repeat(unit.depth)}{unit.name_en} ({unit.code})
                                </option>
                            ))}
                        </select>
                        <FieldError className="mt-1">{errors.target_parent_unit_id}</FieldError>
                    </div>

                    {/* Name Prefix */}
                    <div>
                        <FormLabel htmlFor="name_prefix">{t('organizationUnits.namePrefix')}</FormLabel>
                        <Input
                            id="name_prefix"
                            className="w-full"
                            value={data.name_prefix}
                            onChange={(e) => setData('name_prefix', e.target.value)}
                            maxLength={50}
                        />
                        <FieldError className="mt-1">{errors.name_prefix}</FieldError>
                    </div>

                    {/* Name Suffix */}
                    <div>
                        <FormLabel htmlFor="name_suffix">{t('organizationUnits.nameSuffix')}</FormLabel>
                        <Input
                            id="name_suffix"
                            className="w-full"
                            value={data.name_suffix}
                            onChange={(e) => setData('name_suffix', e.target.value)}
                            maxLength={50}
                        />
                        <FieldError className="mt-1">{errors.name_suffix}</FieldError>
                    </div>

                    {/* Status */}
                    <div>
                        <FormLabel htmlFor="status">{t('organizationUnits.allStatuses')}</FormLabel>
                        <select
                            id="status"
                            className={inputCls}
                            value={data.status}
                            onChange={(e) => setData('status', e.target.value)}
                        >
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                        </select>
                        <FieldError className="mt-1">{errors.status}</FieldError>
                    </div>

                    {/* Effective From */}
                    <div>
                        <FormLabel htmlFor="effective_from">{t('organizationUnits.effectiveFrom')}</FormLabel>
                        <Input
                            id="effective_from"
                            type="date"
                            className="w-full"
                            value={data.effective_from}
                            onChange={(e) => setData('effective_from', e.target.value)}
                        />
                        <FieldError className="mt-1">{errors.effective_from}</FieldError>
                    </div>
                </div>

                {/* Toggles */}
                <div className="mt-4 space-y-3">
                    <label className="flex items-center gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            className="rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)]"
                            checked={data.copy_positions}
                            onChange={(e) => setData('copy_positions', e.target.checked)}
                        />
                        <span className="text-sm text-[color:var(--app-foreground)]">
                            {t('organizationUnits.copyPositions')}
                        </span>
                    </label>
                    <label className="flex items-center gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            className="rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)]"
                            checked={data.copy_functional_relationships}
                            onChange={(e) => setData('copy_functional_relationships', e.target.checked)}
                        />
                        <span className="text-sm text-[color:var(--app-foreground)]">
                            {t('organizationUnits.copyFunctionalRelationships')}
                        </span>
                    </label>
                </div>

                {/* Preview */}
                {data.source_organization_id && previewUnits.length > 0 && (
                    <div className="mt-5 rounded-lg border border-blue-100 bg-blue-50 p-4 dark:border-blue-900/30 dark:bg-blue-950/20">
                        <p className="text-xs font-semibold text-blue-700 dark:text-[color:var(--color-primary)] mb-2">
                            {t('organizationUnits.previewUnits')} ({previewUnits.length})
                        </p>
                        <ul className="space-y-1 max-h-40 overflow-y-auto">
                            {previewUnits.map((unit) => (
                                <li
                                    key={unit.id}
                                    className="text-sm text-[color:var(--app-foreground)]"
                                    style={{ paddingLeft: `${unit.depth * 1}rem` }}
                                >
                                    {data.name_prefix}{unit.name_en}{data.name_suffix}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {data.source_organization_id && !data.source_unit_id && previewUnits.length === 0 && !loadingSourceUnits && (
                    <p className="mt-4 text-sm text-[color:var(--app-muted-foreground)]">
                        {t('organizationUnits.allUnitsWillBeCopied')}
                    </p>
                )}

                {/* Actions */}
                <div className="mt-6 flex justify-end gap-3">
                    <Button variant="outline" type="button" onClick={handleClose} disabled={processing}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={processing || !data.source_organization_id || !data.target_organization_id}>
                        {processing ? t('common.saving') : t('organizationUnits.copyStructure')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
