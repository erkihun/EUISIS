import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useEnumLabel } from '@/Components/grievances/ui';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, FormField, Input, Select, Textarea } from '@euisis/ui';

type Organization = { id: string; name_en: string; name_am: string | null };

type Grievance = {
    id: string;
    reference_number: string | null;
    subject: string;
    status: string;
    submitted_at: string | null;
    /** PDFs the grievance brings: the letters issued to the employee and the evidence they submitted. */
    documents: number;
};

type Props = {
    filingId: string;
    caseTypes: Array<{ id: number; name: string }>;
    organizations: Organization[];
    defaultOrganizationId: string | null;
    grievances: Grievance[];
    limits: { documents: number; documentMb: number };
    /** Why court-ms refused the last attempt (courtCases.portal.errors.*). */
    error: string | null;
};

/**
 * My Portal > My court cases > File a case (court-ms Flow 5). The employee files
 * here, against an institution registered in EUISIS, and chooses the grievances
 * the case concerns: their letters, decisions and PDFs go to the court with it.
 */
export default function CourtCaseFile({ filingId, caseTypes, organizations, defaultOrganizationId, grievances, limits, error }: Props) {
    const { t, locale } = useLocale();
    const enumLabel = useEnumLabel();
    const label = (key: string, values: Record<string, string | number> = {}) =>
        Object.entries(values).reduce((text, [name, value]) => text.replaceAll(`{${name}}`, String(value)), t(`courtCases.file.${key}`));
    const form = useForm({
        filing_id: filingId,
        case_type_id: caseTypes.length === 1 ? String(caseTypes[0].id) : '',
        organization_id: defaultOrganizationId ?? '',
        respondent_address: '',
        description: '',
        relief_requested: '',
        grievance_ids: [] as string[],
        files: [] as File[],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const fileErrors = Object.entries(errors).filter(([key]) => key.startsWith('files.')).map(([, message]) => message).join(' ') || undefined;
    const nameOf = (organization: Organization) => (locale === 'am' ? organization.name_am || organization.name_en : organization.name_en || organization.name_am || '');

    const toggleGrievance = (id: string, checked: boolean) => {
        form.setData('grievance_ids', checked ? [...form.data.grievance_ids, id] : form.data.grievance_ids.filter((value) => value !== id));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('employee.court-cases.file.store'), { forceFormData: true, preserveScroll: true });
    };

    return (
        <PortalPage title={label('title')} description={label('description')} backHref={route('employee.court-cases')}>
            {error && <Alert tone="danger">{t(`courtCases.portal.errors.${error}`)}</Alert>}
            {errors.form && <Alert tone="danger">{errors.form}</Alert>}
            {caseTypes.length === 0 && <Alert tone="warning">{label('noCaseTypes')}</Alert>}

            <form onSubmit={submit} className="space-y-6">
                <Card className="space-y-4">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{label('caseSection')}</h2>
                    <FormField label={label('caseType')} required error={errors.case_type_id} className="md:max-w-sm">
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} required value={form.data.case_type_id} onChange={(e) => form.setData('case_type_id', e.target.value)}>
                                <option value="">{label('select')}</option>
                                {caseTypes.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <FormField label={label('caseDescription')} required description={label('caseDescriptionHelp')} error={errors.description}>
                        {({ id, describedBy, invalid }) => (
                            <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} required rows={6} maxLength={20000} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        )}
                    </FormField>
                    <FormField label={label('relief')} error={errors.relief_requested}>
                        {({ id, describedBy, invalid }) => (
                            <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} rows={3} maxLength={5000} value={form.data.relief_requested} onChange={(e) => form.setData('relief_requested', e.target.value)} />
                        )}
                    </FormField>
                </Card>

                <Card className="space-y-4">
                    <div>
                        <h2 className="text-base font-semibold text-(--app-foreground)">{label('respondentSection')}</h2>
                        <p className="text-sm text-(--app-muted-foreground)">{label('respondentHelp')}</p>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        <FormField label={label('respondent')} required error={errors.organization_id}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} required value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                                    <option value="">{label('select')}</option>
                                    {organizations.map((organization) => <option key={organization.id} value={organization.id}>{nameOf(organization)}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <FormField label={label('respondentAddress')} error={errors.respondent_address}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} maxLength={500} value={form.data.respondent_address} onChange={(e) => form.setData('respondent_address', e.target.value)} />
                            )}
                        </FormField>
                    </div>
                </Card>

                <Card className="space-y-4">
                    <div>
                        <h2 className="text-base font-semibold text-(--app-foreground)">{label('grievancesSection')}</h2>
                        <p className="text-sm text-(--app-muted-foreground)">{label('grievancesHelp')}</p>
                    </div>
                    {grievances.length === 0 ? (
                        <p className="text-sm text-(--app-muted-foreground)">{label('noGrievances')}</p>
                    ) : (
                        <fieldset className="space-y-2">
                            <legend className="sr-only">{label('grievancesSection')}</legend>
                            {grievances.map((grievance) => (
                                <label key={grievance.id} className="flex cursor-pointer items-start gap-3 rounded-lg border border-(--app-border) p-3 hover:bg-(--app-surface-muted)">
                                    <input type="checkbox" className="mt-1 h-4 w-4" checked={form.data.grievance_ids.includes(grievance.id)} onChange={(e) => toggleGrievance(grievance.id, e.target.checked)} />
                                    <span className="min-w-0 flex-1 space-y-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="font-mono text-sm text-(--app-foreground)">{grievance.reference_number ?? '—'}</span>
                                            <StatusBadge status={grievance.status} label={enumLabel('status', grievance.status)} />
                                        </span>
                                        <span className="block text-sm font-medium text-(--app-foreground)">{grievance.subject}</span>
                                        <span className="flex flex-wrap gap-3 text-xs text-(--app-muted-foreground)">
                                            <LocalizedDateDisplay value={grievance.submitted_at} />
                                            <span>{label('documentCount', { count: grievance.documents })}</span>
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                    )}
                    {errors.grievance_ids && <p role="alert" className="text-sm text-red-600">{errors.grievance_ids}</p>}

                    <FormField label={label('files')} description={label('filesHelp', { count: limits.documents, size: limits.documentMb })} error={errors.files ?? fileErrors}>
                        {({ id, describedBy, invalid }) => (
                            <input id={id} aria-describedby={describedBy} aria-invalid={invalid} type="file" multiple accept=".pdf,application/pdf"
                                className="block w-full text-sm text-(--app-foreground) file:mr-3 file:rounded-md file:border-0 file:bg-(--app-surface-muted) file:px-3 file:py-2"
                                onChange={(e) => form.setData('files', Array.from(e.target.files ?? []))} />
                        )}
                    </FormField>
                </Card>

                <div className="flex flex-wrap items-center justify-end gap-3">
                    <p className="text-sm text-(--app-muted-foreground)">{label('submitHelp')}</p>
                    <Button type="submit" variant="primary" loading={form.processing} disabled={form.processing || caseTypes.length === 0}>{label('submit')}</Button>
                </div>
            </form>
        </PortalPage>
    );
}
