import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Alert, Button, Card, FormField, Input, Select } from '@euisis/ui';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';
import type { TipType } from '@/types/publicSubmissions';

export type HandlerType = 'user' | 'organization_unit' | 'role';

export type RuleRecord = {
    id: string;
    organization_id: string | null;
    target_type: 'employee' | 'organization' | null;
    submission_type: 'suggestion' | 'complaint' | null;
    tip_type: TipType | null;
    organization_unit_id: string | null;
    handler_type: HandlerType;
    handler_id: string;
    priority: number;
    effective_from: string | null;
    effective_to: string | null;
    is_active: boolean;
};

export type OrganizationOption = { id: string; code: string; name_en: string; name_am: string | null };
type HandlerOptions = { users: { id: string; name: string }[]; units: { id: string; name: string; name_am: string | null }[]; roles: { id: string; name: string }[] };

type Props = {
    record: RuleRecord | null;
    organizations: OrganizationOption[];
    canManageCityWide: boolean;
};

/** The one routing-rule form, used by the create and the edit page. Saving returns to the list. */
export default function RuleForm({ record, organizations, canManageCityWide }: Props) {
    const { t, locale } = useLocale();
    const form = useForm({
        organization_id: record?.organization_id ?? (canManageCityWide ? '' : organizations[0]?.id ?? ''),
        target_type: record?.target_type ?? '',
        submission_type: record?.submission_type ?? '',
        tip_type: record?.tip_type ?? '',
        organization_unit_id: record?.organization_unit_id ?? '',
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
    // The units a rule may be narrowed to: the institution's own. A city-wide rule has none.
    const unitChoices = form.data.organization_id === '' || options === null ? [] : options.units.map((unit) => ({ id: unit.id, name: locale === 'am' && unit.name_am ? unit.name_am : unit.name }));

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            organization_id: data.organization_id === '' ? null : data.organization_id,
            target_type: data.target_type === '' ? null : data.target_type,
            submission_type: data.submission_type === '' ? null : data.submission_type,
            // A tip type narrows only a Tip-off rule.
            tip_type: data.tip_type === '' || data.submission_type === 'complaint' ? null : data.tip_type,
            organization_unit_id: data.organization_unit_id === '' || data.organization_id === '' ? null : data.organization_unit_id,
            effective_from: data.effective_from === '' ? null : data.effective_from,
            effective_to: data.effective_to === '' ? null : data.effective_to,
        }));

        if (record === null) {
            form.post(route('service-feedback.routing-rules.store'));
        } else {
            form.put(route('service-feedback.routing-rules.update', record.id));
        }
    }

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="space-y-6">
            <Alert tone="info">{t('serviceFeedback.ruleDialogHint')}</Alert>
            <Card>
                <div className="grid gap-4 p-6 sm:grid-cols-2">
                    <FormField id="rule-scope" label={t('serviceFeedback.categoryScope')} error={errors.organization_id}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.organization_id} onChange={(e) => form.setData((data) => ({ ...data, organization_id: e.target.value, organization_unit_id: '', handler_id: '' }))}>
                                {canManageCityWide && <option value="">{t('serviceFeedback.cityWide')}</option>}
                                {organizations.map((organization) => (
                                    <option key={organization.id} value={organization.id}>{locale === 'am' && organization.name_am ? organization.name_am : organization.name_en}</option>
                                ))}
                            </Select>
                        )}
                    </FormField>
                    {form.data.organization_id !== '' && (
                        <FormField id="rule-unit" label={t('serviceFeedback.ruleRelevantUnit')} description={t('serviceFeedback.ruleRelevantUnitHint')} error={errors.organization_unit_id}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.organization_unit_id} disabled={loadingOptions} onChange={(e) => form.setData('organization_unit_id', e.target.value)}>
                                    <option value="">{t('serviceFeedback.anyUnit')}</option>
                                    {unitChoices.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
                                </Select>
                            )}
                        </FormField>
                    )}
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
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.submission_type} onChange={(e) => form.setData((data) => ({ ...data, submission_type: e.target.value, tip_type: e.target.value === 'complaint' ? '' : data.tip_type }))}>
                                <option value="">{t('serviceFeedback.anySubmissionType')}</option>
                                <option value="suggestion">{t('serviceFeedback.adminTypeSuggestion')}</option>
                                <option value="complaint">{t('serviceFeedback.adminTypeComplaint')}</option>
                            </Select>
                        )}
                    </FormField>
                    {form.data.submission_type !== 'complaint' && (
                        <FormField id="rule-tip-type" label={t('serviceFeedback.ruleTipType')} description={t('serviceFeedback.ruleTipTypeHint')} error={errors.tip_type}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.tip_type} onChange={(e) => form.setData('tip_type', e.target.value)}>
                                    <option value="">{t('serviceFeedback.anyTipTypeExceptCorruption')}</option>
                                    <option value="CORRUPTION">{t('serviceFeedback.tipTypeCorruption')}</option>
                                    <option value="IMPROPER_PRACTICE">{t('serviceFeedback.tipTypeImproperPractice')}</option>
                                </Select>
                            )}
                        </FormField>
                    )}
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
                        <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="h-4 w-4 rounded-sm border-(--app-border-strong)" />
                        {t('serviceFeedback.qrActive')}
                    </label>
                    <FormField id="rule-from" label={t('serviceFeedback.effectiveFrom')} error={errors.effective_from}>
                        {({ id }) => <LocalizedDatePicker id={id} value={form.data.effective_from} onChange={(value) => form.setData('effective_from', value)} />}
                    </FormField>
                    <FormField id="rule-to" label={t('serviceFeedback.effectiveTo')} error={errors.effective_to}>
                        {({ id }) => <LocalizedDatePicker id={id} value={form.data.effective_to} min={form.data.effective_from || undefined} onChange={(value) => form.setData('effective_to', value)} />}
                    </FormField>
                </div>
                <div className="flex justify-end gap-2 border-t border-(--app-border) px-6 py-4">
                    <Button as={Link} href={route('service-feedback.routing-rules.index')} variant="outline">{t('common.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={form.processing}>{t('common.save')}</Button>
                </div>
            </Card>
        </form>
    );
}
