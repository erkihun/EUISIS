import { Head, Link, router } from '@inertiajs/react';
import type { JSX } from 'react';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { ListPagination, type LaravelPageMeta } from '@/Components/ui';
import { Card, StatusBadge, TBody, THead, Table, Td, Th, Tr, buttonClassName } from '@euisis/ui';

export type InstitutionCase = {
    id: string;
    court_case_id: number;
    case_number: string;
    applicants: string[];
    case_type: string | null;
    filed_at: string | null;
    status: string | null;
    review_status: string | null;
    received_at: string | null;
    organization: { name_en: string; name_am: string | null } | null;
    officer: { id: number; name: string } | null;
};

type Props = {
    cases: LaravelPageMeta & { data: InstitutionCase[] };
    filters: { show: '' | 'mine' | 'unassigned' };
    canManage: boolean;
};

/**
 * Tribunal cases > Cases against the institution (court-ms Flow 7, ADR 0013 #14):
 * every case filed at the administrative tribunal against an institution in the
 * reader's scope, with the officer who follows it. A new case reaches the
 * officer the institution named, by itself.
 */
export default function InstitutionCases({ cases, filters, canManage }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const label = (key: string) => t(`courtCases.institution.${key}`);
    const organizationName = (organization: InstitutionCase['organization']) =>
        organization ? (locale === 'am' ? organization.name_am || organization.name_en : organization.name_en) : '—';
    const show = (value: Props['filters']['show']) => router.get(route('tribunal-cases.institution-cases.index'), value === '' ? {} : { show: value }, { preserveState: true });

    const actions = (
        <div className="flex flex-wrap gap-2">
            {(['', 'mine', 'unassigned'] as const).map((value) => (
                <button key={value || 'all'} type="button" onClick={() => show(value)}
                    className={buttonClassName({ variant: filters.show === value ? 'primary' : 'outline', size: 'sm' })}>
                    {label(`filters.${value || 'all'}`)}
                </button>
            ))}
            {canManage && <Link href={route('tribunal-cases.institution-cases.case-officers')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{label('officers.title')}</Link>}
            <Link href={route('tribunal-cases.letters.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('courtCases.letters.title')}</Link>
        </div>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')} actions={actions} />}>
            <Head title={label('title')} />

            <Card className="p-0">
                {cases.data.length === 0 ? (
                    <EmptyState title={label('none')} />
                ) : (
                    <Table>
                        <THead>
                            <Tr>
                                {(['received', 'caseNumber', 'applicants', 'institution', 'officer', 'status', 'actions'] as const).map((column) => (
                                    <Th key={column} scope="col">{label(`table.${column}`)}</Th>
                                ))}
                            </Tr>
                        </THead>
                        <TBody>
                            {cases.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td><LocalizedDateDisplay value={row.received_at} withTime /></Td>
                                    <Td className="font-mono text-sm">
                                        {row.case_number}
                                        {row.case_type && <span className="block font-sans text-xs text-(--app-muted-foreground)">{row.case_type}</span>}
                                    </Td>
                                    <Td>{row.applicants.join(', ') || '—'}</Td>
                                    <Td>{organizationName(row.organization)}</Td>
                                    <Td>{row.officer ? row.officer.name : <StatusBadge tone="warning">{label('unassigned')}</StatusBadge>}</Td>
                                    <Td>{row.status ? t(`courtCases.portal.status.${row.status}`) : '—'}</Td>
                                    <Td>
                                        <Link href={route('tribunal-cases.institution-cases.show', row.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{label('open')}</Link>
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                )}
                <ListPagination meta={cases} />
            </Card>
        </AuthenticatedLayout>
    );
}
