import FormSection from '@/Components/FormSection';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Plus, TrashIcon } from '@/Components/Icons';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import { Alert, Button, Card, FormField, Input, Select, buttonClassName } from '@euisis/ui';
import { Link, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

export type Organization = { id: string; name_en: string; name_am: string | null };
export type PositionOption = {
    id: string;
    title_en: string;
    title_am: string | null;
    grade_level: string | null;
    organization_id: string;
    organization_unit_name: string | null;
    code: string | null;
    available_slots: number;
};

export type PositionRow = {
    organization_id: string;
    position_id: string;
    grade_level: string;
    salary_min: string;
    salary_max: string;
    vacancy_count: number;
};

export type RuleType = 'employment_status' | 'current_grade' | 'current_organization' | 'current_position' | 'minimum_service_months';
export type EligibilityRule = { type: RuleType; operator: 'equals' | 'in' | 'greater_than_or_equal'; value: string };

export type AnnouncementFormData = {
    positions: PositionRow[];
    eligibility_rules: EligibilityRule[];
    required_documents: string[];
    opening_date: string;
    closing_date: string;
};

type Props = {
    initial: AnnouncementFormData;
    organizations: Organization[];
    positions: PositionOption[];
    positionsTruncated: boolean;
    settingsDocuments: string[];
    /** Where the form posts, with its verb. */
    submit: { method: 'post' | 'patch'; url: string };
    cancelHref: string;
};

export const emptyRow = (): PositionRow => ({ organization_id: '', position_id: '', grade_level: '', salary_min: '', salary_max: '', vacancy_count: 1 });

/** Rule types an editor can add. `current_position` takes a position id and stays editable only on rules that already use it. */
const ADDABLE_RULES: RuleType[] = ['minimum_service_months', 'current_grade', 'current_organization', 'employment_status'];
const EMPLOYMENT_STATUSES = ['active', 'suspended'];

/** Each rule type compares one way, so the operator follows from the type and the value. */
function operatorFor(type: RuleType, value: string): EligibilityRule['operator'] {
    if (type === 'minimum_service_months') return 'greater_than_or_equal';

    return value.includes(',') ? 'in' : 'equals';
}

/**
 * The announcement draft, shared by Create and Edit: which vacant positions it
 * advertises, the application window, extra eligibility rules and the
 * documents applicants attach. Publishing is a separate, checked action on the
 * announcement page.
 */
export default function AnnouncementForm({ initial, organizations, positions, positionsTruncated, settingsDocuments, submit, cancelHref }: Props) {
    const { locale, t } = useLocale();
    const { number, organizationName } = useDisplayFormat();
    const form = useForm<AnnouncementFormData>(initial);
    const { data, setData, errors, processing } = form;
    const fieldError = (key: string) => (errors as Record<string, string | undefined>)[key];
    const [positionQuery, setPositionQuery] = useState('');

    const chosen = new Set(data.positions.map((row) => row.position_id).filter(Boolean));
    const totalVacancies = data.positions.reduce((sum, row) => sum + (Number(row.vacancy_count) || 0), 0);
    const matches = useMemo(() => {
        const query = positionQuery.trim().toLocaleLowerCase();
        if (!query) return positions;

        return positions.filter((position) => [position.code, position.title_en, position.title_am, position.organization_unit_name]
            .some((value) => value?.toLocaleLowerCase().includes(query)));
    }, [positionQuery, positions]);

    /** Drops the messages of fields the user has since changed, so only live problems stay on screen. */
    const clear = (...keys: string[]) => form.clearErrors(...(keys as never[]));

    function updateRow(index: number, patch: Partial<PositionRow>) {
        setData('positions', data.positions.map((row, i) => (i === index ? { ...row, ...patch } : row)));
        clear(...Object.keys(patch).map((key) => `positions.${index}.${key}`));
    }

    function updateRule(index: number, patch: Partial<EligibilityRule>) {
        setData('eligibility_rules', data.eligibility_rules.map((rule, i) => {
            if (i !== index) return rule;
            const next = { ...rule, ...patch };

            return { ...next, operator: operatorFor(next.type, next.value) };
        }));
        clear(`eligibility_rules.${index}.value`, `eligibility_rules.${index}.type`);
    }

    function onSubmit(event: FormEvent) {
        event.preventDefault();
        // Blank document rows are dropped rather than refused.
        form.transform((current) => ({ ...current, required_documents: current.required_documents.map((name) => name.trim()).filter(Boolean) }));
        form[submit.method](submit.url, { preserveScroll: true });
    }

    return (
        <form onSubmit={onSubmit} noValidate>
            <Card className="space-y-6">
                {Object.keys(errors).length > 0 && <Alert tone="danger">{t('transfers.fixErrorsBelow')}</Alert>}

                <FormSection title={t('transfers.includedPositions')} description={t('transfers.includedPositionsHint')} grid={false}>
                    {positionsTruncated && (
                        <Alert tone="warning">{t('transfers.positionsTruncated').replace(':count', number(positions.length))}</Alert>
                    )}
                    {positions.length === 0 && <Alert tone="warning">{t('transfers.noVacantPositions')}</Alert>}

                    <FormField id="position-search" label={t('transfers.searchPositions')} description={t('transfers.searchPositionsHint')}>
                        {({ id, describedBy }) => <Input id={id} type="search" aria-describedby={describedBy} value={positionQuery} onChange={(e) => setPositionQuery(e.target.value)} />}
                    </FormField>

                    {data.positions.map((row, index) => {
                        const options = matches.filter((position) => !row.organization_id || position.organization_id === row.organization_id);
                        const selected = positions.find((position) => position.id === row.position_id);

                        return (
                            <fieldset key={index} className="rounded-control border border-(--app-border) p-4">
                                <legend className="px-1 text-sm font-semibold text-(--app-foreground)">
                                    {t('transfers.positionLine')} {number(index + 1)}
                                </legend>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <FormField id={`org-${index}`} label={t('transfers.organization')} required error={fieldError(`positions.${index}.organization_id`)}>
                                        {({ id, describedBy, invalid }) => (
                                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={row.organization_id}
                                                onChange={(e) => updateRow(index, { organization_id: e.target.value, position_id: '', grade_level: '', vacancy_count: 1 })}>
                                                <option value="">{t('transfers.selectOrganization')}</option>
                                                {organizations.map((organization) => (
                                                    <option key={organization.id} value={organization.id}>{organizationName(organization.name_en, organization.name_am)}</option>
                                                ))}
                                            </Select>
                                        )}
                                    </FormField>
                                    <FormField id={`position-${index}`} label={t('transfers.position')} required error={fieldError(`positions.${index}.position_id`)}
                                        description={selected ? t('transfers.slotsFree').replace(':count', number(selected.available_slots)) : undefined}>
                                        {({ id, describedBy, invalid }) => (
                                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={row.position_id} disabled={!row.organization_id}
                                                onChange={(e) => updateRow(index, { position_id: e.target.value, grade_level: positions.find((p) => p.id === e.target.value)?.grade_level ?? '' })}>
                                                <option value="">{row.organization_id ? t('transfers.selectPosition') : t('transfers.selectOrganizationFirst')}</option>
                                                {options.map((position) => (
                                                    <option key={position.id} value={position.id} disabled={chosen.has(position.id) && row.position_id !== position.id}>
                                                        {position.code ? `${position.code} — ` : ''}{localizedName(position.title_en, position.title_am, locale)}
                                                        {position.organization_unit_name ? ` · ${position.organization_unit_name}` : ''}
                                                    </option>
                                                ))}
                                            </Select>
                                        )}
                                    </FormField>
                                </div>
                                <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <FormField id={`vacancies-${index}`} label={t('transfers.numberOfVacancies')} required error={fieldError(`positions.${index}.vacancy_count`)}>
                                        {({ id, describedBy, invalid }) => (
                                            <Input id={id} type="number" inputMode="numeric" min={1} max={selected?.available_slots} aria-describedby={describedBy} aria-invalid={invalid}
                                                value={row.vacancy_count} onChange={(e) => updateRow(index, { vacancy_count: Number(e.target.value) })} />
                                        )}
                                    </FormField>
                                    <FormField id={`grade-${index}`} label={t('transfers.gradeLevel')} description={t('transfers.gradeFromPosition')}>
                                        {({ id, describedBy }) => <Input id={id} aria-describedby={describedBy} value={row.grade_level || '—'} readOnly />}
                                    </FormField>
                                    <FormField id={`salary-min-${index}`} label={`${t('transfers.salaryMin')} (${t('common.optional')})`} error={fieldError(`positions.${index}.salary_min`)}>
                                        {({ id, describedBy, invalid }) => (
                                            <Input id={id} type="number" inputMode="decimal" min={0} aria-describedby={describedBy} aria-invalid={invalid} value={row.salary_min} onChange={(e) => updateRow(index, { salary_min: e.target.value })} />
                                        )}
                                    </FormField>
                                    <FormField id={`salary-max-${index}`} label={`${t('transfers.salaryMax')} (${t('common.optional')})`} error={fieldError(`positions.${index}.salary_max`)}>
                                        {({ id, describedBy, invalid }) => (
                                            <Input id={id} type="number" inputMode="decimal" min={0} aria-describedby={describedBy} aria-invalid={invalid} value={row.salary_max} onChange={(e) => updateRow(index, { salary_max: e.target.value })} />
                                        )}
                                    </FormField>
                                </div>
                                {data.positions.length > 1 && (
                                    <div className="mt-3 flex justify-end">
                                        <Button type="button" variant="ghost" size="sm" onClick={() => setData('positions', data.positions.filter((_, i) => i !== index))} icon={<TrashIcon className="size-4" />}>{t('transfers.removePosition')}
                                        </Button>
                                    </div>
                                )}
                            </fieldset>
                        );
                    })}

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Button type="button" variant="outline" onClick={() => setData('positions', [...data.positions, emptyRow()])} icon={<Plus className="size-4" />}>{t('transfers.addPosition')}
                        </Button>
                        <p className="text-sm text-(--app-muted-foreground)" aria-live="polite">
                            {t('transfers.totalVacancies')}: <span className="font-semibold text-(--app-foreground)">{number(totalVacancies)}</span>
                        </p>
                    </div>
                    {fieldError('positions') && <p role="alert" className="text-sm text-red-600 dark:text-red-400">{fieldError('positions')}</p>}
                </FormSection>

                <FormSection title={t('transfers.applicationPeriod')} description={t('transfers.applicationPeriodHint')}>
                    <FormField id="opening-date" label={t('transfers.openingDate')} required error={errors.opening_date}>
                        {({ id }) => <LocalizedDatePicker id={id} value={data.opening_date} onChange={(value) => { setData('opening_date', value); clear('opening_date'); }} />}
                    </FormField>
                    <FormField id="closing-date" label={t('transfers.closingDate')} required error={errors.closing_date} description={t('transfers.closingDayIncluded')}>
                        {({ id }) => <LocalizedDatePicker id={id} value={data.closing_date} min={data.opening_date || undefined} onChange={(value) => { setData('closing_date', value); clear('closing_date'); }} />}
                    </FormField>
                </FormSection>

                <FormSection title={t('transfers.eligibilityRules')} description={t('transfers.eligibilityRulesHint')} grid={false}>
                    {data.eligibility_rules.length === 0 && <p className="text-sm text-(--app-muted-foreground)">{t('transfers.noExtraRules')}</p>}
                    {data.eligibility_rules.map((rule, index) => (
                        <div key={index} className="grid items-end gap-3 rounded-control border border-(--app-border) p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto]">
                            <FormField id={`rule-type-${index}`} label={t('transfers.ruleType')}>
                                {({ id }) => (
                                    <Select id={id} value={rule.type} onChange={(e) => updateRule(index, { type: e.target.value as RuleType, value: e.target.value === 'employment_status' ? 'active' : '' })}>
                                        {(ADDABLE_RULES.includes(rule.type) ? ADDABLE_RULES : [rule.type, ...ADDABLE_RULES]).map((type) => (
                                            <option key={type} value={type}>{t(`transfers.ruleLabel.${type}`)}</option>
                                        ))}
                                    </Select>
                                )}
                            </FormField>
                            <FormField id={`rule-value-${index}`} label={t(`transfers.ruleValueLabel.${rule.type}`)} error={fieldError(`eligibility_rules.${index}.value`)}
                                description={rule.type === 'current_grade' ? t('transfers.ruleGradeHint') : undefined}>
                                {({ id, describedBy, invalid }) => rule.type === 'current_organization' ? (
                                    <Select id={id} aria-invalid={invalid} value={rule.value} onChange={(e) => updateRule(index, { value: e.target.value })}>
                                        <option value="">{t('transfers.selectOrganization')}</option>
                                        {organizations.map((organization) => <option key={organization.id} value={organization.id}>{organizationName(organization.name_en, organization.name_am)}</option>)}
                                    </Select>
                                ) : rule.type === 'employment_status' ? (
                                    <Select id={id} value={rule.value} onChange={(e) => updateRule(index, { value: e.target.value })}>
                                        {EMPLOYMENT_STATUSES.map((status) => <option key={status} value={status}>{t(`status.${status}`)}</option>)}
                                    </Select>
                                ) : (
                                    <Input id={id} aria-describedby={describedBy} aria-invalid={invalid}
                                        type={rule.type === 'minimum_service_months' ? 'number' : 'text'} min={rule.type === 'minimum_service_months' ? 0 : undefined}
                                        value={rule.value} onChange={(e) => updateRule(index, { value: e.target.value })} />
                                )}
                            </FormField>
                            <Button type="button" variant="ghost" size="icon" aria-label={t('transfers.removeRule')} title={t('transfers.removeRule')}
                                onClick={() => setData('eligibility_rules', data.eligibility_rules.filter((_, i) => i !== index))} icon={<TrashIcon className="size-4" />}>
                            </Button>
                        </div>
                    ))}
                    <div>
                        <Button type="button" variant="outline" size="sm" onClick={() => setData('eligibility_rules', [...data.eligibility_rules, { type: 'minimum_service_months', operator: 'greater_than_or_equal', value: '' }])} icon={<Plus className="size-4" />}>{t('transfers.addEligibilityRule')}
                        </Button>
                    </div>
                </FormSection>

                <FormSection title={t('transfers.requiredDocuments')} description={t('transfers.requiredDocumentsHint')} grid={false}>
                    {settingsDocuments.length > 0 && (
                        <p className="text-sm text-(--app-muted-foreground)">
                            {t('transfers.alwaysRequired')}: <span className="text-(--app-foreground)">{settingsDocuments.join(', ')}</span>
                        </p>
                    )}
                    {data.required_documents.map((document, index) => (
                        <div key={index} className="flex items-end gap-2">
                            <FormField id={`document-${index}`} label={`${t('transfers.documentName')} ${number(index + 1)}`} error={fieldError(`required_documents.${index}`)} className="flex-1">
                                {({ id, describedBy, invalid }) => (
                                    <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} maxLength={100} placeholder={t('transfers.documentNamePlaceholder')} value={document}
                                        onChange={(e) => { setData('required_documents', data.required_documents.map((value, i) => (i === index ? e.target.value : value))); clear(`required_documents.${index}`); }} />
                                )}
                            </FormField>
                            <Button type="button" variant="ghost" size="icon" aria-label={t('transfers.removeDocument')} title={t('transfers.removeDocument')}
                                onClick={() => setData('required_documents', data.required_documents.filter((_, i) => i !== index))} icon={<TrashIcon className="size-4" />}>
                            </Button>
                        </div>
                    ))}
                    <div>
                        <Button type="button" variant="outline" size="sm" onClick={() => setData('required_documents', [...data.required_documents, ''])} icon={<Plus className="size-4" />}>{t('transfers.addDocument')}
                        </Button>
                    </div>
                </FormSection>

                <div className="flex flex-col-reverse gap-2 border-t border-(--app-border) pt-5 sm:flex-row sm:items-center sm:justify-end">
                    <p className="text-xs text-(--app-muted-foreground) sm:me-auto">{t('transfers.draftAnnouncementHint')}</p>
                    <Link href={cancelHref} className={buttonClassName({ variant: 'outline' })}>{t('common.cancel')}</Link>
                    <Button type="submit" variant="primary" loading={processing}>{t('transfers.saveDraft')}</Button>
                </div>
            </Card>
        </form>
    );
}
