import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Establishment = {
    id: string;
    establishment_number: string;
    status: string;
    approved_slots: number;
    effective_from: string;
    effective_to: string | null;
    organization: { name_en: string } | null;
    position: { title_en: string } | null;
};

type Props = {
    establishments: { data: Establishment[]; links: unknown[] };
    filters: { organization_id?: string; status?: string };
};

export default function PositionEstablishmentsIndex({ establishments, filters }: Props) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('positionEstablishments.title')} />}
        >
            <Head title={t('positionEstablishments.title')} />

            <div className="rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50 dark:bg-slate-950">
                        <tr>
                            {[
                                t('positionEstablishments.establishmentNumber'),
                                t('positionEstablishments.organization'),
                                t('positionEstablishments.position'),
                                t('positionEstablishments.approvedSlots'),
                                t('positionEstablishments.status'),
                                t('positionEstablishments.effectiveFrom'),
                            ].map((h) => (
                                <th key={h} className="px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                        {establishments.data.map((e) => (
                            <tr key={e.id} className="hover:bg-gray-50 dark:hover:bg-slate-800">
                                <td className="px-4 py-3">
                                    <Link
                                        href={route('position-establishments.show', e.id)}
                                        className="font-mono text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        {e.establishment_number}
                                    </Link>
                                </td>
                                <td className="px-4 py-3 text-gray-700 dark:text-slate-200">{e.organization?.name_en ?? '—'}</td>
                                <td className="px-4 py-3 text-gray-700 dark:text-slate-200">{e.position?.title_en ?? '—'}</td>
                                <td className="px-4 py-3 text-gray-700 dark:text-slate-200">{e.approved_slots}</td>
                                <td className="px-4 py-3"><StatusBadge status={e.status} /></td>
                                <td className="px-4 py-3 text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={e.effective_from} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {establishments.data.length === 0 && (
                    <p className="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">
                        {t('common.noResults')}
                    </p>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
