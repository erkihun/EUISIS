import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

import { cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
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

            <div className="overflow-x-auto rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                <table className={cx(tableClassName, 'min-w-full text-left')}>
                    <thead className={tableHeadClassName}>
                        <tr>
                            {[
                                t('positionEstablishments.establishmentNumber'),
                                t('positionEstablishments.organization'),
                                t('positionEstablishments.position'),
                                t('positionEstablishments.approvedSlots'),
                                t('positionEstablishments.status'),
                                t('positionEstablishments.effectiveFrom'),
                            ].map((h) => (
                                <th key={h} className={cx(tableHeaderCellClassName, 'text-left')}>
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className={tableBodyClassName}>
                        {establishments.data.map((e) => (
                            <tr key={e.id} className={tableRowClassName}>
                                <td className={tableCellClassName}>
                                    <Link
                                        href={route('position-establishments.show', e.id)}
                                        className="font-mono text-[color:var(--color-primary)] hover:underline dark:text-[color:var(--color-primary)]"
                                    >
                                        {e.establishment_number}
                                    </Link>
                                </td>
                                <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{e.organization?.name_en ?? '—'}</td>
                                <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{e.position?.title_en ?? '—'}</td>
                                <td className={cx(tableCellClassName, 'text-[color:var(--app-foreground)]')}>{e.approved_slots}</td>
                                <td className={tableCellClassName}><StatusBadge status={e.status} /></td>
                                <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={e.effective_from} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {establishments.data.length === 0 && (
                    <p className="px-4 py-8 text-center text-sm text-[color:var(--app-muted-foreground)]">
                        {t('common.noResults')}
                    </p>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
