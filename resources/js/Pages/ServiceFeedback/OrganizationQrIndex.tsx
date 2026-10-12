import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Card, TBody, THead, Table, TableCard, Td, Th, Tr, actionLinkClassName } from '@euisis/ui';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Row = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    status: string;
    qr: { status: 'active' | 'disabled'; scan_count: number; last_scanned_at: string | null; enabled_at: string | null } | null;
    submissions: number;
};

type Props = { organizations: Row[] };

/** Every institution in scope with the state of its public feedback QR (brief §69, 79). */
export default function OrganizationQrIndex({ organizations }: Props) {
    const { t, locale } = useLocale();
    const name = (row: Row) => (locale === 'am' && row.name_am ? row.name_am : row.name_en);
    const qrLabel = (row: Row) => row.qr === null ? t('serviceFeedback.qrNotIssued') : t(row.qr.status === 'active' ? 'serviceFeedback.qrActive' : 'serviceFeedback.qrStatusDisabled');
    const qrStatus = (row: Row) => row.qr === null ? 'inactive' : row.qr.status === 'active' ? 'active' : 'inactive';

    return (
        <AuthenticatedLayout header={<PageHeader title={t('serviceFeedback.orgQrIndexTitle')} description={t('serviceFeedback.orgQrIndexIntro')} />}>
            <Head title={t('serviceFeedback.orgQrIndexTitle')} />

            {organizations.length === 0 ? (
                <Card><EmptyState title={t('serviceFeedback.noOrganizationsInScope')} /></Card>
            ) : (
                <TableCard>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('serviceFeedback.filterOrganization')}</Th>
                                <Th>{t('serviceFeedback.organizationFeedbackQr')}</Th>
                                <Th align="right">{t('serviceFeedback.scans')}</Th>
                                <Th>{t('serviceFeedback.lastScanned')}</Th>
                                <Th align="right">{t('serviceFeedback.submissionsAboutInstitution')}</Th>
                                <Th align="right">{t('common.actions')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {organizations.map((row) => (
                                <Tr key={row.id}>
                                    <Td>
                                        <div className="font-medium text-[color:var(--app-foreground)]">{name(row)}</div>
                                        <div className="font-mono text-xs text-[color:var(--app-muted-foreground)]">{row.code}{row.status !== 'active' && <> · {t('serviceFeedback.organizationInactive')}</>}</div>
                                    </Td>
                                    <Td><StatusBadge status={qrStatus(row)} label={qrLabel(row)} /></Td>
                                    <Td align="right" numeric>{row.qr ? row.qr.scan_count.toLocaleString() : '—'}</Td>
                                    <Td>{row.qr?.last_scanned_at ? <LocalizedDateDisplay value={row.qr.last_scanned_at} withTime /> : '—'}</Td>
                                    <Td align="right" numeric>{row.submissions.toLocaleString()}</Td>
                                    <Td align="right">
                                        <Link href={route('organizations.feedback-qr.show', row.id)} className={actionLinkClassName({ tone: 'primary' })}>{t(row.qr === null ? 'serviceFeedback.enableQr' : 'serviceFeedback.manageQr')}</Link>
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </TableCard>
            )}
        </AuthenticatedLayout>
    );
}
