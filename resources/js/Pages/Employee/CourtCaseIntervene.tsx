import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PortalPage from '@/Components/employees/portal/PortalPage';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, FormField, Input, Textarea } from '@euisis/ui';

type Props = {
    /** A case number to start from (?case=). */
    caseNumber: string;
    /** Why court-ms refused the last attempt (courtCases.portal.errors.*). */
    error: string | null;
};

/**
 * My Portal > My court cases > Apply to intervene (court-ms, ADR 0013 #13). An
 * employee whose rights a colleague's case affects asks to join it, with their
 * grounds and a supporting PDF; only the judge of the case admits or refuses.
 */
export default function CourtCaseIntervene({ caseNumber, error }: Props) {
    const { t } = useLocale();
    const label = (key: string) => t(`courtCases.intervene.${key}`);
    const form = useForm({ case_number: caseNumber, statement: '', document: null as File | null });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('employee.court-cases.intervene.store'), { forceFormData: true, preserveScroll: true });
    };

    return (
        <PortalPage title={label('title')} description={label('description')} backHref={route('employee.court-cases')}>
            {error && <Alert tone="danger">{t(`courtCases.portal.errors.${error}`)}</Alert>}
            {errors.form && <Alert tone="danger">{errors.form}</Alert>}

            <form onSubmit={submit} className="space-y-6">
                <Card className="space-y-4">
                    <FormField label={label('caseNumber')} required description={label('caseNumberHelp')} error={errors.case_number} className="md:max-w-sm">
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} required maxLength={60} value={form.data.case_number}
                                onChange={(e) => form.setData('case_number', e.target.value)} className="font-mono" />
                        )}
                    </FormField>
                    <FormField label={label('statement')} required description={label('statementHelp')} error={errors.statement}>
                        {({ id, describedBy, invalid }) => (
                            <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} required rows={6} maxLength={5000} value={form.data.statement}
                                onChange={(e) => form.setData('statement', e.target.value)} />
                        )}
                    </FormField>
                    <FormField label={label('document')} description={label('documentHelp')} error={errors.document}>
                        {({ id, describedBy, invalid }) => (
                            <input id={id} aria-describedby={describedBy} aria-invalid={invalid} type="file" accept=".pdf,application/pdf"
                                className="block w-full text-sm text-(--app-foreground) file:mr-3 file:rounded-md file:border-0 file:bg-(--app-surface-muted) file:px-3 file:py-2"
                                onChange={(e) => form.setData('document', e.target.files?.[0] ?? null)} />
                        )}
                    </FormField>
                </Card>

                <div className="flex flex-wrap items-center justify-end gap-3">
                    <p className="text-sm text-(--app-muted-foreground)">{label('submitHelp')}</p>
                    <Button type="submit" variant="primary" loading={form.processing} disabled={form.processing}>{label('submit')}</Button>
                </div>
            </form>
        </PortalPage>
    );
}
