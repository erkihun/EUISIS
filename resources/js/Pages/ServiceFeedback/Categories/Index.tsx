import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { AppDialog, Alert, Button, Card, FormField, Input, Select, TBody, THead, Table, TableCard, Td, Textarea, Th, Tr, actionLinkClassName } from '@euisis/ui';
import { Head, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { useState } from 'react';
import type { FormEvent } from 'react';

type SubmissionType = 'feedback' | 'suggestion' | 'complaint';
type TargetType = 'employee' | 'organization';

type Category = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    description_am: string | null;
    submission_types: SubmissionType[];
    target_types: TargetType[];
    organization_id: string | null;
    organization_name_en: string | null;
    organization_name_am: string | null;
    is_active: boolean;
    sort_order: number;
    is_referenced: boolean;
    can_manage: boolean;
};

type OrganizationOption = { id: string; code: string; name_en: string; name_am: string | null };

type Props = {
    categories: Category[];
    organizations: OrganizationOption[];
    canManageCityWide: boolean;
};

const SUBMISSION_TYPES: Array<{ value: SubmissionType; key: string }> = [
    { value: 'feedback', key: 'typeFeedback' },
    { value: 'suggestion', key: 'typeSuggestion' },
    { value: 'complaint', key: 'typeComplaint' },
];
const TARGET_TYPES: Array<{ value: TargetType; key: string }> = [
    { value: 'employee', key: 'targetEmployee' },
    { value: 'organization', key: 'targetOrganization' },
];

/**
 * Public feedback categories (brief §31–33): city-wide ones apply everywhere;
 * an institution may add its own. A category referenced by submissions is
 * retired, never deleted.
 */
