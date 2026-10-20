import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import StatusBadge from '@/Components/StatusBadge';
import { CheckCircle } from '@/Components/Icons';
import { useConfirm } from '@/hooks/useConfirm';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { useLocale } from '@/hooks/useLocale';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { localizedName } from '@/utils/localizedName';
import { Table, TBody, Td, Th, THead, Tr, buttonClassName } from '@/Components/ui';
import { Alert, Button, Card, CardHeader, CardTitle } from '@euisis/ui';
import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type Rule = { type: string; operator: string; value: string };
type PositionLine = {
    id: string;
    advertised_slots: number;
    current_available: number;
    position: { code: string; title_en: string; title_am: string | null; grade_level: string | null; organization_unit_name_en: string | null; organization_unit_name_am: string | null } | null;
};
type Announcement = {
    id: string;
    organization: { name_en: string; name_am: string | null } | null;
    position: { title_en: string; title_am: string | null } | null;
    opening_date: string;
    closing_date: string;
    status: string;
    created_at: string;
    published_at: string | null;
    created_by: { name: string } | null;
    published_by: { name: string } | null;
    eligibility_rules: Rule[] | null;
    required_documents: string[] | null;
};
type Readiness = { ready: boolean; issues: { code: string; message: string }[]; positions: PositionLine[] };
type Summary = { total: number; pending_screening: number; eligible: number; ineligible: number; selected: number; transfers_created: number } | null;
type TimelineEvent = { event_type: string; actor_user_id: number | null; reason: string | null; created_at: string | null };
type WindowState = 'not_published' | 'scheduled' | 'open' | 'closed';
type Props = {
    announcement: Announcement;
    readiness: Readiness;
    applicationSummary: Summary;
    applicationWindowState: WindowState;
    timeline: TimelineEvent[];
    /** Names for organization ids an eligibility rule refers to. */
    ruleOrganizations?: Record<string, { name_en: string; name_am: string | null }>;
    can: { update: boolean; publish: boolean; close: boolean; cancel: boolean; delete: boolean; viewApplications: boolean };
};

const WINDOW_LABEL: Record<WindowState, string> = {
    not_published: 'transfers.windowNotPublished',
    scheduled: 'transfers.windowScheduled',
    open: 'transfers.windowOpen',
    closed: 'transfers.windowClosed',
};

