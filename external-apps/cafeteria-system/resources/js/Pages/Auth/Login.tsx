import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

export default function Login() {
    const { t } = useLocale();
    const form = useForm({ email: '', password: '', remember: false });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/login');
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4">
            <form onSubmit={submit} className="w-full max-w-sm rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div className="mb-5 text-center">
                    <span className="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-600 text-lg font-bold text-white">C</span>
                    <h1 className="mt-3 text-lg font-bold text-slate-900">{t('app.name')}</h1>
                    <p className="text-xs text-slate-500">{t('extra.signInWithAccount')}</p>
                </div>

                <label className="mb-3 block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">{t('login.email')}</span>
                    <input
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                    />
                </label>

                <label className="mb-4 block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">{t('login.password')}</span>
                    <input
                        type="password"
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                    />
                </label>

                {form.errors.email && <p className="mb-3 text-xs text-red-600">{form.errors.email}</p>}

                <button
                    type="submit"
                    disabled={form.processing}
                    className="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                >
                    {form.processing ? 'Signing in...' : 'Sign in'}
                </button>
            </form>
        </div>
    );
}