export default function CategoriesIndex({ categories, organizations, canManageCityWide }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState<Category | null | 'new'>(null);

    const name = (row: { name_en: string; name_am: string | null }) => (locale === 'am' && row.name_am ? row.name_am : row.name_en);
    const scopeLabel = (row: Category) =>
        row.organization_id === null ? t('serviceFeedback.cityWide') : (locale === 'am' && row.organization_name_am ? row.organization_name_am : row.organization_name_en) ?? '';

    async function destroy(row: Category) {
        const result = await confirm({ title: t('serviceFeedback.deleteCategoryConfirm'), variant: row.is_referenced ? 'warning' : 'danger' });
        if (!result.confirmed) return;

        router.delete(route('service-feedback.categories.destroy', row.id), { preserveScroll: true });
    }

    const canCreate = canManageCityWide || organizations.length > 0;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('serviceFeedback.categoriesTitle')}
                    description={t('serviceFeedback.categoriesIntro')}
                    actions={canCreate && <Button variant="primary" onClick={() => setEditing('new')}>{t('serviceFeedback.addCategory')}</Button>}
                />
            }
        >
            <Head title={t('serviceFeedback.categoriesTitle')} />

            <div className="space-y-4">
                {!canManageCityWide && <Alert tone="info">{t('serviceFeedback.cityWideNeedsCityRole')}</Alert>}

                {categories.length === 0 ? (
                    <Card><EmptyState title={t('serviceFeedback.noCategories')} /></Card>
                ) : (
                    <TableCard>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('serviceFeedback.categoryCode')}</Th>
                                    <Th>{t('serviceFeedback.category')}</Th>
                                    <Th>{t('serviceFeedback.categoryScope')}</Th>
                                    <Th>{t('serviceFeedback.appliesToTypes')}</Th>
                                    <Th>{t('serviceFeedback.appliesToTargets')}</Th>
                                    <Th>{t('serviceFeedback.filterStatus')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {categories.map((row) => (
                                    <Tr key={row.id}>
                                        <Td><span className="font-mono text-xs">{row.code}</span></Td>
                                        <Td>
                                            <div className="font-medium text-[color:var(--app-foreground)]">{name(row)}</div>
                                            {row.is_referenced && <div className="text-xs text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.categoryReferenced')}</div>}
                                        </Td>
                                        <Td>{scopeLabel(row)}</Td>
                                        <Td>{row.submission_types.map((type) => t(`serviceFeedback.${SUBMISSION_TYPES.find((s) => s.value === type)?.key ?? 'typeFeedback'}`)).join(', ')}</Td>
                                        <Td>{row.target_types.map((target) => t(`serviceFeedback.${TARGET_TYPES.find((s) => s.value === target)?.key ?? 'targetEmployee'}`)).join(', ')}</Td>
                                        <Td><StatusBadge status={row.is_active ? 'active' : 'inactive'} label={t(row.is_active ? 'serviceFeedback.qrActive' : 'serviceFeedback.qrStatusDisabled')} /></Td>
                                        <Td align="right">
                                            {row.can_manage && (
                                                <span className="inline-flex gap-2">
                                                    <button type="button" className={actionLinkClassName({ tone: 'primary' })} onClick={() => setEditing(row)}>{t('common.edit')}</button>
                                                    <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => destroy(row)}>
                                                        {t(row.is_referenced ? 'serviceFeedback.retireCategory' : 'serviceFeedback.deleteCategory')}
                                                    </button>
                                                </span>
                                            )}
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </TableCard>
                )}
            </div>

            {editing !== null && (
                <CategoryDialog
                    record={editing === 'new' ? null : editing}
                    organizations={organizations}
                    canManageCityWide={canManageCityWide}
                    onClose={() => setEditing(null)}
                />
            )}
        </AuthenticatedLayout>
    );
}

function CategoryDialog({ record, organizations, canManageCityWide, onClose }: {
    record: Category | null;
    organizations: OrganizationOption[];
    canManageCityWide: boolean;
    onClose: () => void;
}) {
    const { t, locale } = useLocale();
    const form = useForm({
        code: record?.code ?? '',
        name_en: record?.name_en ?? '',
        name_am: record?.name_am ?? '',
        description_en: record?.description_en ?? '',
        description_am: record?.description_am ?? '',
        submission_types: record?.submission_types ?? (['feedback', 'suggestion', 'complaint'] as SubmissionType[]),
        target_types: record?.target_types ?? (['employee', 'organization'] as TargetType[]),
        organization_id: record?.organization_id ?? (canManageCityWide ? '' : organizations[0]?.id ?? ''),
        is_active: record?.is_active ?? true,
        sort_order: record?.sort_order ?? 0,
    });

    function toggle<T extends string>(list: T[], value: T): T[] {
        return list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        const payload = { ...form.data, organization_id: form.data.organization_id === '' ? null : form.data.organization_id };

        form.transform(() => payload);

        if (record === null) {
            form.post(route('service-feedback.categories.store'), options);
        } else {
            form.put(route('service-feedback.categories.update', record.id), options);
        }
    }

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <AppDialog
            open
            onClose={onClose}
            title={t(record === null ? 'serviceFeedback.addCategory' : 'serviceFeedback.editCategory')}
            maxWidth="lg"
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" form="category-form" variant="primary" loading={form.processing}>{t('common.save')}</Button>
                </>
            }
        >
            <form id="category-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <FormField id="category-code" label={t('serviceFeedback.categoryCode')} error={errors.code} required>
                    {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.code} maxLength={40} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />}
                </FormField>
                <FormField id="category-scope" label={t('serviceFeedback.categoryScope')} error={errors.organization_id}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                            {canManageCityWide && <option value="">{t('serviceFeedback.cityWide')}</option>}
                            {organizations.map((organization) => (
                                <option key={organization.id} value={organization.id}>{locale === 'am' && organization.name_am ? organization.name_am : organization.name_en}</option>
                            ))}
                        </Select>
                    )}
                </FormField>
                <FormField id="category-name-en" label={t('serviceFeedback.serviceNameEn').replace(/.*\(/, t('serviceFeedback.category') + ' (')} error={errors.name_en} required>
                    {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.name_en} maxLength={255} onChange={(e) => form.setData('name_en', e.target.value)} />}
                </FormField>
                <FormField id="category-name-am" label={t('serviceFeedback.serviceNameAm').replace(/.*\(/, t('serviceFeedback.category') + ' (')} error={errors.name_am}>
                    {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.name_am} maxLength={255} onChange={(e) => form.setData('name_am', e.target.value)} />}
                </FormField>
                <FormField id="category-description-en" label={`${t('serviceFeedback.serviceDescription')} (EN)`} error={errors.description_en}>
                    {({ id, describedBy, invalid }) => <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} rows={2} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}
                </FormField>
                <FormField id="category-description-am" label={`${t('serviceFeedback.serviceDescription')} (አማ)`} error={errors.description_am}>
                    {({ id, describedBy, invalid }) => <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} rows={2} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}
                </FormField>

                <fieldset className="space-y-1.5">
                    <legend className="text-sm font-medium text-[color:var(--app-foreground)]">{t('serviceFeedback.appliesToTypes')}</legend>
                    {SUBMISSION_TYPES.map((item) => (
                        <label key={item.value} className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.data.submission_types.includes(item.value)} onChange={() => form.setData('submission_types', toggle(form.data.submission_types, item.value))} className="h-4 w-4 rounded border-[color:var(--app-border-strong)]" />
                            {t(`serviceFeedback.${item.key}`)}
                        </label>
                    ))}
                    {errors.submission_types && <p className="text-sm text-red-600">{errors.submission_types}</p>}
                </fieldset>
                <fieldset className="space-y-1.5">
                    <legend className="text-sm font-medium text-[color:var(--app-foreground)]">{t('serviceFeedback.appliesToTargets')}</legend>
                    {TARGET_TYPES.map((item) => (
                        <label key={item.value} className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.data.target_types.includes(item.value)} onChange={() => form.setData('target_types', toggle(form.data.target_types, item.value))} className="h-4 w-4 rounded border-[color:var(--app-border-strong)]" />
                            {t(`serviceFeedback.${item.key}`)}
                        </label>
                    ))}
                    {errors.target_types && <p className="text-sm text-red-600">{errors.target_types}</p>}
                </fieldset>

                <FormField id="category-sort" label={t('serviceFeedback.sortOrder')} error={errors.sort_order}>
                    {({ id, describedBy, invalid }) => <Input id={id} type="number" min={0} max={9999} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.sort_order} onChange={(e) => form.setData('sort_order', Number(e.target.value))} />}
                </FormField>
                <label className="flex items-center gap-2 self-end pb-2 text-sm">
                    <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="h-4 w-4 rounded border-[color:var(--app-border-strong)]" />
                    {t('serviceFeedback.qrActive')}
                </label>
            </form>
        </AppDialog>
    );
}