export default function TransferAnnouncementShow({ announcement, readiness, applicationSummary, applicationWindowState, timeline, ruleOrganizations = {}, can }: Props) {
    const { locale, t } = useLocale();
    const { confirm } = useConfirm();
    const { number, organizationName } = useDisplayFormat();
    const calendar = useCalendarSystem();
    const [busy, setBusy] = useState(false);

    const org = announcement.organization ? organizationName(announcement.organization.name_en, announcement.organization.name_am) : '—';
    const title = announcement.position ? localizedName(announcement.position.title_en, announcement.position.title_am, locale) : t('transfers.announcement');
    const totalSlots = readiness.positions.reduce((sum, line) => sum + line.advertised_slots, 0);
    const period = `${formatDateDisplay(announcement.opening_date, calendar, locale)} – ${formatDateDisplay(announcement.closing_date, calendar, locale)}`;
    const isDraft = announcement.status === 'draft';

    function post(name: string) {
        router.post(route(name, announcement.id), {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    }

    async function publish() {
        const { confirmed } = await confirm({
            title: t('transfers.publishAnnouncement'),
            description: t('transfers.publishSummary').replace(':count', number(totalSlots)).replace(':period', period),
            confirmLabel: t('transfers.publishAnnouncement'),
            cancelLabel: t('common.cancel'),
        });
        if (confirmed) post('transfer-announcements.publish');
    }

    async function close() {
        const { confirmed } = await confirm({ title: t('transfers.closeAnnouncement'), description: t('transfers.closeConfirmation'), confirmLabel: t('transfers.closeAnnouncement'), cancelLabel: t('common.cancel'), variant: 'warning' });
        if (confirmed) post('transfer-announcements.close');
    }

    async function cancel() {
        const { confirmed } = await confirm({ title: t('transfers.cancelAnnouncement'), description: t('transfers.cancelConfirmation'), confirmLabel: t('transfers.cancelAnnouncementConfirm'), cancelLabel: t('transfers.keepAnnouncement'), variant: 'danger' });
        if (confirmed) post('transfer-announcements.cancel');
    }

    const ruleLabel = (rule: Rule) => {
        const type = t(`transfers.rule${rule.type === 'minimum_service_months' ? 'MinimumService' : pascal(rule.type)}`);
        const operator = t(`transfers.rule${rule.operator === 'greater_than_or_equal' ? 'AtLeast' : rule.operator === 'equals' ? 'Equals' : 'In'}`);

        const value = rule.type === 'current_organization'
            ? rule.value.split(',').map((id) => id.trim()).map((id) => ruleOrganizations[id] ? organizationName(ruleOrganizations[id].name_en, ruleOrganizations[id].name_am) : id).join(', ')
            : rule.type === 'employment_status' ? t(`status.${rule.value}`) : rule.value;

        return `${type} ${operator} ${value}`;
    };

    const actions = (
        <div className="flex flex-wrap gap-2">
            {can.cancel && ['draft', 'published'].includes(announcement.status) && (
                <Button variant="destructiveOutline" disabled={busy} onClick={cancel}>{t('transfers.cancelAnnouncement')}</Button>
            )}
            {can.close && announcement.status === 'published' && (
                <Button variant="outline" disabled={busy} onClick={close}>{t('transfers.closeAnnouncement')}</Button>
            )}
            {isDraft && (
                <Link href={route('transfer-announcements.preview', announcement.id)} className={buttonClassName({ variant: 'outline' })}>{t('common.preview')}</Link>
            )}
            {can.update && (
                <Link href={route('transfer-announcements.edit', announcement.id)} className={buttonClassName({ variant: 'outline' })}>{t('common.edit')}</Link>
            )}
            {can.viewApplications && !isDraft && (
                <Link href={`${route('transfer-applications.index')}?announcement_id=${announcement.id}`} className={buttonClassName({ variant: 'primary' })}>
                    {t('transfers.viewApplications')}
                </Link>
            )}
            {can.publish && isDraft && (
                <Button variant="primary" loading={busy} disabled={!readiness.ready} title={readiness.ready ? undefined : t('transfers.resolveReadinessIssues')} onClick={publish}>
                    {t('transfers.publishAnnouncement')}
                </Button>
            )}
        </div>
    );

    return (
        <AuthenticatedLayout header={<PageHeader backHref={route('transfer-announcements.index')} title={title} description={org} actions={actions} />}>
            <Head title={title} />

            <div className="space-y-6">
                {isDraft && !readiness.ready && (
                    <Alert tone="warning" title={t('transfers.notReadyToPublish')}>
                        <ul className="list-disc space-y-1 ps-5">
                            {readiness.issues.map((issue) => <li key={`${issue.code}-${issue.message}`}>{issue.message}</li>)}
                        </ul>
                    </Alert>
                )}

                <section className="@container" aria-label={t('transfers.overview')}>
                    <div className="grid gap-4 @lg:grid-cols-2 @4xl:grid-cols-4">
                        <StatCard label={t('transfers.totalVacancies')} value={number(totalSlots)} hint={t('transfers.inPositions').replace(':count', number(readiness.positions.length))} />
                        <StatCard label={t('transfers.applicationPeriod')} value={t(WINDOW_LABEL[applicationWindowState])} hint={period} />
                        <StatCard label={t('transfers.applications')} value={applicationSummary ? number(applicationSummary.total) : '—'} hint={applicationSummary ? t('transfers.awaitingScreening').replace(':count', number(applicationSummary.pending_screening)) : t('transfers.afterPublishing')} />
                        <StatCard label={t('transfers.selectedApplicants')} value={applicationSummary ? number(applicationSummary.selected) : '—'} hint={applicationSummary ? t('transfers.transfersCreatedCount').replace(':count', number(applicationSummary.transfers_created)) : undefined} />
                    </div>
                </section>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(19rem,1fr)]">
                    <div className="min-w-0 space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('transfers.includedPositions')}</CardTitle>
                            </CardHeader>
                            {readiness.positions.length === 0 ? (
                                <p className="text-sm text-(--app-muted-foreground)">{t('transfers.noPositionsConfigured')}</p>
                            ) : (
                                <Table>
                                    <THead>
                                        <Tr>
                                            <Th>{t('transfers.position')}</Th>
                                            <Th>{t('transfers.organizationUnit')}</Th>
                                            <Th>{t('transfers.gradeLevel')}</Th>
                                            <Th align="right">{t('transfers.advertisedSlots')}</Th>
                                            <Th align="right">{t('transfers.currentAvailableVacancy')}</Th>
                                        </Tr>
                                    </THead>
                                    <TBody>
                                        {readiness.positions.map((line) => (
                                            <Tr key={line.id}>
                                                <Td className="font-medium text-(--app-foreground)">
                                                    {line.position ? localizedName(line.position.title_en, line.position.title_am, locale) : '—'}
                                                    {line.position?.code && <span className="block text-xs font-normal text-(--app-muted-foreground)">{line.position.code}</span>}
                                                </Td>
                                                <Td>{line.position ? localizedName(line.position.organization_unit_name_en, line.position.organization_unit_name_am, locale) || '—' : '—'}</Td>
                                                <Td>{line.position?.grade_level ?? '—'}</Td>
                                                <Td numeric>{line.advertised_slots}</Td>
                                                <Td numeric>{line.current_available}</Td>
                                            </Tr>
                                        ))}
                                    </TBody>
                                </Table>
                            )}
                        </Card>

                        <div className="grid gap-6 md:grid-cols-2">
                            <Card>
                                <CardHeader><CardTitle>{t('transfers.eligibilityRules')}</CardTitle></CardHeader>
                                {announcement.eligibility_rules?.length ? (
                                    <ul className="list-disc space-y-1.5 ps-5 text-sm text-(--app-foreground)">
                                        {announcement.eligibility_rules.map((rule, index) => <li key={index}>{ruleLabel(rule)}</li>)}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-(--app-muted-foreground)">{t('transfers.noEligibilityRulesConfigured')}</p>
                                )}
                            </Card>
                            <Card>
                                <CardHeader><CardTitle>{t('transfers.requiredDocuments')}</CardTitle></CardHeader>
                                {announcement.required_documents?.length ? (
                                    <ul className="list-disc space-y-1.5 ps-5 text-sm text-(--app-foreground)">
                                        {announcement.required_documents.map((document) => <li key={document}>{document}</li>)}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-(--app-muted-foreground)">{t('transfers.noRequiredDocumentsConfigured')}</p>
                                )}
                                <p className="mt-3 text-xs text-(--app-muted-foreground)">{t('transfers.settingsDocumentsAlsoApply')}</p>
                            </Card>
                        </div>
                    </div>

                    <aside className="min-w-0 space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('transfers.announcementInformation')}</CardTitle>
                                <StatusBadge status={announcement.status} />
                            </CardHeader>
                            <dl className="space-y-3 text-sm">
                                <Row label={t('transfers.applicationPeriod')}>{period}</Row>
                                <Row label={t('transfers.createdBy')}>{announcement.created_by?.name ?? '—'}</Row>
                                <Row label={t('transfers.createdAt')}><LocalizedDateDisplay value={announcement.created_at} /></Row>
                                <Row label={t('transfers.publishedBy')}>{announcement.published_by?.name ?? '—'}</Row>
                                <Row label={t('transfers.publishedAt')}><LocalizedDateDisplay value={announcement.published_at} fallback="—" /></Row>
                            </dl>
                        </Card>

                        {isDraft && readiness.ready && (
                            <Card>
                                <CardHeader><CardTitle>{t('transfers.publicationReadiness')}</CardTitle></CardHeader>
                                <p className="flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                                    <CheckCircle className="size-4" aria-hidden="true" />{t('transfers.readyToPublish')}
                                </p>
                            </Card>
                        )}

                        {applicationSummary && (
                            <Card>
                                <CardHeader><CardTitle>{t('transfers.applications')}</CardTitle></CardHeader>
                                <dl className="space-y-3 text-sm">
                                    <Row label={t('transfers.pendingApplications')}>{number(applicationSummary.pending_screening)}</Row>
                                    <Row label={t('transfers.eligibleApplications')}>{number(applicationSummary.eligible)}</Row>
                                    <Row label={t('transfers.ineligibleApplications')}>{number(applicationSummary.ineligible)}</Row>
                                    <Row label={t('transfers.selectedApplicants')}>{number(applicationSummary.selected)}</Row>
                                    <Row label={t('transfers.transfersCreated')}>{number(applicationSummary.transfers_created)}</Row>
                                </dl>
                            </Card>
                        )}

                        {timeline.length > 0 && (
                            <Card>
                                <CardHeader><CardTitle>{t('transfers.workflowHistory')}</CardTitle></CardHeader>
                                <ol className="space-y-3">
                                    {timeline.map((event, index) => (
                                        <li key={`${event.event_type}-${event.created_at}-${index}`} className="border-s-2 border-(--app-border) ps-3 text-sm">
                                            <p className="font-medium text-(--app-foreground)">{t(`transfers.timeline${pascal(event.event_type)}`)}</p>
                                            <p className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={event.created_at} /></p>
                                            {event.reason && <p className="mt-1 wrap-break-word text-(--app-foreground)">{event.reason}</p>}
                                        </li>
                                    ))}
                                </ol>
                            </Card>
                        )}
                    </aside>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-4">
            <dt className="text-(--app-muted-foreground)">{label}</dt>
            <dd className="text-end font-medium wrap-break-word text-(--app-foreground)">{children}</dd>
        </div>
    );
}

const pascal = (value: string) => value.split('_').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join('');

