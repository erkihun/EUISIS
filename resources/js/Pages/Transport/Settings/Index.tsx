import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Settings = {
    require_pass_for_scan: boolean;
    allow_pay_as_you_go: boolean;
    scan_nonce_required: boolean;
};

const labels: Record<keyof Settings, string> = {
    require_pass_for_scan: 'transport.requirePassForScan',
    allow_pay_as_you_go: 'transport.allowPayAsYouGo',
    scan_nonce_required: 'transport.scanNonceRequired',
};

/* Named to avoid shadowing the globally shared `settings` branding prop. */
export default function Index({ transportSettings }: { transportSettings: Settings }) {
    const { t } = useLocale();
    const form = useForm<Settings>({
        require_pass_for_scan: transportSettings.require_pass_for_scan,
        allow_pay_as_you_go: transportSettings.allow_pay_as_you_go,
        scan_nonce_required: transportSettings.scan_nonce_required,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(route('transport.settings.update'), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('transport.settings')} />}
        >
            <Head title={t('transport.settings')} />

            <form onSubmit={submit} className="space-y-4">
                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {(Object.keys(labels) as Array<keyof Settings>).map((key, idx, arr) => (
                        <div
                            key={key}
                            className={`flex items-center justify-between px-5 py-4 ${idx < arr.length - 1 ? 'border-b border-gray-100 dark:border-slate-800' : ''}`}
                        >
                            <span className="text-sm font-medium text-gray-700 dark:text-slate-200">
                                {t(labels[key])}
                            </span>
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-slate-300">
                                <input
                                    type="checkbox"
                                    checked={form.data[key]}
                                    onChange={(e) => form.setData(key, e.target.checked)}
                                    className="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-700"
                                />
                                <span>{form.data[key] ? t('common.enabled') : t('common.disabled')}</span>
                            </label>
                        </div>
                    ))}
                </div>

                <div>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {form.processing ? t('common.saving') : t('common.save')}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
