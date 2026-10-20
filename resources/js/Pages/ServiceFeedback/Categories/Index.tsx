import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Alert, Button, Card, FilterField, FilterPanel, Input, Select, TBody, THead, Table, TableCard, Td, Th, Tr } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { useState } from 'react';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import { SUBMISSION_TYPES, TARGET_TYPES, type CategoryRecord, type OrganizationOption } from './Form';

type Category = CategoryRecord & {
    organization_name_en: string | null;
    organization_name_am: string | null;
    is_referenced: boolean;
    can_manage: boolean;
};

type Filters = { search: string; status: string };

type Props = {
    categories: Category[];
    filters: Filters;
    organizations: OrganizationOption[];
    canManageCityWide: boolean;
};

/**
 * Public feedback categories (brief §31–33): city-wide ones apply everywhere;
 * an institution may add its own. A category referenced by submissions is
 * retired, never deleted. Adding and editing happen on their own pages.
 */
export default function CategoriesIndex({ categories, filters, organizations, canManageCityWide }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [values, setValues] = useState<Filters>(filters);
    const filtersActive = Object.values(filters).some(Boolean);

    function submitFilters() {
        router.get(route('service-feedback.categories.index'), values, { preserveState: true, preserveScroll: true, replace: true });
    }

    function resetFilters() {
        const empty: Filters = { search: '', status: '' };
        setValues(empty);
        router.get(route('service-feedback.categories.index'), empty, { preserveScroll: true, replace: true });
    }

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
                    actions={canCreate && <Button as={Link} href={route('service-feedback.categories.create')} variant="primary">{t('serviceFeedback.addCategory')}</Button>}
                />
            }
        >
            <Head title={t('serviceFeedback.categoriesTitle')} />

            <div className="space-y-4">
                {!canManageCityWide && <Alert tone="info">{t('serviceFeedback.cityWideNeedsCityRole')}</Alert>}

                <FilterPanel onSubmit={submitFilters} onReset={resetFilters} hasActiveFilters={filtersActive}>
                    <FilterField label={t('common.search')} wide>
                        <Input
                            type="search"
                            value={values.search}
                            onChange={(event) => setValues({ ...values, search: event.target.value })}
                            placeholder={t('common.search')}
                        />
                    </FilterField>
                    <FilterField label={t('serviceFeedback.filterStatus')}>
                        <Select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            <option value="active">{t('common.active')}</option>
                            <option value="inactive">{t('common.inactive')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                {categories.length === 0 ? (
                    <Card><EmptyState title={t('serviceFeedback.noCategories')} /></Card>
                ) : (
                    <TableCard>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('serviceFeedback.categoryCode')}</Th>
                                    <Th>{t('serviceFeedback.issueCategory')}</Th>
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
                                            <div className="font-medium text-(--app-foreground)">
                                                {row.can_manage ? <Link href={route('service-feedback.categories.edit', row.id)} className="hover:underline">{name(row)}</Link> : name(row)}
                                            </div>
                                            {row.is_referenced && <div className="text-xs text-(--app-muted-foreground)">{t('serviceFeedback.categoryReferenced')}</div>}
                                        </Td>
                                        <Td>{scopeLabel(row)}</Td>
                                        <Td>{row.submission_types.map((type) => t(`serviceFeedback.${SUBMISSION_TYPES.find((s) => s.value === type)?.key ?? 'adminTypeFeedback'}`)).join(', ')}</Td>
                                        <Td>{row.target_types.map((target) => t(`serviceFeedback.${TARGET_TYPES.find((s) => s.value === target)?.key ?? 'targetEmployee'}`)).join(', ')}</Td>
                                        <Td>
                                            <StatusBadge status={row.is_active ? 'active' : 'inactive'} label={t(row.is_active ? 'serviceFeedback.qrActive' : 'serviceFeedback.qrStatusDisabled')} />
                                            {row.is_active && !row.is_public_selectable && <div className="mt-1 text-xs text-(--app-muted-foreground)">{t('serviceFeedback.categoryInternal')}</div>}
                                        </Td>
                                        <Td align="right">
                                            {row.can_manage && (
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.edit'), href: route('service-feedback.categories.edit', row.id) },
                                                            { label: t(row.is_referenced ? 'serviceFeedback.retireCategory' : 'serviceFeedback.deleteCategory'), onClick: () => destroy(row), variant: 'danger' },
                                                        ]}
                                                    />
                                                </div>
                                            )}
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </TableCard>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
