import { Head, useForm } from '@inertiajs/react';
import axios from 'axios';
import { type FormEvent, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { Alert, Button, Card, FormField, Input, Select } from '@/Components/ui';
import { usePublicText } from '@/court/i18n/usePublicText';

interface FileCaseProps {
    caseTypes: { id: number; name: string }[];
    organizations: { uuid: string; name_en: string; name_am: string | null }[];
}

/** A client's employee number, looked up in EUISIS before filing. */
type ClientCheck = { state: 'idle' | 'checking' | 'found' | 'missing'; message: string };

interface FileCaseForm {
    client_employee_number: string;
    /** The other clients in the same matter: co-applicants, each invited to confirm. */
    co_client_employee_numbers: string[];
    case_type_id: string;
    respondent_organization_uuid: string;
    respondent_address: string;
    description: string;
    relief_requested: string;
    power_of_attorney: File | null;
    documents: File[];
}

/**
 * A verified lawyer files a case for a client (LawyerCaseController). The
 * client is an employee found in EUISIS by number, checked by name before
 * filing; the respondent an EUISIS institution. The power of attorney goes
 * with the case, and the client confirms it in the EUISIS employee portal.
 * Other clients in the same matter are added below the first, each checked
 * the same way; they join as co-applicants.
 */
export default function LawyerFileCase({ caseTypes, organizations }: FileCaseProps) {
    const { t, lang } = usePublicText();
    const text = (key: string) => t(`cm.lawyer.file.${key}`);
    const form = useForm<FileCaseForm>({
        client_employee_number: '',
        co_client_employee_numbers: [],
        case_type_id: '',
        respondent_organization_uuid: '',
        respondent_address: '',
        description: '',
        relief_requested: '',
        power_of_attorney: null,
        documents: [],
    });
    const [client, setClient] = useState<ClientCheck>({ state: 'idle', message: '' });
    const [coChecks, setCoChecks] = useState<ClientCheck[]>([]);
    const errors = form.errors as Record<string, string | undefined>;
    const title = text('title');

    const lookUp = async (number: string): Promise<ClientCheck> => {
        try {
            const response = await axios.post<{ data: { name: string } }>(route('court.applicant.lawyer.client'), { employee_number: number });
            return { state: 'found', message: t('cm.lawyer.file.client_found').replace(':name', response.data.data.name) };
        } catch (error) {
            const message = axios.isAxiosError(error) ? (error.response?.data as { message?: string } | undefined)?.message : undefined;
            return { state: 'missing', message: message ?? text('client_not_found') };
        }
    };

    const findClient = async () => {
        const number = form.data.client_employee_number.trim();
        if (number === '') return;
        setClient({ state: 'checking', message: '' });
        setClient(await lookUp(number));
    };

    const setCoCheck = (index: number, check: ClientCheck) => setCoChecks((current) => {
        const next = [...current];
        next[index] = check;
        return next;
    });
    const findCoClient = async (index: number) => {
        const number = (form.data.co_client_employee_numbers[index] ?? '').trim();
        if (number === '') return;
        setCoCheck(index, { state: 'checking', message: '' });
        setCoCheck(index, await lookUp(number));
    };
    const addCoClient = () => form.setData('co_client_employee_numbers', [...form.data.co_client_employee_numbers, '']);
    const removeCoClient = (index: number) => {
        form.setData('co_client_employee_numbers', form.data.co_client_employee_numbers.filter((_, i) => i !== index));
        setCoChecks((current) => current.filter((_, i) => i !== index));
    };
    const anyMissing = client.state === 'missing' || coChecks.some((check) => check?.state === 'missing');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.applicant.lawyer.file.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={text('description')} backHref={route('court.applicant.cases.index')} />}>
            <Head title={title} />

            <form onSubmit={submit} className="space-y-5" encType="multipart/form-data">
                <Card className="space-y-5">
                    <FormField label={text('client')} required error={errors.client_employee_number}>
                        {({ id, describedBy, invalid }) => (
                            <div className="flex flex-wrap items-start gap-3">
                                <Input id={id} value={form.data.client_employee_number} required aria-describedby={describedBy} aria-invalid={invalid || undefined} className="max-w-xs"
                                    onChange={(event) => { form.setData('client_employee_number', event.target.value); setClient({ state: 'idle', message: '' }); }}
                                    onBlur={() => { void findClient(); }} />
                                <Button type="button" variant="secondary" onClick={() => { void findClient(); }} disabled={client.state === 'checking'}>{text('check')}</Button>
                                {client.state === 'found' && <p role="status" className="self-center text-sm font-medium text-emerald-700 dark:text-emerald-400">{client.message}</p>}
                                {client.state === 'missing' && <p role="alert" className="self-center text-sm font-medium text-red-600 dark:text-red-400">{client.message}</p>}
                            </div>
                        )}
                    </FormField>

                    <fieldset className="space-y-3 rounded-control border border-dashed border-(--app-border) p-4">
                        <legend className="px-1 text-sm font-semibold text-(--app-foreground)">{text('co_clients')}</legend>
                        <p className="text-xs text-(--app-muted-foreground)">{text('co_clients_help')}</p>
                        {form.data.co_client_employee_numbers.map((number, index) => {
                            const check = coChecks[index] ?? { state: 'idle', message: '' };
                            return (
                                <FormField key={index} label={text('co_client').replace(':n', String(index + 2))} error={errors[`co_client_employee_numbers.${index}`]}>
                                    {({ id, describedBy, invalid }) => (
                                        <div className="flex flex-wrap items-start gap-3">
                                            <Input id={id} value={number} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="max-w-xs"
                                                onChange={(event) => {
                                                    form.setData('co_client_employee_numbers', form.data.co_client_employee_numbers.map((value, i) => (i === index ? event.target.value : value)));
                                                    setCoCheck(index, { state: 'idle', message: '' });
                                                }}
                                                onBlur={() => { void findCoClient(index); }} />
                                            <Button type="button" variant="secondary" onClick={() => { void findCoClient(index); }} disabled={check.state === 'checking'}>{text('check')}</Button>
                                            <Button type="button" variant="ghost" onClick={() => removeCoClient(index)}>{text('remove_client')}</Button>
                                            {check.state === 'found' && <p role="status" className="self-center text-sm font-medium text-emerald-700 dark:text-emerald-400">{check.message}</p>}
                                            {check.state === 'missing' && <p role="alert" className="self-center text-sm font-medium text-red-600 dark:text-red-400">{check.message}</p>}
                                        </div>
                                    )}
                                </FormField>
                            );
                        })}
                        <Button type="button" variant="outline" size="sm" onClick={addCoClient}>{text('add_client')}</Button>
                    </fieldset>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField label={text('respondent')} required error={errors.respondent_organization_uuid}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} value={form.data.respondent_organization_uuid} onChange={(event) => form.setData('respondent_organization_uuid', event.target.value)}
                                    required aria-describedby={describedBy} aria-invalid={invalid || undefined}>
                                    <option value="">{text('select')}</option>
                                    {organizations.map((org) => <option key={org.uuid} value={org.uuid}>{(lang === 'am' && org.name_am) || org.name_en}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <FormField label={text('respondent_address')} error={errors.respondent_address}>
                            {({ id, describedBy }) => (
                                <Input id={id} value={form.data.respondent_address} onChange={(event) => form.setData('respondent_address', event.target.value)} aria-describedby={describedBy} />
                            )}
                        </FormField>
                    </div>

                    <FormField label={text('case_type')} required error={errors.case_type_id}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} value={form.data.case_type_id} onChange={(event) => form.setData('case_type_id', event.target.value)} required
                                aria-describedby={describedBy} aria-invalid={invalid || undefined} className="max-w-md">
                                <option value="">{text('select')}</option>
                                {caseTypes.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                            </Select>
                        )}
                    </FormField>
                </Card>

                <Card className="space-y-5">
                    <RichTextEditor label={text('description_label')} required preset="document" minHeight={360} value={form.data.description}
                        onChange={(html) => form.setData('description', html)} error={errors.description} />
                    <RichTextEditor label={text('relief')} preset="document" minHeight={240} value={form.data.relief_requested}
                        onChange={(html) => form.setData('relief_requested', html)} error={errors.relief_requested} />
                </Card>

                <Card className="space-y-5">
                    <FormField label={text('power_of_attorney')} required error={errors.power_of_attorney}>
                        {({ id, describedBy, invalid }) => (
                            <input id={id} type="file" accept="application/pdf" required aria-describedby={describedBy} aria-invalid={invalid || undefined}
                                onChange={(event) => form.setData('power_of_attorney', event.target.files?.[0] ?? null)}
                                className="block w-full text-sm text-(--app-muted-foreground) file:mr-3 file:rounded-control file:border-0 file:bg-(--app-surface-muted) file:px-3 file:py-2 file:text-sm file:font-medium" />
                        )}
                    </FormField>
                    <FormField label={text('documents')} error={errors.documents ?? Object.entries(errors).find(([key]) => key.startsWith('documents.'))?.[1]}>
                        {({ id, describedBy }) => (
                            <input id={id} type="file" accept="application/pdf" multiple aria-describedby={describedBy}
                                onChange={(event) => form.setData('documents', Array.from(event.target.files ?? []))}
                                className="block w-full text-sm text-(--app-muted-foreground) file:mr-3 file:rounded-control file:border-0 file:bg-(--app-surface-muted) file:px-3 file:py-2 file:text-sm file:font-medium" />
                        )}
                    </FormField>
                    {client.state === 'missing' && <Alert tone="danger">{client.message}</Alert>}
                    <div className="flex flex-wrap gap-3 border-t border-(--app-border) pt-4">
                        <Button type="submit" disabled={form.processing || anyMissing}>{text('submit')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
