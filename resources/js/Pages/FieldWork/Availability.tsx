import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Pager from '@/Components/dailyActivity/Pager';
import ManagementNav from '@/Components/fieldWork/ManagementNav';
import { employeeName, named, panelCls } from '@/Components/fieldWork/helpers';
import type { EmployeeRef, ManagementAbilities, Named, Paginated } from '@/Components/fieldWork/types';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link } from '@inertiajs/react';
import type { JSX } from 'react';

import { cx, tableBodyClassName, tableClassName, tableHeadClassName } from '@/Components/ui';
import { useBranding } from '@/hooks/useBranding';
type Row = {
    id: string;
    status: 'official_field_work' | 'approved_not_checked_in' | 'unknown';
    employee: EmployeeRef | null;
    organization_unit: Named;
    request: { id: string; reference_number: string };
    destination: { name_en: string | null; name_am: string | null };
    expected_return_at: string | null;
};

/**
 * Who is on field work right now. Workflow status only: no coordinates, and
 * people not listed are UNKNOWN, never "absent".
 */
export default function FieldWorkAvailability({ rows, at, can }: { rows: Paginated<Row>; at: string; can: ManagementAbilities }): JSX.Element {
    const { t, locale } = useLocale();
    const { withBrand } = useBranding();
    const th = 'px-3 py-2 text-left text-xs font-medium text-[color:var(--app-muted-foreground)]';
    const td = 'px-3 py-2 align-top text-sm text-[color:var(--app-foreground)]';
    const destination = (row: Row) => (locale === 'am' && row.destination.name_am ? row.destination.name_am : row.destination.name_en) ?? '—';
    const badge = (row: Row) => <StatusBadge status={row.status === 'official_field_work' ? 'in_field' : row.status === 'approved_not_checked_in' ? 'check_in_missing' : 'unknown'} label={t(`fieldWork.availability.status.${row.status}`)} />;

    return (
        <AuthenticatedLayout header={<PageHeader title={t('fieldWork.availability.title')} description={t('fieldWork.availability.description')} />}>
            <Head title={t('fieldWork.availability.title')} />
            <div className="space-y-3">
                <ManagementNav can={can} current="field-work.availability.index" />
                <p className="text-xs text-[color:var(--app-muted-foreground)]"><LocalizedDateDisplay value={at} withTime /> · {withBrand(t('fieldWork.availability.unknownNote'))}</p>
                {rows.data.length === 0 ? (
                    <p className={`${panelCls} p-4 text-sm text-[color:var(--app-muted-foreground)]`}>{t('fieldWork.availability.empty')}</p>
                ) : (
                    <div className={panelCls}>
                        <table className={cx(tableClassName, 'hidden')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    <th className={th}>{t('fieldWork.columns.employee')}</th>
                                    <th className={th}>{t('fieldWork.columns.status')}</th>
                                    <th className={th}>{t('fieldWork.columns.destination')}</th>
                                    <th className={th}>{t('fieldWork.availability.expectedReturn')}</th>
                                    <th className={th}>{t('fieldWork.columns.reference')}</th>
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {rows.data.map((row) => (
                                    <tr key={row.id}>
                                        <td className={td}>
                                            <span className="font-medium">{employeeName(row.employee, locale)}</span>
                                            <span className="block text-xs text-[color:var(--app-muted-foreground)]">{[row.employee?.employee_number, named(row.organization_unit ?? undefined, locale)].filter(Boolean).join(' · ')}</span>
                                        </td>
                                        <td className={td}>{badge(row)}</td>
                                        <td className={td}>{destination(row)}</td>
                                        <td className={`${td} whitespace-nowrap`}><LocalizedDateDisplay value={row.expected_return_at} withTime /></td>
                                        <td className={td}><Link href={route('field-work.requests.show', row.request.id)} className="font-medium text-[color:var(--color-primary)] hover:underline">{row.request.reference_number}</Link></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <ul className="divide-y divide-[color:var(--app-border)] md:hidden">
                            {rows.data.map((row) => (
                                <li key={row.id}>
                                    <Link href={route('field-work.requests.show', row.request.id)} className="block px-3 py-2.5">
                                        <div className="flex items-start justify-between gap-2">
                                            <p className="truncate text-sm font-medium text-[color:var(--app-foreground)]">{employeeName(row.employee, locale)}</p>
                                            {badge(row)}
                                        </div>
                                        <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{destination(row)} · <LocalizedDateDisplay value={row.expected_return_at} withTime /></p>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                <Pager meta={rows.meta} links={rows.links} />
            </div>
        </AuthenticatedLayout>
    );
}
