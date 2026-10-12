import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { AppDialog, Alert, Button, Card, FormField, Input, Select, TBody, THead, Table, TableCard, Td, Th, Tr, actionLinkClassName } from '@euisis/ui';
import { Head, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';

type HandlerType = 'user' | 'organization_unit' | 'role';
type NamePair = { en: string; am: string | null } | null;

type Rule = {
    id: string;
    organization_id: string | null;
    organization: NamePair;
    target_type: 'employee' | 'organization' | null;
    submission_type: 'suggestion' | 'complaint' | null;
    category_id: string | null;
    category: NamePair;
    handler_type: HandlerType;
    handler_id: string;
    handler_name: string | null;
    handler_name_am: string | null;
    priority: number;
    effective_from: string | null;
    effective_to: string | null;
    is_active: boolean;
    referenced: boolean;
    can_manage: boolean;
};
type OrganizationOption = { id: string; code: string; name_en: string; name_am: string | null };
type CategoryOption = { id: string; organization_id: string | null; name_en: string; name_am: string | null };
type HandlerOptions = { users: { id: string; name: string }[]; units: { id: string; name: string; name_am: string | null }[]; roles: { id: string; name: string }[] };

type Props = { rules: Rule[]; organizations: OrganizationOption[]; categories: CategoryOption[]; canManageCityWide: boolean };

const HANDLER_TYPE_KEY: Record<HandlerType, string> = { user: 'handlerUser', organization_unit: 'handlerUnit', role: 'handlerRole' };

/**
 * Routing rules (brief §45–47): organization × target × type × category →
 * handler. Most specific rule wins, then the lowest priority number. A rule
 * that already routed something is retired, never deleted.
 */
export default function RoutingRulesIndex({ rules, organizations, categories, canManageCityWide }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState<Rule | null | 'new'>(null);

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
                    actions={canCreate && <Button variant="primary" onClick={() => setEditing('new')}>{t('serviceFeedback.addRule')}</Button>}
                />
            }
        >
            <Head title={t('serviceFeedback.routingRulesTitle')} />

            <div className="space-y-4">
                {!canManageCityWide && <Alert tone="info">{t('serviceFeedback.cityRulesNeedCityRole')}</Alert>}
                <Alert tone="info">{t('serviceFeedback.routingPrecedence')}</Alert>

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
                                        <Td><div className="font-medium text-[color:var(--app-foreground)]">{pair(row.organization, t('serviceFeedback.cityWide'))}</div></Td>
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
                                                <span className="inline-flex gap-2">
                                                    <button type="button" className={actionLinkClassName({ tone: 'primary' })} onClick={() => setEditing(row)}>{t('common.edit')}</button>
                                                    <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => destroy(row)}>
                                                        {t(row.referenced ? 'serviceFeedback.retireRule' : 'serviceFeedback.deleteRule')}
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
                <RuleDialog
                    record={editing === 'new' ? null : editing}
                    organizations={organizations}
                    categories={categories}
                    canManageCityWide={canManageCityWide}
                    onClose={() => setEditing(null)}
                />
            )}
        </AuthenticatedLayout>
    );
}

