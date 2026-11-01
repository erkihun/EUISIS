import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { Alert, AppToaster, Button, FormField, Input, LocalizedUiProvider, Select, cx } from '@/Components/ui';
import { useBranding } from '@/hooks/useBranding';
import { AuthTopBar } from '@/court/components/applicant/AuthShell';
import PasswordInput from '@/court/components/applicant/PasswordInput';
import { useApplicantPagesText } from '@/court/i18n/useApplicantPagesText';

/** "1234567890123456" → "1234 5678 9012 3456", as the Blade form formatted the National ID while typing. */
const formatNationalId = (value: string): string => (value || '').replace(/[^0-9]/g, '').slice(0, 16).match(/.{1,4}/g)?.join(' ') ?? '';

/** The local part of the phone (the form sends "+251" in front of it), digits only, at most 10. */
const sanitizePhone = (value: string): string => value.replace(/[^0-9]/g, '').slice(0, 10);

type TextFieldName = 'first_name' | 'middle_name' | 'last_name' | 'position' | 'organization_name' | 'email' | 'address';

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-4 border-t border-(--app-border) pt-6 first:border-t-0 first:pt-0">
            <h2 className="text-xs font-semibold uppercase tracking-wider text-(--app-muted-foreground)">{title}</h2>
            {children}
        </section>
    );
}

/**
 * Self-registration of an applicant (the Blade applicant/auth/register):
 * whether the person is a lawyer (then their credential, a PDF), who they
 * are, their organisation and their account. The phone is typed without the
 * country code and sent as +251…; the National ID is grouped in fours while
 * typing and sent as typed (the server keeps the digits). On success the
 * person is signed in and lands on the portal dashboard.
 */
