import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PortalPage from '@/Components/employees/portal/PortalPage';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, CardDescription, CardTitle, FormField, Input, actionLinkClassName } from '@euisis/ui';

type Witness = { full_name: string; phone: string; email: string; address: string };

type Props = {
    form: {
        case: { id: number; case_number: string; description: string; relief_requested: string };
        docs: Array<{ id: number; title: string | null; has_file: boolean }>;
        witnesses: Array<{ id: number; full_name: string; phone: string | null; email: string | null; address: string | null }>;
    };
};

const MAX_DOCUMENTS = 10;
const blankWitness = (): Witness => ({ full_name: '', phone: '', email: '', address: '' });

/**
 * My Portal > My court cases > a case > Correct (court-ms Flow 6). A case the
 * registry sent back is corrected here and goes back for review: what happened,
 * what is asked, more documents and witnesses. The respondent and case type
 * stay as filed.
 */
export default function CourtCaseCorrect({ form: page }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const label = (key: string, values: Record<string, string | number> = {}) =>
        Object.entries(values).reduce((text, [name, value]) => text.replaceAll(`{${name}}`, String(value)), t(`courtCases.correct.${key}`));
    const courtCase = page.case;
    const form = useForm({
        description: courtCase.description,
        relief_requested: courtCase.relief_requested,
        evidence_titles: [] as string[],
        evidence_files: [] as File[],
        witnesses: [] as Witness[],
        certify_appeal: false,
        certify_evidence: false,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const shown = ['description', 'relief_requested', 'certify_appeal', 'certify_evidence'];
    // court-ms's own checks (duplicate witness phones, a file it refused) have no field of their own here.
    const other = Object.entries(errors).filter(([key, message]) => message && !shown.includes(key)).map(([, message]) => message as string);

    const setFiles = (files: File[]) => {
        const chosen = files.slice(0, MAX_DOCUMENTS);
        form.setData((data) => ({ ...data, evidence_files: chosen, evidence_titles: chosen.map((file, i) => data.evidence_titles[i] ?? file.name.replace(/\.pdf$/i, '')) }));
    };
    const setTitle = (index: number, value: string) => form.setData('evidence_titles', form.data.evidence_titles.map((title, i) => (i === index ? value : title)));
    const setWitness = (index: number, field: keyof Witness, value: string) =>
        form.setData('witnesses', form.data.witnesses.map((witness, i) => (i === index ? { ...witness, [field]: value } : witness)));

    async function remove(url: string, question: string) {
        const { confirmed } = await confirm({ title: t('courtCases.case.removeTitle'), description: question, confirmLabel: t('courtCases.case.remove'), cancelLabel: t('courtCases.case.cancel'), variant: 'danger' });
        if (confirmed) {
            router.post(url, {}, { preserveScroll: true, preserveState: true });
        }
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('employee.court-cases.correct.store', courtCase.id), { forceFormData: true, preserveScroll: true });
    };

    return (
        <PortalPage title={label('title', { number: courtCase.case_number })} description={label('description')} backHref={route('employee.court-cases.show', courtCase.id)}>
            {other.length > 0 && (
                <Alert tone="danger">
                    <ul className="list-disc space-y-1 pl-5">{other.map((message) => <li key={message}>{message}</li>)}</ul>
                </Alert>
            )}

            <form onSubmit={submit} className="space-y-6">
                <Card className="space-y-4">
                    <CardTitle>{label('caseSection')}</CardTitle>
                    <RichTextEditor label={t('courtCases.file.caseDescription')} required preset="basic" locale={locale === 'am' ? 'am' : 'en'} maxLength={20000}
                        value={form.data.description} onChange={(html) => form.setData('description', html)} error={errors.description} />
                    <RichTextEditor label={t('courtCases.file.relief')} required preset="basic" locale={locale === 'am' ? 'am' : 'en'} maxLength={5000}
                        value={form.data.relief_requested} onChange={(html) => form.setData('relief_requested', html)} error={errors.relief_requested} />
                </Card>

                <Card className="space-y-4">
                    <div>
                        <CardTitle>{label('documents')}</CardTitle>
                        <CardDescription>{label('documentsHelp', { count: MAX_DOCUMENTS })}</CardDescription>
                    </div>
                    {page.docs.length > 0 && (
                        <ul className="divide-y divide-(--app-border) rounded-lg border border-(--app-border)">
                            {page.docs.map((doc) => (
                                <li key={doc.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <span className="truncate text-sm text-(--app-foreground)">{doc.title ?? t('courtCases.case.document')}</span>
                                    <button type="button" onClick={() => void remove(route('employee.court-cases.evidences.destroy', [courtCase.id, doc.id]), t('courtCases.case.removeDocument'))}
                                        className={actionLinkClassName({ tone: 'danger' })}>{t('courtCases.case.remove')}</button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <FormField label={label('newDocuments')} error={errors.evidence_files}>
                        {({ id, describedBy, invalid }) => (
                            <input id={id} aria-describedby={describedBy} aria-invalid={invalid} type="file" multiple accept=".pdf,application/pdf"
                                className="block w-full text-sm text-(--app-foreground) file:mr-3 file:rounded-md file:border-0 file:bg-(--app-surface-muted) file:px-3 file:py-2"
                                onChange={(e) => setFiles(Array.from(e.target.files ?? []))} />
                        )}
                    </FormField>
                    {form.data.evidence_files.map((file, index) => (
                        <FormField key={`${file.name}-${index}`} label={label('documentTitle', { name: file.name })} error={errors[`evidence_titles.${index}`] ?? errors[`evidence_files.${index}`]}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} maxLength={255} value={form.data.evidence_titles[index] ?? ''} onChange={(e) => setTitle(index, e.target.value)} />
                            )}
                        </FormField>
                    ))}
                </Card>

                <Card className="space-y-4">
                    <div>
                        <CardTitle>{label('witnesses')}</CardTitle>
                        <CardDescription>{label('witnessesHelp')}</CardDescription>
                    </div>
                    {page.witnesses.length > 0 && (
                        <ul className="divide-y divide-(--app-border) rounded-lg border border-(--app-border)">
                            {page.witnesses.map((witness) => (
                                <li key={witness.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-medium text-(--app-foreground)">{witness.full_name}</span>
                                        <span className="block truncate text-xs text-(--app-muted-foreground)">{[witness.phone, witness.email].filter(Boolean).join(' · ')}</span>
                                    </span>
                                    <button type="button" onClick={() => void remove(route('employee.court-cases.witnesses.destroy', [courtCase.id, witness.id]), t('courtCases.case.removeWitness'))}
                                        className={actionLinkClassName({ tone: 'danger' })}>{t('courtCases.case.remove')}</button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {form.data.witnesses.map((witness, index) => (
                        <fieldset key={index} className="grid gap-3 rounded-lg border border-(--app-border) p-4 md:grid-cols-2">
                            <legend className="px-1 text-sm font-medium text-(--app-foreground)">{label('newWitness', { n: index + 1 })}</legend>
                            {(['full_name', 'phone', 'email', 'address'] as const).map((field) => (
                                <FormField key={field} label={label(`witness.${field}`)} required={field === 'full_name'} error={errors[`witnesses.${index}.${field}`]}>
                                    {({ id, describedBy, invalid }) => (
                                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} type={field === 'email' ? 'email' : field === 'phone' ? 'tel' : 'text'} required={field === 'full_name'}
                                            maxLength={field === 'phone' ? 60 : field === 'email' ? 150 : 255} value={witness[field]} onChange={(e) => setWitness(index, field, e.target.value)} />
                                    )}
                                </FormField>
                            ))}
                            <div className="md:col-span-2">
                                <button type="button" onClick={() => form.setData('witnesses', form.data.witnesses.filter((_, i) => i !== index))} className={actionLinkClassName({ tone: 'danger' })}>{label('removeRow')}</button>
                            </div>
                        </fieldset>
                    ))}
                    <Button type="button" variant="outline" size="sm" disabled={form.data.witnesses.length >= 20} onClick={() => form.setData('witnesses', [...form.data.witnesses, blankWitness()])}>{label('addWitness')}</Button>
                </Card>

                <Card className="space-y-3">
                    {(['certify_appeal', 'certify_evidence'] as const).map((field) => (
                        <div key={field}>
                            <label className="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" className="mt-1 h-4 w-4" required checked={form.data[field]} onChange={(e) => form.setData(field, e.target.checked)} aria-invalid={Boolean(errors[field])} />
                                <span className="text-sm text-(--app-foreground)">{label(field)}</span>
                            </label>
                            {errors[field] && <p role="alert" className="mt-1 text-sm text-red-600">{errors[field]}</p>}
                        </div>
                    ))}
                </Card>

                <div className="flex flex-wrap items-center justify-end gap-3">
                    <p className="text-sm text-(--app-muted-foreground)">{label('submitHelp')}</p>
                    <Button type="submit" variant="primary" loading={form.processing} disabled={form.processing}>{label('submit')}</Button>
                </div>
            </form>
        </PortalPage>
    );
}
