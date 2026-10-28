import { Head, Link, router } from '@inertiajs/react';
import { useState, type JSX } from 'react';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { Button, Card, Select, StatusBadge, TBody, THead, Table, Td, Th, Tr, buttonClassName } from '@euisis/ui';

type Row = {
    id: string;
    name_en: string;
    name_am: string | null;
    officer: { id: number; name: string } | null;
    candidates: Array<{ id: number; name: string }>;
    can_manage: boolean;
};

/**
 * Tribunal cases > Court case officers (court-ms Flow 7, ADR 0013 #14): the person
 * each institution names to follow its cases at the administrative tribunal.
 * Every new case against the institution reaches them by itself.
 */
export default function CourtCaseOfficers({ organizations }: { organizations: Row[] }): JSX.Element {
    const { locale, t } = useLocale();
    const label = (key: string) => t(`courtCases.institution.officers.${key}`);
    const [choice, setChoice] = useState<Record<string, string>>(() => Object.fromEntries(organizations.map((row) => [row.id, row.officer ? String(row.officer.id) : ''])));
    const save = (row: Row) => router.post(route('tribunal-cases.institution-cases.case-officers.store'), { organization_id: row.id, user_id: choice[row.id] || null }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={label('title')} description={label('description')} backHref={route('tribunal-cases.institution-cases.index')}
                actions={<Link href={route('tribunal-cases.institution-cases.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('courtCases.institution.title')}</Link>} />
        )}>
            <Head title={label('title')} />

            <Card className="p-0">
                {organizations.length === 0 ? <EmptyState title={label('none')} /> : (
                    <Table>
                        <THead>
                            <Tr>
                                {(['institution', 'officer', 'actions'] as const).map((column) => <Th key={column} scope="col">{label(`table.${column}`)}</Th>)}
                            </Tr>
                        </THead>
                        <TBody>
                            {organizations.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="font-medium">{locale === 'am' ? row.name_am || row.name_en : row.name_en}</Td>
                                    <Td>
                                        {row.can_manage ? (
                                            <Select aria-label={label('table.officer')} value={choice[row.id] ?? ''} onChange={(e) => setChoice((current) => ({ ...current, [row.id]: e.target.value }))}>
                                                <option value="">{label('nobody')}</option>
                                                {row.candidates.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                                            </Select>
                                        ) : (row.officer ? row.officer.name : <StatusBadge tone="warning">{label('nobody')}</StatusBadge>)}
                                    </Td>
                                    <Td>
                                        {row.can_manage && (
                                            <Button type="button" size="sm" variant="outline" disabled={(choice[row.id] ?? '') === (row.officer ? String(row.officer.id) : '')} onClick={() => save(row)}>
                                                {label('save')}
                                            </Button>
                                        )}
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