export default function ApplicantRegister() {
    const { t, text } = useApplicantPagesText();
    const { brandName } = useBranding();
    const form = useForm({
        is_lawyer: '0',
        lawyer_document: null as File | null,
        first_name: '',
        middle_name: '',
        last_name: '',
        gender: '',
        phone: '',
        national_id_number: '',
        position: '',
        organization_name: '',
        email: '',
        address: '',
        password: '',
        password_confirmation: '',
    });
    const { data, setData, errors } = form;
    const isLawyer = data.is_lawyer === '1';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            phone: `+251${sanitizePhone(values.phone)}`,
            lawyer_document: values.is_lawyer === '1' ? values.lawyer_document : null,
        }));
        form.post(route('court.applicant.register.submit'), { forceFormData: true, onFinish: () => form.reset('password', 'password_confirmation') });
    };

    const textField = (field: TextFieldName, label: string, props: { type?: string; autoComplete?: string } = {}) => (
        <FormField label={label} required error={errors[field]}>
            {({ id, describedBy, invalid }) => (
                <Input id={id} name={field} type={props.type ?? 'text'} autoComplete={props.autoComplete} value={data[field]} onChange={(event) => setData(field, event.target.value)} required aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
            )}
        </FormField>
    );

    return (
        <LocalizedUiProvider>
            <Head title={t('cm.auth.sign_up_title')} />
            <AppToaster flashToasts={false} />
            <div className="flex min-h-screen flex-col bg-(--app-surface-muted)">
                <AuthTopBar />
                <main className="flex flex-1 justify-center px-4 py-8 sm:py-12">
                    <div className="w-full max-w-4xl rounded-panel border border-(--app-border) bg-(--app-surface) px-5 py-7 shadow-sm sm:px-9">
                        <div className="mb-6">
                            <h1 className="text-2xl font-bold tracking-tight text-(--app-foreground)">{t('cm.auth.sign_up_title')}</h1>
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">{t('cm.auth.sign_up_subtitle')}</p>
                        </div>

                        {form.hasErrors && <Alert tone="danger" className="mb-6">{t('cm.auth.please_fix_errors')}</Alert>}

                        <form onSubmit={submit} className="space-y-6" encType="multipart/form-data">
                            <Section title={t('cm.auth.lawyer_question')}>
                                <div className="flex flex-wrap gap-6" role="radiogroup" aria-label={t('cm.auth.lawyer_question')}>
                                    {([['1', t('cm.auth.yes')], ['0', t('cm.auth.no')]] as const).map(([value, label]) => (
                                        <label key={value} className="flex items-center gap-2 text-sm font-medium text-(--app-foreground)">
                                            <input type="radio" name="is_lawyer" value={value} checked={data.is_lawyer === value} onChange={() => setData('is_lawyer', value)} className="h-4 w-4 border-(--app-border-strong) text-primary focus:ring-primary" />
                                            {label}
                                        </label>
                                    ))}
                                </div>
                                {errors.is_lawyer && <p className="text-xs text-red-600 dark:text-red-400">{errors.is_lawyer}</p>}
                                {isLawyer && (
                                    <FormField label={t('cm.auth.lawyer_document')} required error={errors.lawyer_document} description={errors.lawyer_document ? undefined : t('cm.auth.lawyer_document_hint')}>
                                        {({ id, describedBy, invalid }) => (
                                            <Input id={id} name="lawyer_document" type="file" accept=".pdf" required onChange={(event) => setData('lawyer_document', event.target.files?.[0] ?? null)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full cursor-pointer py-1.5 file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium" />
                                        )}
                                    </FormField>
                                )}
                            </Section>

                            <Section title={t('cm.auth.personal_info')}>
                                <div className="grid gap-4 md:grid-cols-3">
                                    {textField('first_name', t('cm.auth.first_name'), { autoComplete: 'given-name' })}
                                    {textField('middle_name', t('cm.auth.middle_name'), { autoComplete: 'additional-name' })}
                                    {textField('last_name', t('cm.auth.last_name'), { autoComplete: 'family-name' })}
                                </div>
                                <div className="grid gap-4 md:grid-cols-3">
                                    <FormField label={t('cm.auth.gender')} required error={errors.gender}>
                                        {({ id, describedBy, invalid }) => (
                                            <Select id={id} name="gender" value={data.gender} onChange={(event) => setData('gender', event.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full">
                                                <option value="">{t('cm.auth.select_option')}</option>
                                                <option value="male">{t('cm.auth.male')}</option>
                                                <option value="female">{t('cm.auth.female')}</option>
                                            </Select>
                                        )}
                                    </FormField>
                                    <FormField label={t('cm.auth.phone')} required error={errors.phone} description={errors.phone ? undefined : t('cm.auth.phone_hint').replace(':min', '9').replace(':max', '10')}>
                                        {({ id, describedBy, invalid }) => (
                                            <div className={cx('flex rounded-control border bg-(--app-surface) focus-within:ring-2 focus-within:ring-primary', invalid ? 'border-red-400 dark:border-red-500' : 'border-(--app-border-strong)')}>
                                                <span className="flex items-center border-r border-(--app-border) px-3 text-sm font-medium text-(--app-muted-foreground)" title={text('phone_country_code')}>+251</span>
                                                <input
                                                    id={id}
                                                    type="tel"
                                                    inputMode="numeric"
                                                    placeholder="9XXXXXXXXX"
                                                    minLength={9}
                                                    maxLength={10}
                                                    pattern="[0-9]{9,10}"
                                                    required
                                                    autoComplete="tel-national"
                                                    value={data.phone}
                                                    onChange={(event) => setData('phone', sanitizePhone(event.target.value))}
                                                    aria-describedby={describedBy}
                                                    aria-invalid={invalid || undefined}
                                                    className="h-(--control-h-md) min-w-0 flex-1 rounded-r-control border-0 bg-transparent px-3 text-sm text-(--app-foreground) focus:outline-hidden focus:ring-0"
                                                />
                                            </div>
                                        )}
                                    </FormField>
                                    <FormField label={t('cm.auth.national_id')} required error={errors.national_id_number} description={errors.national_id_number ? undefined : t('cm.auth.national_id_hint')}>
                                        {({ id, describedBy, invalid }) => (
                                            <Input
                                                id={id}
                                                name="national_id_number"
                                                value={data.national_id_number}
                                                onChange={(event) => setData('national_id_number', formatNationalId(event.target.value))}
                                                required
                                                inputMode="numeric"
                                                autoComplete="off"
                                                maxLength={19}
                                                pattern="\d{4}\s\d{4}\s\d{4}\s\d{4}"
                                                title={t('cm.auth.national_id_hint')}
                                                placeholder={t('cm.auth.national_id_placeholder')}
                                                aria-describedby={describedBy}
                                                aria-invalid={invalid || undefined}
                                                className="w-full"
                                            />
                                        )}
                                    </FormField>
                                </div>
                            </Section>

                            <Section title={t('cm.auth.organization_info')}>
                                <div className="grid gap-4 md:grid-cols-2">
                                    {textField('position', t('cm.auth.position'), { autoComplete: 'organization-title' })}
                                    {textField('organization_name', t('cm.auth.organization_name'), { autoComplete: 'organization' })}
                                </div>
                            </Section>

                            <Section title={t('cm.auth.account_details')}>
                                <div className="grid gap-4 md:grid-cols-2">
                                    {textField('email', t('cm.auth.email'), { type: 'email', autoComplete: 'email' })}
                                    {textField('address', t('cm.auth.address'), { autoComplete: 'street-address' })}
                                </div>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <FormField label={t('cm.auth.password')} required error={errors.password}>
                                        {({ id, describedBy, invalid }) => (
                                            <PasswordInput id={id} name="password" value={data.password} onChange={(event) => setData('password', event.target.value)} required autoComplete="new-password" aria-describedby={describedBy} aria-invalid={invalid || undefined} />
                                        )}
                                    </FormField>
                                    <FormField label={t('cm.auth.confirm_password')} required error={errors.password_confirmation}>
                                        {({ id, describedBy, invalid }) => (
                                            <PasswordInput id={id} name="password_confirmation" value={data.password_confirmation} onChange={(event) => setData('password_confirmation', event.target.value)} required autoComplete="new-password" aria-describedby={describedBy} aria-invalid={invalid || undefined} />
                                        )}
                                    </FormField>
                                </div>
                            </Section>

                            <div className="flex flex-col-reverse gap-3 border-t border-(--app-border) pt-6 sm:flex-row sm:items-center sm:justify-between">
                                <Link href={route('court.applicant.login')} className="text-sm font-medium text-primary hover:underline">{t('cm.auth.already_have_account')}</Link>
                                <Button type="submit" disabled={form.processing}>{t('cm.auth.create_account_button')}</Button>
                            </div>
                        </form>
                    </div>
                </main>
                <footer className="pb-6 text-center text-xs text-(--app-muted-foreground)">&copy; {new Date().getFullYear()} {brandName}</footer>
            </div>
        </LocalizedUiProvider>
    );
}
