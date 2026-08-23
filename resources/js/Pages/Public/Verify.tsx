import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import PublicLayout from '@/Layouts/PublicLayout';
import { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

function BadgeCheckIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
            <polyline points="9 12 11 14 15 10" />
        </svg>
    );
}

export default function PublicVerify() {
    const { t } = useLocale();
    const [value, setValue] = useState('');

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const trimmed = value.trim();
        if (!trimmed) return;
        router.get(route('id-checker.show', { cardUuid: trimmed }));
    };

    return (
        <PublicLayout title={t('home.verifyPageTitle')}>
            <div className="mx-auto max-w-lg px-4 py-16 sm:px-6">
                <div className="mb-8 flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-600 text-white">
                        <BadgeCheckIcon className="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">{t('home.verifyPageTitle')}</h1>
                        <p className="text-sm text-gray-500 dark:text-slate-400">{t('home.verifyPageSubtitle')}</p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <label htmlFor="card-ref" className="block text-sm font-medium text-gray-700 dark:text-slate-300">
                        {t('home.verifyInputLabel')}
                    </label>
                    <input
                        id="card-ref"
                        type="text"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        placeholder={t('home.verifyInputPlaceholder')}
                        className="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500"
                    />
                    <button
                        type="submit"
                        disabled={!value.trim()}
                        className="mt-4 w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                    >
                        {t('home.verifyButton')}
                    </button>
                </form>
            </div>
        </PublicLayout>
    );
}
