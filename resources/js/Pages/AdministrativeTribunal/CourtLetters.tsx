import { Head, Link, router } from '@inertiajs/react';
import type { JSX } from 'react';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import { ListPagination, type LaravelPageMeta } from '@/Components/ui';
import { Card, StatusBadge, TBody, THead, Table, Td, Th, Tr, buttonClassName } from '@euisis/ui';

type CourtLetter = {
    id: string;
    case_number: string | null;
    case_title: string | null;
    subject: string | null;
    reference_number: string | null;
    approved_by: string | null;
    approved_at: string | null;
    received_at: string | null;
    opened: boolean;
    organization: { name_en: string; name_am: string | null } | null;
    /** The register entry when the case was referred from here. */
    tribunal_case_id: string | null;
};

type Props = {
    letters: LaravelPageMeta & { data: CourtLetter[] };
    filters: { unread: boolean };
};

/**
 * Tribunal cases > Court letters (court-ms Flow 5): letters the administrative
 * tribunal sent the institutions in the reader's scope, each with its PDF.
 */
export default function CourtLetters({ letters, filters }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const label = (key: string) => t(`courtCases.letters.${key}`);
    const organizationName = (organization: CourtLetter['organization']) =>
        organization ? (locale === 'am' ? organization.name_am || organization.name_en : organization.name_en) : '—';

    const actions = (
        <div className="flex flex-wrap gap-2">
            <button type="button" className={buttonClassName({ variant: filters.unread ? 'primary' : 'outline', size: 'sm' })}
                onClick={() => router.get(route('tribunal-cases.letters.index'), filters.unread ? {} : { unread: 1 }, { preserveState: true })}>
                {label('unreadOnly')}
            </button>
            <Link href={route('tribunal-cases.index')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('grievances.tribunalCases')}</Link>
        </div>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={label('title')} description={label('description')} actions={actions} />}>
            <Head title={label('title')} />

            <Card className="p-0">
                {letters.data.length === 0 ? (
                    <EmptyState title={label('none')} />
                ) : (
                    <Table>
                        <THead>
                            <Tr>
                                {(['received', 'caseNumber', 'subject', 'institution', 'approvedBy', 'actions'] as const).map((column) => (
                                    <Th key={column} scope="col">{label(`table.${column}`)}</Th>
                                ))}
                            </Tr>
                        </THead>
                        <TBody>
                            {letters.data.map((letter) => (
                                <Tr key={letter.id}>
                                    <Td><LocalizedDateDisplay value={letter.received_at} withTime /></Td>
                                    <Td className="font-mono text-sm">
                                        {letter.tribunal_case_id
                                            ? <Link href={route('tribunal-cases.show', letter.tribunal_case_id)} className="underline-offset-2 hover:underline">{letter.case_number}</Link>
                                            : (letter.case_number ?? '—')}
                                        {letter.case_title && <span className="block font-sans text-xs text-(--app-muted-foreground)">{letter.case_title}</span>}
                                    </Td>
                                    <Td>
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className={letter.opened ? '' : 'font-semibold'}>{letter.subject ?? '—'}</span>
                                            {!letter.opened && <StatusBadge tone="info">{label('new')}</StatusBadge>}
                                        </span>
                                        {letter.reference_number && <span className="block text-xs text-(--app-muted-foreground)">{letter.reference_number}</span>}
                                    </Td>
                                    <Td>{organizationName(letter.organization)}</Td>
                                    <Td muted={!letter.approved_by}>
                                        {letter.approved_by ?? '—'}
                                        {letter.approved_at && <LocalizedDateDisplay value={letter.approved_at} className="block text-xs text-(--app-muted-foreground)" />}
                                    </Td>
                                    <Td>
                                        <a href={route('tribunal-cases.letters.download', letter.id)} className={buttonClassName({ variant: 'outline', size: 'sm', className: 'whitespace-nowrap' })}>{label('download')}</a>
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            <ListPagination meta={letters} />
        </AuthenticatedLayout>
    );
}
