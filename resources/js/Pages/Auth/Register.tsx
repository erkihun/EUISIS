import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

type Props = {
    otpSent?: boolean;
    pendingEmployeeNumber?: string | null;
    status?: string | null;
};

export default function Register({ otpSent = false, pendingEmployeeNumber = null, status = null }: Props) {
    const form = useForm({
        employee_number: pendingEmployeeNumber ?? '',
        otp: '',
        password: '',
        password_confirmation: '',
    });
    const [showPassword, setShowPassword] = useState(false);

    useEffect(() => {
        if (pendingEmployeeNumber) form.setData('employee_number', pendingEmployeeNumber);
    }, [pendingEmployeeNumber]);

    const sendCode = () => {
        form.post(route('register.send-otp'), {
            preserveScroll: true,
            only: ['otpSent', 'pendingEmployeeNumber', 'status', 'errors'],
        });
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('register'), {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    const inputClass = (hasError: boolean) => `w-full rounded-lg border px-3 py-2.5 text-sm text-gray-900 outline-none transition focus:ring-2 focus:ring-blue-500 dark:text-slate-100 ${
        hasError
            ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-950/20'
            : 'border-gray-300 bg-white dark:border-slate-700 dark:bg-slate-800'
    }`;

    return (
        <div className="flex min-h-screen bg-gray-50 dark:bg-[#0d0f14]">
            <Head title="Create Account" />

            <aside className="relative hidden w-[44%] overflow-hidden bg-gradient-to-br from-blue-950 via-slate-950 to-slate-900 lg:flex">
                <div className="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-blue-500/20 blur-3xl" />
                <div className="relative z-10 flex w-full flex-col justify-between p-12 text-white">
                    <div className="flex items-center gap-3">
                        <ApplicationLogo className="h-14 w-14 fill-white" />
                        <div><p className="font-bold">EUISIS</p><p className="text-xs text-blue-200/70">Employee Unified Identity System</p></div>
                    </div>
                    <div className="max-w-md">
                        <p className="mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-orange-300">Secure employee registration</p>
                        <h1 className="text-4xl font-bold leading-tight">Verify your identity before creating an account.</h1>
                        <div className="mt-8 space-y-4 text-sm text-slate-300">
                            <p>1. Enter the employee number assigned by HR.</p>
                            <p>2. Receive one code at your registered email and phone.</p>
                            <p>3. Enter the code and choose your password.</p>
                        </div>
                    </div>
                    <p className="text-xs text-slate-500">Government of Ethiopia · Addis Ababa City Administration</p>
                </div>
            </aside>

            <main className="flex flex-1 items-center justify-center px-6 py-10">
                <div className="w-full max-w-md">
                    <div className="mb-6 flex items-center gap-3 lg:hidden">
                        <ApplicationLogo className="h-10 w-10 fill-slate-900 dark:fill-white" />
                        <span className="font-bold dark:text-white">EUISIS</span>
                    </div>

                    <div className="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Create employee account</h2>
                        <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">We verify the contact details already recorded by HR.</p>

                        {status && (
                            <div className="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">
                                {status}
                            </div>
                        )}

                        <form onSubmit={submit} className="mt-6 space-y-4">
                            <div>
                                <label htmlFor="employee_number" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">Employee number</label>
                                <div className="flex gap-2">
                                    <input id="employee_number" value={form.data.employee_number} onChange={(e) => form.setData('employee_number', e.target.value)} autoFocus autoComplete="off" placeholder="e.g. EMP-001234" className={inputClass(Boolean(form.errors.employee_number))} />
                                    <button type="button" onClick={sendCode} disabled={form.processing || !form.data.employee_number} className="shrink-0 rounded-lg border border-blue-600 px-4 text-sm font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50 dark:text-blue-300 dark:hover:bg-blue-950/40">
                                        {otpSent ? 'Resend' : 'Send code'}
                                    </button>
                                </div>
                                {form.errors.employee_number && <p className="mt-1 text-xs text-red-500">{form.errors.employee_number}</p>}
                            </div>

                            {otpSent && (
                                <>
                                    <div>
                                        <label htmlFor="otp" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">6-digit verification code</label>
                                        <input id="otp" inputMode="numeric" maxLength={6} value={form.data.otp} onChange={(e) => form.setData('otp', e.target.value.replace(/\D/g, ''))} autoComplete="one-time-code" placeholder="000000" className={`${inputClass(Boolean(form.errors.otp))} tracking-[0.35em]`} />
                                        {form.errors.otp && <p className="mt-1 text-xs text-red-500">{form.errors.otp}</p>}
                                    </div>

                                    <div>
                                        <label htmlFor="password" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">Password</label>
                                        <div className="relative">
                                            <input id="password" type={showPassword ? 'text' : 'password'} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="new-password" className={`${inputClass(Boolean(form.errors.password))} pr-16`} />
                                            <button type="button" onClick={() => setShowPassword((value) => !value)} className="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-gray-500">{showPassword ? 'Hide' : 'Show'}</button>
                                        </div>
                                        {form.errors.password && <p className="mt-1 text-xs text-red-500">{form.errors.password}</p>}
                                    </div>

                                    <div>
                                        <label htmlFor="password_confirmation" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-300">Confirm password</label>
                                        <input id="password_confirmation" type={showPassword ? 'text' : 'password'} value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" className={inputClass(Boolean(form.errors.password_confirmation))} />
                                        {form.errors.password_confirmation && <p className="mt-1 text-xs text-red-500">{form.errors.password_confirmation}</p>}
                                    </div>

                                    <button type="submit" disabled={form.processing} className="w-full rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-60">
                                        {form.processing ? 'Verifying…' : 'Verify and create account'}
                                    </button>
                                </>
                            )}
                        </form>
                    </div>

                    <p className="mt-5 text-center text-sm text-gray-500 dark:text-slate-400">Already have an account? <Link href={route('login')} className="font-semibold text-blue-700 dark:text-blue-300">Sign in</Link></p>
                </div>
            </main>
        </div>
    );
}
