import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import PaginatorLinks, { type PaginatorLink } from '@/Components/PaginatorLinks';
import { useLocale } from '@/hooks/useLocale';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import type { JSX } from 'react';

type Row = {
    id: string;
    reference_number: string;
    employee: string | null;
    employee_number: string | null;
    type_en: string | null;
    type_am: string | null;
    purpose: string;
    destination_en: string | null;
    destination_am: string | null;
    starts_at: string;
    expected_return_at: string;
};

type Props = {
    requests: {
        data: Row[];
        links: PaginatorLink[];
    };
    can: {
        approve: boolean;
        return: boolean;
        reject: boolean;
    };
};

export default function FieldWorkPending({ requests, can }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const amharic = locale === 'am';

    function action(id: string, kind: 'approve' | 'return' | 'reject'): void {
        const reason = kind === 'approve'
            ? undefined
            : window.prompt(t(kind === 'return' ? 'fieldWork.returnReason' : 'fieldWork.rejectionReason'));

        if (kind === 'approve' || reason?.trim()) {
            router.post(route(`field-work.${kind}`, id), kind === 'approve' ? {} : { reason: reason?.trim() });
        }
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('fieldWork.pendingTitle')}
                    description={t('fieldWork.pendingDescription')}
                />
            }
        >
            <Head title={t('fieldWork.pendingTitle')} />
            <div className="space-y-4">
                <div className="overflow-x-auto rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {requests.data.length === 0 ? (
                        <p className="p-6 text-center text-sm text-gray-500 dark:text-slate-400">
                            {t('fieldWork.noPending')}
                        </p>
                    ) : (
                        <table className="min-w-full text-sm">
                            <thead className="bg-gray-50 dark:bg-slate-950">
                                <tr className="border-b text-left dark:border-slate-800">
                                    <th className="p-3">{t('fieldWork.employee')}</th>
                                    <th className="p-3">{t('fieldWork.request')}</th>
                                    <th className="p-3">{t('fieldWork.schedule')}</th>
                                    <th className="p-3">{t('fieldWork.actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.data.map((row) => (
                                    <tr key={row.id} className="border-b last:border-0 dark:border-slate-800">
                                        <td className="p-3">
                                            {row.employee}
                                            <br />
                                            <span className="text-xs text-gray-500">{row.employee_number}</span>
                                        </td>
                                        <td className="p-3">
                                            <span className="font-medium">{row.reference_number}</span>
                                            {' · '}
                                            {amharic ? (row.type_am ?? row.type_en) : row.type_en}
                                            <br />
                                            {row.purpose}
                                            <br />
                                            <span className="text-xs text-gray-500">
                                                {amharic ? (row.destination_am ?? row.destination_en) : row.destination_en}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap p-3">
                                            <LocalizedDateDisplay value={row.starts_at} withTime />
                                            <br />
                                            <LocalizedDateDisplay value={row.expected_return_at} withTime />
                                        </td>
                                        <td className="whitespace-nowrap p-3">
                                            {can.approve && (
                                                <button type="button" onClick={() => action(row.id, 'approve')} className="me-3 font-medium text-emerald-700 hover:underline dark:text-emerald-400">
                                                    {t('fieldWork.approve')}
                                                </button>
                                            )}
                                            {can.return && (
                                                <button type="button" onClick={() => action(row.id, 'return')} className="me-3 font-medium text-amber-700 hover:underline dark:text-amber-400">
                                                    {t('fieldWork.return')}
                                                </button>
                                            )}
                                            {can.reject && (
                                                <button type="button" onClick={() => action(row.id, 'reject')} className="font-medium text-red-700 hover:underline dark:text-red-400">
                                                    {t('fieldWork.reject')}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
                <PaginatorLinks links={requests.links} label={t('fieldWork.pagination')} />
            </div>
        </AuthenticatedLayout>
    );
}
