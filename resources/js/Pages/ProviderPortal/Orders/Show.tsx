import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Provider = { id: string; code: string; name_en: string; name_am?: string | null };
type Order = {
    id: string; order_number: string; status: string;
    employee?: { name?: string | null; employee_number?: string | null } | null;
    menu?: { title_en?: string | null; title_am?: string | null } | null;
};

const STATUS_CLS: Record<string, string> = {
    pending:    'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    confirmed:  'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-[color:var(--color-primary)]',
    preparing:  'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    ready:      'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-400',
    served:     'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    rejected:   'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    cancelled:  'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400',
};

const ACTION_TRANSITIONS: { status: string; labelKey: string; cls: string }[] = [
    { status: 'confirm', labelKey: 'markConfirmed', cls: 'bg-blue-500 text-white hover:bg-[color:var(--color-primary-hover)]' },
    { status: 'prepare', labelKey: 'markPreparing', cls: 'bg-purple-500 text-white hover:bg-purple-600' },
    { status: 'serve', labelKey: 'markServed', cls: 'bg-emerald-500 text-white hover:bg-emerald-600' },
    { status: 'reject', labelKey: 'reject', cls: 'bg-red-500 text-white hover:bg-red-600' },
    { status: 'cancel', labelKey: 'cancel', cls: 'border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800' },
];

function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-3 border-b border-gray-100 py-3 text-sm last:border-0 dark:border-slate-800">
            <span className="text-gray-500 dark:text-slate-400">{label}</span>
            <span className="col-span-2 font-medium text-gray-900 dark:text-slate-100">{children}</span>
        </div>
    );
}

export default function OrderShow({ providers, selected_provider_id, order }: {
    providers: Provider[];
    selected_provider_id: string | null;
    order: Order;
}) {
    const { t, locale } = useLocale();
    const menuTitle = (locale === 'am' && order.menu?.title_am) ? order.menu.title_am : order.menu?.title_en;

    function setStatus(action: string) {
        router.post(route(`provider.portal.orders.${action}`, order.id), {}, { preserveScroll: true });
    }

    return (
        <CafeteriaProviderPortalLayout
            title={order.order_number}
            header={
                <PageHeader
                    title={order.order_number}
                    backHref={route('provider.portal.orders.index')}
                />
            }
            providers={providers}
            selectedProviderId={selected_provider_id}
        >
            <Head title={order.order_number} />

            <div className="mx-auto max-w-2xl space-y-4">
                {/* Details card */}
                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="border-b border-gray-100 px-5 py-4 dark:border-slate-800">
                        <p className="text-xs font-semibold text-gray-500 dark:text-slate-400">{t('providerPortal.orders')}</p>
                        <p className="mt-1 font-mono text-sm font-medium text-gray-900 dark:text-slate-100">{order.order_number}</p>
                    </div>
                    <div className="px-5">
                        <DetailRow label={t('providerPortal.employee')}>
                            {order.employee?.name ?? '—'}
                            {order.employee?.employee_number && (
                                <span className="ml-2 font-mono text-xs text-gray-400">#{order.employee.employee_number}</span>
                            )}
                        </DetailRow>
                        <DetailRow label={t('providerPortal.menuTitle')}>
                            {menuTitle ?? '—'}
                        </DetailRow>
                        <DetailRow label={t('providerPortal.status')}>
                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${STATUS_CLS[order.status] ?? 'bg-gray-100 text-gray-600'}`}>
                                {order.status}
                            </span>
                        </DetailRow>
                    </div>
                </div>

                {/* Actions card */}
                <div className="rounded-card border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p className="mb-3 text-xs font-semibold text-gray-500 dark:text-slate-400">{t('common.actions')}</p>
                    <div className="flex flex-wrap gap-2">
                        {ACTION_TRANSITIONS.map(({ status, labelKey, cls }) => (
                            <button
                                key={status}
                                onClick={() => setStatus(status)}
                                className={`rounded-lg px-3 py-2 text-sm font-medium ${cls}`}
                            >
                                {t(`providerPortal.${labelKey}` as Parameters<typeof t>[0])}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </CafeteriaProviderPortalLayout>
    );
}