function RuleDialog({ record, organizations, categories, canManageCityWide, onClose }: {
    record: Rule | null;
    organizations: OrganizationOption[];
    categories: CategoryOption[];
    canManageCityWide: boolean;
    onClose: () => void;
}) {
    const { t, locale } = useLocale();
    const form = useForm({
        organization_id: record?.organization_id ?? (canManageCityWide ? '' : organizations[0]?.id ?? ''),
        target_type: record?.target_type ?? '',
        submission_type: record?.submission_type ?? '',
        category_id: record?.category_id ?? '',
        handler_type: (record?.handler_type ?? 'user') as HandlerType,
        handler_id: record?.handler_id ?? '',
        priority: record?.priority ?? 100,
        effective_from: record?.effective_from ?? '',
        effective_to: record?.effective_to ?? '',
        is_active: record?.is_active ?? true,
    });
    const [options, setOptions] = useState<HandlerOptions | null>(null);
    const [loadingOptions, setLoadingOptions] = useState(false);

    // Handler choices depend on the organization: its units, the users who may work there, every role.
    useEffect(() => {
        let cancelled = false;
        setLoadingOptions(true);
        fetch(route('service-feedback.routing-rules.handlers', form.data.organization_id ? { organization_id: form.data.organization_id } : {}), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((data: HandlerOptions) => { if (!cancelled) setOptions(data); })
            .catch(() => { if (!cancelled) setOptions({ users: [], units: [], roles: [] }); })
            .finally(() => { if (!cancelled) setLoadingOptions(false); });
        return () => { cancelled = true; };
    }, [form.data.organization_id]);

    const choices = options === null ? []
        : form.data.handler_type === 'user' ? options.users
            : form.data.handler_type === 'organization_unit' ? options.units.map((unit) => ({ id: unit.id, name: locale === 'am' && unit.name_am ? unit.name_am : unit.name }))
                : options.roles;
    const applicableCategories = categories.filter((category) => category.organization_id === null || category.organization_id === (form.data.organization_id || null));

    function submit(event: FormEvent) {
        event.preventDefault();
        const settings = { preserveScroll: true, onSuccess: onClose };
        const payload = {
            ...form.data,
            organization_id: form.data.organization_id === '' ? null : form.data.organization_id,
            target_type: form.data.target_type === '' ? null : form.data.target_type,
            submission_type: form.data.submission_type === '' ? null : form.data.submission_type,
            category_id: form.data.category_id === '' ? null : form.data.category_id,
            effective_from: form.data.effective_from === '' ? null : form.data.effective_from,
            effective_to: form.data.effective_to === '' ? null : form.data.effective_to,
        };

        form.transform(() => payload);

        if (record === null) {
            form.post(route('service-feedback.routing-rules.store'), settings);
        } else {
            form.put(route('service-feedback.routing-rules.update', record.id), settings);
        }
    }

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <AppDialog
            open
            onClose={onClose}
            title={t(record === null ? 'serviceFeedback.addRule' : 'serviceFeedback.editRule')}
            description={t('serviceFeedback.ruleDialogHint')}
            maxWidth="xl"
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" form="rule-form" variant="primary" loading={form.processing}>{t('common.save')}</Button>
                </>
            }
        >
            <form id="rule-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <FormField id="rule-scope" label={t('serviceFeedback.categoryScope')} error={errors.organization_id}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.organization_id} onChange={(e) => form.setData((data) => ({ ...data, organization_id: e.target.value, category_id: '', handler_id: '' }))}>
                            {canManageCityWide && <option value="">{t('serviceFeedback.cityWide')}</option>}
                            {organizations.map((organization) => (
                                <option key={organization.id} value={organization.id}>{locale === 'am' && organization.name_am ? organization.name_am : organization.name_en}</option>
                            ))}
                        </Select>
                    )}
                </FormField>
                <FormField id="rule-category" label={t('serviceFeedback.category')} error={errors.category_id}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.category_id} onChange={(e) => form.setData('category_id', e.target.value)}>
                            <option value="">{t('serviceFeedback.anyCategory')}</option>
                            {applicableCategories.map((category) => <option key={category.id} value={category.id}>{locale === 'am' && category.name_am ? category.name_am : category.name_en}</option>)}
                        </Select>
                    )}
                </FormField>
                <FormField id="rule-target" label={t('serviceFeedback.appliesToTargets')} error={errors.target_type}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.target_type} onChange={(e) => form.setData('target_type', e.target.value)}>
                            <option value="">{t('serviceFeedback.anyTargetType')}</option>
                            <option value="employee">{t('serviceFeedback.targetEmployee')}</option>
                            <option value="organization">{t('serviceFeedback.targetOrganization')}</option>
                        </Select>
                    )}
                </FormField>
                <FormField id="rule-type" label={t('serviceFeedback.appliesToTypes')} error={errors.submission_type}>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.submission_type} onChange={(e) => form.setData('submission_type', e.target.value)}>
                            <option value="">{t('serviceFeedback.anySubmissionType')}</option>
                            <option value="suggestion">{t('serviceFeedback.typeSuggestion')}</option>
                            <option value="complaint">{t('serviceFeedback.typeComplaint')}</option>
                        </Select>
                    )}
                </FormField>
                <FormField id="rule-handler-type" label={t('serviceFeedback.handlerType')} error={errors.handler_type} required>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.handler_type} onChange={(e) => form.setData((data) => ({ ...data, handler_type: e.target.value as HandlerType, handler_id: '' }))}>
                            <option value="user">{t('serviceFeedback.handlerUser')}</option>
                            {form.data.organization_id !== '' && <option value="organization_unit">{t('serviceFeedback.handlerUnit')}</option>}
                            <option value="role">{t('serviceFeedback.handlerRole')}</option>
                        </Select>
                    )}
                </FormField>
                <FormField id="rule-handler" label={t('serviceFeedback.handler')} error={errors.handler_id} required>
                    {({ id, describedBy, invalid }) => (
                        <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.handler_id} disabled={loadingOptions} onChange={(e) => form.setData('handler_id', e.target.value)}>
                            <option value="">{loadingOptions ? t('common.loading') : choices.length === 0 ? t('serviceFeedback.noHandlerOptions') : t('serviceFeedback.chooseHandler')}</option>
                            {choices.map((choice) => <option key={choice.id} value={choice.id}>{choice.name}</option>)}
                        </Select>
                    )}
                </FormField>
                <FormField id="rule-priority" label={t('serviceFeedback.priority')} description={t('serviceFeedback.priorityHint')} error={errors.priority}>
                    {({ id, describedBy, invalid }) => <Input id={id} type="number" min={0} max={1000} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.priority} onChange={(e) => form.setData('priority', Number(e.target.value))} />}
                </FormField>
                <label className="flex items-center gap-2 self-end pb-2 text-sm">
                    <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="h-4 w-4 rounded border-[color:var(--app-border-strong)]" />
                    {t('serviceFeedback.qrActive')}
                </label>
                <FormField id="rule-from" label={t('serviceFeedback.effectiveFrom')} error={errors.effective_from}>
                    {({ id }) => <LocalizedDatePicker id={id} value={form.data.effective_from} onChange={(value) => form.setData('effective_from', value)} />}
                </FormField>
                <FormField id="rule-to" label={t('serviceFeedback.effectiveTo')} error={errors.effective_to}>
                    {({ id }) => <LocalizedDatePicker id={id} value={form.data.effective_to} min={form.data.effective_from || undefined} onChange={(value) => form.setData('effective_to', value)} />}
                </FormField>
            </form>
        </AppDialog>
    );
}
