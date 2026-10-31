import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { CheckCircle, EyeIcon } from '@/Components/Icons';
import { Alert, Button, Card, FormField, Input, Select, badgeClassName, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import AccountTabs from '@/court/components/applicant/AccountTabs';
import PasswordInput from '@/court/components/applicant/PasswordInput';
import type { ApplicantProfileProps } from '@/court/types/applicant';

/** The Blade profile's National ID formatter: letters and digits only, 16 at most, upper case, in groups of four. */
const formatNationalId = (value: string): string => ((value || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 16).toUpperCase().match(/.{1,4}/g) ?? []).join(' ');

const SECURITY_FIELDS = ['current_password', 'password', 'password_confirmation'];

type TextFieldName = 'first_name' | 'middle_name' | 'last_name' | 'phone' | 'email' | 'address' | 'position' | 'organization_name';

/**
 * The applicant's profile (the Blade applicant/profile/edit): the account
 * details (and, for a lawyer, the credential document) on one tab, the
 * password on the other, the signed-in sessions one link away. The security
 * tab opens on #security, after a password change and when it was refused.
 */
export default function ApplicantProfile({ profile, securitySuccess }: ApplicantProfileProps) {
    const { t } = useLocale();
    const pageErrors = (usePage().props as { errors?: Record<string, string> }).errors ?? {};
    const securityError = SECURITY_FIELDS.some((field) => Boolean(pageErrors[field]));
    const [tab, setTab] = useState<'profile' | 'security'>(() => (securityError || securitySuccess ? 'security' : 'profile'));

    useEffect(() => {
        const fromHash = () => { if (window.location.hash === '#security') setTab('security'); else if (window.location.hash === '#profile') setTab('profile'); };
        fromHash();
        window.addEventListener('hashchange', fromHash);
        return () => window.removeEventListener('hashchange', fromHash);
    }, []);

    useEffect(() => {
        if (securityError || securitySuccess) setTab('security');
    }, [securityError, securitySuccess]);

    const select = (next: 'profile' | 'security') => {
        setTab(next);
        window.history.replaceState(window.history.state, '', `#${next}`);
    };

    const form = useForm({
        first_name: profile.first_name,
        middle_name: profile.middle_name,
        last_name: profile.last_name,
        gender: profile.gender ?? '',
        phone: profile.phone,
        national_id_number: formatNationalId(profile.national_id_number),
        email: profile.email,
        address: profile.address,
        position: profile.position,
        organization_name: profile.organization_name,
        lawyer_document: null as File | null,
    });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });

    const saveProfile = (event: FormEvent) => {
        event.preventDefault();
        if (form.data.lawyer_document) {
            // A file goes as multipart, which PHP reads only from a POST: spoof the PATCH.
            form.transform((data) => ({ ...data, _method: 'patch' }));
            form.post(route('court.applicant.profile.update'), { forceFormData: true, preserveScroll: true, onSuccess: () => form.setData('lawyer_document', null) });
        } else {
            form.transform((data) => data);
            form.patch(route('court.applicant.profile.update'), { preserveScroll: true });
        }
    };

    const savePassword = (event: FormEvent) => {
        event.preventDefault();
        password.patch(route('court.applicant.profile.password.update'), { preserveScroll: true, onFinish: () => password.reset() });
    };

    const errorList = Object.values(pageErrors).filter(Boolean);
    const field = (name: TextFieldName, label: string, props: { type?: string; autoComplete?: string; inputMode?: 'tel' } = {}) => (
        <FormField label={label} error={pageErrors[name]}>
            {({ id, describedBy, invalid }) => (
                <Input id={id} name={name} type={props.type ?? 'text'} autoComplete={props.autoComplete} inputMode={props.inputMode} value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />
            )}
        </FormField>
    );

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('cm.auth.my_profile')} description={t('cm.auth.fill_basic_info')} backHref={route('court.applicant.dashboard')} />}>
            <Head title={t('cm.auth.my_profile')} />

            <Card>
                <AccountTabs active={tab} onSelect={select} />

                {errorList.length > 0 && (
                    <Alert tone="danger" title={t('cm.auth.please_fix_errors')} className="mb-5">
                        <ul className="list-disc space-y-0.5 pl-5">{errorList.map((message) => <li key={message}>{message}</li>)}</ul>
                    </Alert>
                )}

                {tab === 'profile' && (
                    <form id="profile" onSubmit={saveProfile} className="space-y-8" encType="multipart/form-data">
                        <section>
                            <div className="mb-3 flex flex-wrap items-center gap-2">
                                <h2 className="font-semibold text-(--app-foreground)">{t('cm.auth.basic_information')}</h2>
                                <span className={badgeClassName('info')}>{t('cm.auth.profile_basic_info')}</span>
                            </div>
                            <div className="grid gap-4 md:grid-cols-3">
                                {field('first_name', t('cm.auth.first_name'), { autoComplete: 'given-name' })}
                                {field('middle_name', t('cm.auth.middle_name'), { autoComplete: 'additional-name' })}
                                {field('last_name', t('cm.auth.last_name'), { autoComplete: 'family-name' })}
                            </div>
                            <div className="mt-4 grid gap-4 md:grid-cols-3">
                                <FormField label={t('cm.auth.gender')} error={pageErrors.gender}>
                                    {({ id, describedBy, invalid }) => (
                                        <Select id={id} name="gender" value={form.data.gender} onChange={(event) => form.setData('gender', event.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full">
                                            <option value="">— {t('cm.auth.select_option')} —</option>
                                            <option value="male">{t('cm.auth.male')}</option>
                                            <option value="female">{t('cm.auth.female')}</option>
                                        </Select>
                                    )}
                                </FormField>
                                {field('phone', t('cm.auth.phone'), { autoComplete: 'tel', inputMode: 'tel' })}
                                <FormField label={t('cm.auth.national_id')} required error={pageErrors.national_id_number} description={t('cm.auth.national_id_hint')}>
                                    {({ id, describedBy, invalid }) => (
                                        <Input
                                            id={id}
                                            name="national_id_number"
                                            value={form.data.national_id_number}
                                            onChange={(event) => form.setData('national_id_number', formatNationalId(event.target.value))}
                                            required
                                            inputMode="numeric"
                                            autoComplete="off"
                                            maxLength={19}
                                            pattern="\d{4}\s\d{4}\s\d{4}\s\d{4}"
                                            title={t('cm.auth.national_id_format')}
                                            placeholder={t('cm.auth.national_id_placeholder')}
                                            aria-describedby={describedBy}
                                            aria-invalid={invalid || undefined}
                                            className="w-full"
                                        />
                                    )}
                                </FormField>
                            </div>
                            <div className="mt-4 grid gap-4 md:grid-cols-2">
                                {field('email', t('cm.auth.email'), { type: 'email', autoComplete: 'email' })}
                                {field('address', t('cm.auth.address'), { autoComplete: 'street-address' })}
                                {field('position', t('cm.auth.position'), { autoComplete: 'organization-title' })}
                                {field('organization_name', t('cm.auth.organization_name'), { autoComplete: 'organization' })}
                            </div>
                        </section>

                        {profile.is_lawyer && (
                            <section className="border-t border-(--app-border) pt-6">
                                <h2 className="mb-3 font-semibold text-(--app-foreground)">{t('cm.auth.lawyer_document')}</h2>
                                {profile.has_lawyer_document && (
                                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-card border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800/50 dark:bg-emerald-900/20">
                                        <span className="inline-flex items-center gap-2 text-sm font-medium text-emerald-800 dark:text-emerald-300"><CheckCircle className="h-4 w-4" />{t('cm.auth.lawyer_document_uploaded')}</span>
                                        <a href={route('court.applicant.profile.lawyer-document')} target="_blank" rel="noopener" className={buttonClassName({ variant: 'outline', size: 'sm', className: 'gap-1.5' })}><EyeIcon className="h-4 w-4" />{t('cm.cases.show.view_lawyer_document')}</a>
                                    </div>
                                )}
                                <FormField label={profile.has_lawyer_document ? t('cm.auth.lawyer_document_replace') : t('cm.auth.lawyer_document')} error={pageErrors.lawyer_document} description={t('cm.auth.lawyer_document_hint')}>
                                    {({ id, describedBy, invalid }) => (
                                        <Input id={id} name="lawyer_document" type="file" accept=".pdf" onChange={(event) => form.setData('lawyer_document', event.target.files?.[0] ?? null)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full cursor-pointer py-1.5 file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium" />
                                    )}
                                </FormField>
                            </section>
                        )}

                        <div className="flex flex-wrap items-center gap-2 border-t border-(--app-border) pt-5">
                            <Button type="submit" disabled={form.processing}>{t('cm.auth.save_changes')}</Button>
                            <Link href={route('court.applicant.dashboard')} className={buttonClassName({ variant: 'outline' })}>{t('cm.auth.back')}</Link>
                        </div>
                    </form>
                )}

                {tab === 'security' && (
                    <section id="security" className="space-y-6">
                        <div className="rounded-card border border-(--app-border) bg-(--app-surface-muted) p-5">
                            <h2 className="text-lg font-semibold text-(--app-foreground)">{t('cm.auth.security_section')}</h2>
                            <p className="mt-1 text-sm leading-6 text-(--app-muted-foreground)">{t('cm.auth.profile.password_security_hint')}</p>
                        </div>
                        {securitySuccess && <Alert tone="success">{securitySuccess}</Alert>}
                        <form onSubmit={savePassword} className="rounded-card border border-(--app-border) p-5">
                            <h3 className="text-base font-semibold text-(--app-foreground)">{t('cm.auth.change_password')}</h3>
                            <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.auth.password_optional_hint')}</p>
                            <div className="mt-5 grid gap-4 md:grid-cols-3">
                                <FormField label={t('cm.auth.current_password')} required error={pageErrors.current_password}>
                                    {({ id, describedBy, invalid }) => <PasswordInput id={id} name="current_password" value={password.data.current_password} onChange={(event) => password.setData('current_password', event.target.value)} required autoComplete="current-password" aria-describedby={describedBy} aria-invalid={invalid || undefined} />}
                                </FormField>
                                <FormField label={t('cm.auth.new_password')} required error={pageErrors.password}>
                                    {({ id, describedBy, invalid }) => <PasswordInput id={id} name="password" value={password.data.password} onChange={(event) => password.setData('password', event.target.value)} required autoComplete="new-password" aria-describedby={describedBy} aria-invalid={invalid || undefined} />}
                                </FormField>
                                <FormField label={t('cm.auth.confirm_new_password')} required error={pageErrors.password_confirmation}>
                                    {({ id, describedBy, invalid }) => <PasswordInput id={id} name="password_confirmation" value={password.data.password_confirmation} onChange={(event) => password.setData('password_confirmation', event.target.value)} required autoComplete="new-password" aria-describedby={describedBy} aria-invalid={invalid || undefined} />}
                                </FormField>
                            </div>
                            <div className="mt-5 flex justify-end">
                                <Button type="submit" disabled={password.processing}>{t('cm.auth.save_changes')}</Button>
                            </div>
                        </form>
                    </section>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
