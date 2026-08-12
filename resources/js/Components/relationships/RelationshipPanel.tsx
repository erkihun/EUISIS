import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { useForm, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

type Option = {
    value: string;
    label: string;
};

type TargetOption = {
    id: string;
    name_en: string | null;
    name_am?: string | null;
    code?: string | null;
    office_code?: string | null;
};

export type RelationshipRow = {
    id: string;
    target_type: string;
    target_id: string;
    target: { name_en: string | null; name_am: string | null; code: string | null } | null;
    relationship_type: string;
    relationship_label: string;
    is_primary: boolean;
    effective_from: string | null;
    effective_to: string | null;
    status: string;
    notes_en: string | null;
    notes_am: string | null;
};

type RelationshipOptions = {
    targetTypes: Option[];
    relationshipTypes: Option[];
    statuses: Option[];
    organizations: TargetOption[];
    institutionOffices: TargetOption[];
    organizationUnits: TargetOption[];
};

type Props = {
    rows: RelationshipRow[];
    options: RelationshipOptions;
    storeRoute: string;
    updateRoute?: (id: string) => string;
    deleteRoute?: (id: string) => string;
    canManage: boolean;
    canUpdate?: boolean;
    canDelete?: boolean;
};

const emptyForm = {
    relationship_type: 'functional_reporting',
    target_type: 'organization',
    target_id: '',
    is_primary: false,
    effective_from: '',
    effective_to: '',
    status: 'active',
    notes_en: '',
    notes_am: '',
};

export default function RelationshipPanel({
    rows,
    options,
    storeRoute,
    updateRoute,
    deleteRoute,
    canManage,
    canUpdate = false,
    canDelete = false,
}: Props) {
    const { t, locale } = useLocale();

    function targetName(name_en: string | null | undefined, name_am: string | null | undefined, fallback?: string): string {
        const name = locale === 'am' ? name_am : name_en;
        return name ?? fallback ?? '';
    }
    const form = useForm(emptyForm);
    const [editingId, setEditingId] = useState<string | null>(null);

    const targetOptions = useMemo(() => {
        if (form.data.target_type === 'institution_office') return options.institutionOffices;
        if (form.data.target_type === 'organization_unit') return options.organizationUnits;
        return options.organizations;
    }, [form.data.target_type, options.institutionOffices, options.organizationUnits, options.organizations]);

    function startEdit(row: RelationshipRow) {
        setEditingId(row.id);
        form.setData({
            relationship_type: row.relationship_type,
            target_type: row.target_type,
            target_id: row.target_id,
            is_primary: row.is_primary,
            effective_from: row.effective_from ?? '',
            effective_to: row.effective_to ?? '',
            status: row.status,
            notes_en: row.notes_en ?? '',
            notes_am: row.notes_am ?? '',
        });
    }

    function cancelEdit() {
        setEditingId(null);
        form.reset();
        form.setData(emptyForm);
    }

    function handleDelete(row: RelationshipRow) {
        if (!deleteRoute) return;
        if (!confirm(t('common.cannotUndo'))) return;
        router.delete(deleteRoute(row.id), { preserveScroll: true });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        if (editingId && updateRoute) {
            form.put(updateRoute(editingId), {
                preserveScroll: true,
                onSuccess: () => cancelEdit(),
            });
        } else {
            form.post(storeRoute, { preserveScroll: true });
        }
    }

    const showForm = canManage || (canUpdate && editingId !== null);
    const isEditing = editingId !== null;
    const showActionColumn = canUpdate || canDelete;

    const selectCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    return (
        <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h3 className="font-semibold text-gray-900 dark:text-slate-100">{t('relationships.title')}</h3>
                    <div className="mt-2 space-y-1 text-xs text-gray-500 dark:text-slate-400">
                        <p>{t('relationships.helpStructural')}</p>
                        <p>{t('relationships.helpFunctional')}</p>
                        <p>{t('relationships.helpNoManagement')}</p>
                    </div>
                </div>
            </div>

            <div className="mt-4 overflow-hidden rounded-xl border border-gray-100 dark:border-slate-800">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50 dark:bg-slate-950">
                        <tr>
                            {[
                                t('relationships.type'),
                                t('relationships.relatedTo'),
                                t('relationships.relatedToType'),
                                t('relationships.effectiveFrom'),
                                t('relationships.effectiveTo'),
                                t('relationships.primary'),
                                t('relationships.status'),
                                ...(showActionColumn ? [''] : []),
                            ].map((heading, i) => (
                                <th key={i} className="px-4 py-2 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">
                                    {heading}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td colSpan={showActionColumn ? 8 : 7} className="px-4 py-6 text-center text-sm text-gray-400 dark:text-slate-500">
                                    {t('relationships.noRelationships')}
                                </td>
                            </tr>
                        ) : rows.map((row) => (
                            <tr
                                key={row.id}
                                className={`border-t border-gray-100 dark:border-slate-800 ${editingId === row.id ? 'bg-blue-50 dark:bg-blue-950/30' : ''}`}
                            >
                                <td className="px-4 py-2 text-gray-700 dark:text-slate-200">{t(`relationships.types.${row.relationship_type}`)}</td>
                                <td className="px-4 py-2 text-gray-700 dark:text-slate-200">{targetName(row.target?.name_en, row.target?.name_am, row.target_id)}</td>
                                <td className="px-4 py-2 text-gray-500 dark:text-slate-400">{t(`relationships.targetTypes.${row.target_type}`)}</td>
                                <td className="px-4 py-2 text-gray-500 dark:text-slate-400">
                                    <LocalizedDateDisplay value={row.effective_from} />
                                </td>
                                <td className="px-4 py-2 text-gray-500 dark:text-slate-400">
                                    <LocalizedDateDisplay value={row.effective_to} />
                                </td>
                                <td className="px-4 py-2 text-gray-500 dark:text-slate-400">{row.is_primary ? t('common.yes') : t('common.no')}</td>
                                <td className="px-4 py-2 text-gray-500 dark:text-slate-400">{t(`relationships.statuses.${row.status}`)}</td>
                                {showActionColumn && (
                                    <td className="px-4 py-2">
                                        <div className="flex items-center gap-2">
                                            {canUpdate && updateRoute && (
                                                <button
                                                    type="button"
                                                    onClick={() => editingId === row.id ? cancelEdit() : startEdit(row)}
                                                    className="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
                                                >
                                                    {editingId === row.id ? t('common.cancel') : t('common.edit')}
                                                </button>
                                            )}
                                            {canDelete && deleteRoute && (
                                                <button
                                                    type="button"
                                                    onClick={() => handleDelete(row)}
                                                    className="text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                                                >
                                                    {t('common.delete')}
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-5 space-y-3">
                    <div className="flex items-center justify-between">
                        <h4 className="text-sm font-semibold text-gray-700 dark:text-slate-300">
                            {isEditing ? t('relationships.editRelationship') : t('relationships.addRelationship')}
                        </h4>
                        {isEditing && (
                            <button
                                type="button"
                                onClick={cancelEdit}
                                className="text-xs text-gray-500 hover:underline dark:text-slate-400"
                            >
                                {t('common.cancel')}
                            </button>
                        )}
                    </div>

                    <div className="grid gap-3 md:grid-cols-2">
                        <select
                            value={form.data.relationship_type}
                            onChange={(e) => form.setData('relationship_type', e.target.value)}
                            className={selectCls}
                        >
                            {options.relationshipTypes.map((o) => (
                                <option key={o.value} value={o.value}>{t(`relationships.types.${o.value}`)}</option>
                            ))}
                        </select>

                        <select
                            value={form.data.target_type}
                            onChange={(e) => form.setData('target_type', e.target.value)}
                            className={selectCls}
                            disabled={isEditing}
                        >
                            {options.targetTypes.map((o) => (
                                <option key={o.value} value={o.value}>{t(`relationships.targetTypes.${o.value}`)}</option>
                            ))}
                        </select>

                        <select
                            value={form.data.target_id}
                            onChange={(e) => form.setData('target_id', e.target.value)}
                            className={selectCls}
                            disabled={isEditing}
                        >
                            <option value="">{t('relationships.relatedTo')}</option>
                            {targetOptions.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {targetName(o.name_en, o.name_am, o.code ?? o.office_code ?? o.id)}
                                </option>
                            ))}
                        </select>

                        <select
                            value={form.data.status}
                            onChange={(e) => form.setData('status', e.target.value)}
                            className={selectCls}
                        >
                            {options.statuses.map((o) => (
                                <option key={o.value} value={o.value}>{t(`relationships.statuses.${o.value}`)}</option>
                            ))}
                        </select>

                        <LocalizedDatePicker
                            value={form.data.effective_from}
                            onChange={(v) => form.setData('effective_from', v)}
                            className={selectCls}
                        />

                        <LocalizedDatePicker
                            value={form.data.effective_to}
                            onChange={(v) => form.setData('effective_to', v)}
                            className={selectCls}
                        />
                    </div>

                    <div className="flex items-center justify-between gap-4">
                        <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-slate-300">
                            <input
                                type="checkbox"
                                checked={form.data.is_primary}
                                onChange={(e) => form.setData('is_primary', e.target.checked)}
                                className="rounded border-gray-300"
                            />
                            {t('relationships.primary')}
                        </label>

                        <button
                            type="submit"
                            disabled={form.processing || (!isEditing && form.data.target_id === '')}
                            className="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
                        >
                            {isEditing ? t('common.update') : t('relationships.addRelationship')}
                        </button>
                    </div>
                </form>
            )}
        </section>
    );
}
