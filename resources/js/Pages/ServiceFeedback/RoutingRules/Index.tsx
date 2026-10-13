import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Alert, Button, Card, FilterField, FilterPanel, Select, TBody, THead, Table, TableCard, Td, Th, Tr } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { useState } from 'react';
import AppActionMenu from '@/Components/ui/AppActionMenu';
import type { CategoryOption, HandlerType, OrganizationOption, RuleRecord } from './Form';

type NamePair = { en: string; am: string | null } | null;

type Rule = RuleRecord & {
    organization: NamePair;
    category: NamePair;
    handler_name: string | null;
    handler_name_am: string | null;
    referenced: boolean;
    can_manage: boolean;
};

type Filters = { status: string; organization_id: string };
type Props = { rules: Rule[]; filters: Filters; organizations: OrganizationOption[]; categories: CategoryOption[]; canManageCityWide: boolean };

const HANDLER_TYPE_KEY: Record<HandlerType, string> = { user: 'handlerUser', organization_unit: 'handlerUnit', role: 'handlerRole' };

/**
 * Routing rules (brief §45–47): organization × target × type × category →
 * handler. Most specific rule wins, then the lowest priority number. A rule
 * that already routed something is retired, never deleted. Adding and editing
 * happen on their own pages.
 */
export default function RoutingRulesIndex({ rules, filters, organizations, canManageCityWide }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [values, setValues] = useState<Filters>(filters);
    const filtersActive = Object.values(filters).some(Boolean);

    function submitFilters() {
        router.get(route('service-feedback.routing-rules.index'), values, { preserveState: true, preserveScroll: true, replace: true });
    }

    function resetFilters() {
        const empty: Filters = { status: '', organization_id: '' };
        setValues(empty);
        router.get(route('service-feedback.routing-rules.index'), empty, { preserveScroll: true, replace: true });
    }

    const pair = (value: NamePair, fallback: string) => (value === null ? fallback : locale === 'am' && value.am ? value.am : value.en);

    async function destroy(row: Rule) {
        const result = await confirm({ title: t('serviceFeedback.deleteRuleConfirm'), variant: row.referenced ? 'warning' : 'danger' });
        if (!result.confirmed) return;

        router.delete(route('service-feedback.routing-rules.destroy', row.id), { preserveScroll: true });
    }

    const canCreate = canManageCityWide || organizations.length > 0;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('serviceFeedback.routingRulesTitle')}
                    description={t('serviceFeedback.routingRulesIntro')}
                    actions={canCreate && <Button as={Link} href={route('service-feedback.routing-rules.create')} variant="primary">{t('serviceFeedback.addRule')}</Button>}
                />
            }
        >
            <Head title={t('serviceFeedback.routingRulesTitle')} />

            <div className="space-y-4">
                {!canManageCityWide && <Alert tone="info">{t('serviceFeedback.cityRulesNeedCityRole')}</Alert>}
                <Alert tone="info">{t('serviceFeedback.routingPrecedence')}</Alert>

                <FilterPanel onSubmit={submitFilters} onReset={resetFilters} hasActiveFilters={filtersActive}>
                    <FilterField label={t('serviceFeedback.categoryScope')}>
                        <Select value={values.organization_id} onChange={(event) => setValues({ ...values, organization_id: event.target.value })}>
                            <option value="">{t('common.all')}</option>
                            {organizations.map((organization) => (
                                <option key={organization.id} value={organization.id}>
                                    {locale === 'am' && organization.name_am ? organization.name_am : organization.name_en}
                                </option>
                            ))}
                        </Select>
                    </FilterField>
                    <FilterField label={t('serviceFeedback.filterStatus')}>
                        <Select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })}>
                            <option value="">{t('common.allStatuses')}</option>
                            <option value="active">{t('common.active')}</option>
                            <option value="inactive">{t('common.inactive')}</option>
                        </Select>
                    </FilterField>
                </FilterPanel>

                {rules.length === 0 ? (
                    <Card><EmptyState title={t('serviceFeedback.noRules')} description={t('serviceFeedback.noRulesHint')} /></Card>
                ) : (
                    <TableCard>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('serviceFeedback.categoryScope')}</Th>
                                    <Th>{t('serviceFeedback.matches')}</Th>
                                    <Th>{t('serviceFeedback.handler')}</Th>
                                    <Th align="right">{t('serviceFeedback.priority')}</Th>
                                    <Th>{t('serviceFeedback.effectiveWindow')}</Th>
                                    <Th>{t('serviceFeedback.filterStatus')}</Th>
                                    <Th align="right">{t('common.actions')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {rules.map((row) => (
                                    <Tr key={row.id}>
                                        <Td>
                                            <div className="font-medium text-[color:var(--app-foreground)]">
                                                {row.can_manage
                                                    ? <Link href={route('service-feedback.routing-rules.edit', row.id)} className="hover:underline">{pair(row.organization, t('serviceFeedback.cityWide'))}</Link>
                                                    : pair(row.organization, t('serviceFeedback.cityWide'))}
                                            </div>
                                        </Td>
                                        <Td>
                                            <div className="text-sm">{row.target_type ? t(row.target_type === 'organization' ? 'serviceFeedback.targetOrganization' : 'serviceFeedback.targetEmployee') : t('serviceFeedback.anyTargetType')} · {row.submission_type ? t(row.submission_type === 'complaint' ? 'serviceFeedback.typeComplaint' : 'serviceFeedback.typeSuggestion') : t('serviceFeedback.anySubmissionType')}</div>
                                            <div className="text-xs text-[color:var(--app-muted-foreground)]">{pair(row.category, t('serviceFeedback.anyCategory'))}</div>
                                        </Td>
                                        <Td>
                                            <div className="font-medium">{locale === 'am' && row.handler_name_am ? row.handler_name_am : row.handler_name ?? '—'}</div>
                                            <div className="text-xs text-[color:var(--app-muted-foreground)]">{t(`serviceFeedback.${HANDLER_TYPE_KEY[row.handler_type]}`)}{row.referenced && <> · {t('serviceFeedback.ruleReferenced')}</>}</div>
                                        </Td>
                                        <Td align="right" numeric>{row.priority}</Td>
                                        <Td>
                                            {row.effective_from || row.effective_to
                                                ? <span className="text-sm"><LocalizedDateDisplay value={row.effective_from} /> → <LocalizedDateDisplay value={row.effective_to} /></span>
                                                : <span className="text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.alwaysInEffect')}</span>}
                                        </Td>
                                        <Td><StatusBadge status={row.is_active ? 'active' : 'inactive'} label={t(row.is_active ? 'serviceFeedback.qrActive' : 'serviceFeedback.qrStatusDisabled')} /></Td>
                                        <Td align="right">
                                            {row.can_manage && (
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.edit'), href: route('service-feedback.routing-rules.edit', row.id) },
                                                            { label: t(row.referenced ? 'serviceFeedback.retireRule' : 'serviceFeedback.deleteRule'), onClick: () => destroy(row), variant: 'danger' },
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
