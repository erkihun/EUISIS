import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Briefcase } from '@/Components/Icons';
import CourtAuthShell, { courtAuthButtonClass, courtAuthInputClass, courtAuthLabelClass } from '@/court/components/auth/CourtAuthShell';
import { usePublicText } from '@/court/i18n/usePublicText';

interface Application {
    first_name: string;
    middle_name: string;
    last_name: string;
    gender: string;
    email: string;
    phone: string;
    national_id_number: string;
    address: string;
    license_number: string;
    license_document: File | null;
}

type TextField = Exclude<keyof Application, 'gender' | 'license_document'>;

/**
 * A lawyer applies to be registered (LawyerAuthController@register, EUISIS
 * ADR 0013 #13 as amended on 2026-10-29). The account stays closed until the
 * registry verifies the licence on its Lawyers screen; the approval email
 * carries the link to choose a password.
 */
export default function LawyerRegister() {
    const { t } = usePublicText();
    const text = (key: string) => t(`cm.lawyer.apply.${key}`);
    const label = (key: string) => t(`cm.lawyer.register.${key}`);
    const form = useForm<Application>({
        first_name: '', middle_name: '', last_name: '', gender: '', email: '', phone: '', national_id_number: '', address: '', license_number: '', license_document: null,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.lawyer.register.submit'), { forceFormData: true, preserveScroll: true });
    };

    const error = (key: keyof Application) => form.errors[key]
        ? <p id={`${key}-error`} role="alert" className="mt-1.5 text-xs text-red-600 dark:text-red-400">{form.errors[key]}</p>
        : null;

    const field = (key: TextField, options: { type?: string; autoComplete?: string; labelKey?: string } = {}) => (
        <div>
            <label htmlFor={key} className={courtAuthLabelClass}>{label(options.labelKey ?? key)}</label>
            <input id={key} name={key} type={options.type ?? 'text'} value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)}
                required autoComplete={options.autoComplete} aria-invalid={form.errors[key] ? true : undefined}
                aria-describedby={form.errors[key] ? `${key}-error` : undefined} className={courtAuthInputClass(Boolean(form.errors[key]))} />
            {error(key)}
        </div>
    );

    const section = 'mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400';

    return (
        <CourtAuthShell wide title={text('title')} subtitle={text('subtitle')} icon={<Briefcase className="h-5 w-5" />} backToLogin={false}
            footer={(
                <p className="mt-6 border-t border-gray-100 pt-5 text-center text-sm text-gray-500 dark:border-slate-800 dark:text-slate-400">
                    {text('have_account')}{' '}
                    <Link href={route('court.lawyer.login')} className="font-semibold text-primary hover:underline">{text('sign_in')}</Link>
                </p>
            )}>
            <form onSubmit={submit} className="space-y-6" noValidate encType="multipart/form-data">
                <fieldset>
                    <legend className={section}>{text('personal')}</legend>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        {field('first_name', { autoComplete: 'given-name' })}
                        {field('middle_name', { autoComplete: 'additional-name' })}
                        {field('last_name', { autoComplete: 'family-name' })}
                    </div>
                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="gender" className={courtAuthLabelClass}>{label('gender')}</label>
                            <select id="gender" name="gender" value={form.data.gender} onChange={(event) => form.setData('gender', event.target.value)} required
                                aria-invalid={form.errors.gender ? true : undefined} aria-describedby={form.errors.gender ? 'gender-error' : undefined}
                                className={courtAuthInputClass(Boolean(form.errors.gender))}>
                                <option value="">—</option>
                                <option value="male">{label('male')}</option>
                                <option value="female">{label('female')}</option>
                            </select>
                            {error('gender')}
                        </div>
                        {field('national_id_number', { labelKey: 'national_id', autoComplete: 'off' })}
                        {field('email', { type: 'email', autoComplete: 'email' })}
                        {field('phone', { type: 'tel', autoComplete: 'tel' })}
                    </div>
                    <div className="mt-4">{field('address', { autoComplete: 'street-address' })}</div>
                </fieldset>

                <fieldset>
                    <legend className={section}>{text('license_section')}</legend>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {field('license_number', { autoComplete: 'off' })}
                        <div>
                            <label htmlFor="license_document" className={courtAuthLabelClass}>{text('license_document')}</label>
                            <input id="license_document" name="license_document" type="file" accept="application/pdf" required
                                onChange={(event) => form.setData('license_document', event.target.files?.[0] ?? null)}
                                aria-invalid={form.errors.license_document ? true : undefined}
                                aria-describedby={form.errors.license_document ? 'license_document-error license_document-help' : 'license_document-help'}
                                className="block w-full text-sm text-gray-600 file:mr-3 file:rounded-card file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-slate-300 dark:file:bg-slate-800 dark:file:text-slate-200" />
                            <p id="license_document-help" className="mt-1.5 text-xs text-gray-500 dark:text-slate-400">{text('license_help')}</p>
                            {error('license_document')}
                        </div>
                    </div>
                </fieldset>

                <button type="submit" disabled={form.processing} className={courtAuthButtonClass}>{text('submit')}</button>
            </form>
        </CourtAuthShell>
    );
}
