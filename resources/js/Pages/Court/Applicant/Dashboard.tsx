import { Head, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Briefcase, CheckCircle, ClipboardListIcon, ClockIcon, InfoIcon, Plus, ScaleIcon, ScrollText, MessageSquareIcon, ArchiveIcon } from '@/Components/Icons';
import { Card, TBody, THead, Table, Td, Th, Tr, actionLinkClassName, badgeClassName, buttonClassName, cx, type Tone } from '@/Components/ui';
import { useApplicantPagesText } from '@/court/i18n/useApplicantPagesText';
import type { ApplicantDashboardProps } from '@/court/types/applicant';

type Panel = 'recent' | 'letters' | 'responses' | 'response-replies' | 'decisions';

const caseStatusTone = (status: string): Tone => (status === 'pending' ? 'warning' : status === 'active' ? 'success' : 'neutral');
const replyTone: Record<string, Tone> = { accepted: 'success', returned: 'warning', rejected: 'danger' };

/** One of the four totals, with the share of all cases it is (as the Blade tiles' bars showed). */
function Share({ value, total, tone }: { value: number; total: number; tone: 'warning' | 'success' | 'neutral' }) {
    const pct = total > 0 ? Math.round((value / total) * 100) : 0;
    const bar = { warning: 'bg-amber-500', success: 'bg-emerald-500', neutral: 'bg-(--app-muted-foreground)' }[tone];
    return (
        <div className="mt-2 h-1.5 w-full rounded-full bg-(--app-surface-muted)" role="presentation">
            <div className={cx('h-1.5 rounded-full', bar)} style={{ width: `${pct}%` }} />
        </div>
    );
}

function PanelHeader({ icon, title, description, actions }: { icon: ReactNode; title: string; description?: string; actions?: ReactNode }) {
    return (
        <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-(--app-surface-muted) text-(--app-muted-foreground)">{icon}</span>
                <div>
                    <h3 className="text-base font-semibold text-(--app-foreground)">{title}</h3>
                    {description && <p className="text-xs text-(--app-muted-foreground)">{description}</p>}
                </div>
            </div>
            {actions && <div className="flex flex-wrap items-center gap-3">{actions}</div>}
        </div>
    );
}

function Item({ children }: { children: ReactNode }) {
    return <li className="rounded-card border border-(--app-border) p-4">{children}</li>;
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="rounded-card border border-dashed border-(--app-border) px-4 py-8 text-center text-sm text-(--app-muted-foreground)">{children}</p>;
}

/**
 * The applicant portal's home (the Blade applicant/dashboard): the case
 * totals, quick actions that switch the panel between recent cases, letters,
 * respondent responses, responses of response and decisions, and three tips.
 * Links into the case pages are plain links, so they open whichever kind of
 * page those are.
 */
export default function ApplicantDashboard({ applicant, today, counts, recent, letters, responses, responseReplies, decisions }: ApplicantDashboardProps) {
    const { t, tx } = useApplicantPagesText();
    // Only EUISIS employees and institutions are parties: new cases are filed from the EUISIS employee portal.
    const shared = usePage().props as { portal?: { filesInEuisis?: boolean } | null; auth?: { user?: { can_file_for_client?: boolean } | null } };
    const filesInEuisis = Boolean(shared.portal?.filesInEuisis);
    // A verified lawyer files for a client whatever the filing mode (EUISIS ADR 0013 #13).
    const filesForClients = Boolean(shared.auth?.user?.can_file_for_client);
    const [panel, setPanel] = useState<Panel>('recent');

    const name = applicant.name ?? t('cm.applicant.user');
    const displayName = applicant.is_lawyer ? `${t('cm.dashboard.lawyer_title')} ${name}` : name;
    const caseStatus = (status: string) => tx(`cm.cases.status.${status}`, status);
    const link = actionLinkClassName({ tone: 'primary', size: 'xs', className: 'text-sm' });
    const toRecent = <button type="button" onClick={() => setPanel('recent')} className={link}>{t('cm.dashboard.recent_cases')}</button>;

    const actions: { id: Panel; label: string; icon: ReactNode; count?: number }[] = [
        { id: 'recent', label: t('cm.dashboard.recent_cases'), icon: <ClockIcon className="h-5 w-5" /> },
        { id: 'letters', label: t('cm.dashboard.letters'), icon: <ScrollText className="h-5 w-5" />, count: counts.letters },
        { id: 'responses', label: t('cm.dashboard.responses'), icon: <MessageSquareIcon className="h-5 w-5" />, count: counts.responses },
        { id: 'response-replies', label: t('cm.respondent.response_of_response'), icon: <ArchiveIcon className="h-5 w-5" />, count: counts.responseReplies },
        { id: 'decisions', label: t('cm.dashboard.decisions'), icon: <ScaleIcon className="h-5 w-5" />, count: counts.decisions },
    ];

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={`${t('cm.dashboard.welcome_back')}, ${displayName}`}
                    actions={(
                        <div className="rounded-card border border-(--app-border) bg-(--app-surface) px-4 py-2 text-sm">
                            <div className="text-xs uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.dashboard.today')}</div>
                            <div className="font-semibold text-(--app-foreground)"><LocalizedDateDisplay value={today} /></div>
                        </div>
                    )}
                />
            )}
        >
            <Head title={t('cm.dashboard.my_dashboard')} />

            <div className="space-y-6">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatCard label={t('cm.dashboard.total_cases')} value={counts.total} tone="primary" icon={<ClipboardListIcon className="h-4.5 w-4.5" />} />
                    <StatCard label={t('cm.dashboard.pending')} value={counts.pending} tone="warning" icon={<ClockIcon className="h-4.5 w-4.5" />} hint={<Share value={counts.pending} total={counts.total} tone="warning" />} />
                    <StatCard label={t('cm.dashboard.active')} value={counts.active} tone="success" icon={<CheckCircle className="h-4.5 w-4.5" />} hint={<Share value={counts.active} total={counts.total} tone="success" />} />
                    <StatCard label={t('cm.dashboard.closed')} value={counts.closed} tone="neutral" icon={<ArchiveIcon className="h-4.5 w-4.5" />} hint={<Share value={counts.closed} total={counts.total} tone="neutral" />} />
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-[3fr_9fr]">
                    <Card className="h-fit">
                        <h3 className="mb-4 text-base font-semibold text-(--app-foreground)">{t('cm.dashboard.quick_actions')}</h3>
                        <div className="space-y-2">
                            {/* The "file in EUISIS" note is for employees; a lawyer files here, for a client. */}
                            {filesInEuisis ? (
                                !filesForClients && <p className="rounded-lg border border-(--app-border) bg-(--app-surface-muted) p-3 text-sm text-(--app-muted-foreground)">{t('cm.cases.file_in_euisis')}</p>
                            ) : (
                                <a href={route('court.applicant.cases.create')} className={buttonClassName({ variant: filesForClients ? 'outline' : 'primary', className: 'w-full justify-start gap-2' })}>
                                    <Plus className="h-5 w-5 shrink-0" /><span className="min-w-0 text-left">{t('cm.dashboard.submit_new_case')}</span>
                                </a>
                            )}
                            {filesForClients && (
                                <a href={route('court.applicant.lawyer.file')} className={buttonClassName({ variant: 'primary', className: 'h-auto min-h-10 w-full justify-start gap-2 py-2' })}>
                                    <Briefcase className="h-5 w-5 shrink-0" /><span className="min-w-0 text-left">{t('cm.lawyer.file.nav')}</span>
                                </a>
                            )}
                            {actions.map((action) => (
                                <button
                                    key={action.id}
                                    type="button"
                                    aria-pressed={panel === action.id}
                                    onClick={() => setPanel(action.id)}
                                    className={cx(
                                        'flex w-full items-center gap-2 rounded-control border px-3 py-2.5 text-left text-sm font-medium transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary',
                                        panel === action.id ? 'border-primary bg-primary/10 text-primary' : 'border-(--app-border) text-(--app-foreground) hover:bg-(--app-surface-muted)',
                                    )}
                                >
                                    {action.icon}
                                    <span className="min-w-0 flex-1 truncate">{action.label}</span>
                                    {action.count !== undefined && <span className={badgeClassName('neutral')}>{action.count}</span>}
                                </button>
                            ))}
                        </div>
                    </Card>

                    <Card>
                        {panel === 'recent' && (
                            <div>
                                <PanelHeader icon={<ClockIcon className="h-5 w-5" />} title={t('cm.dashboard.recent_cases')} actions={<a href={route('court.applicant.cases.index')} className={link}>{t('cm.dashboard.view_all')}</a>} />
                                {recent.length === 0 ? (
                                    <div className="py-10 text-center">
                                        <ClipboardListIcon className="mx-auto h-12 w-12 text-(--app-muted-foreground) opacity-40" />
                                        <h4 className="mt-4 text-lg font-medium text-(--app-foreground)">{t('cm.dashboard.no_cases_yet')}</h4>
                                        <p className="mx-auto mt-2 max-w-sm text-sm text-(--app-muted-foreground)">{t('cm.dashboard.no_cases_description')}</p>
                                        {filesForClients
                                            ? <a href={route('court.applicant.lawyer.file')} className={buttonClassName({ variant: 'primary', className: 'mt-6 gap-2' })}><Briefcase className="h-5 w-5" />{t('cm.lawyer.file.nav')}</a>
                                            : filesInEuisis
                                                ? <p className="mx-auto mt-4 max-w-sm text-sm text-(--app-muted-foreground)">{t('cm.cases.file_in_euisis')}</p>
                                                : <a href={route('court.applicant.cases.create')} className={buttonClassName({ variant: 'primary', className: 'mt-6 gap-2' })}><Plus className="h-5 w-5" />{t('cm.dashboard.submit_first_case')}</a>}
                                    </div>
                                ) : (
                                    <>
                                        <div className="hidden overflow-hidden rounded-card border border-(--app-border) lg:block">
                                            <Table>
                                                <THead>
                                                    <tr>
                                                        <Th>{t('cm.dashboard.case_number')}</Th>
                                                        <Th>{t('cm.dashboard.type')}</Th>
                                                        <Th>{t('cm.dashboard.status')}</Th>
                                                        <Th>{t('cm.dashboard.created')}</Th>
                                                        <Th align="right">{t('cm.dashboard.actions')}</Th>
                                                    </tr>
                                                </THead>
                                                <TBody>
                                                    {recent.slice(0, 3).map((row) => (
                                                        <Tr key={row.id}>
                                                            <Td className="whitespace-nowrap font-mono font-semibold text-primary">{row.case_number}</Td>
                                                            <Td className="whitespace-nowrap">{row.case_type && <span className={badgeClassName('info')}>{row.case_type}</span>}</Td>
                                                            <Td className="whitespace-nowrap"><span className={badgeClassName(caseStatusTone(row.status))}>{caseStatus(row.status)}</span></Td>
                                                            <Td className="whitespace-nowrap">
                                                                <div className="text-(--app-foreground)"><LocalizedDateDisplay value={row.created_at} /></div>
                                                                <div className="text-xs text-(--app-muted-foreground)">{row.created_relative}</div>
                                                            </Td>
                                                            <Td align="right" className="whitespace-nowrap">
                                                                <a href={route('court.applicant.cases.show', row.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.dashboard.view')}</a>
                                                            </Td>
                                                        </Tr>
                                                    ))}
                                                </TBody>
                                            </Table>
                                        </div>
                                        <ul className="space-y-3 lg:hidden">
                                            {recent.slice(0, 3).map((row) => (
                                                <Item key={row.id}>
                                                    <div className="mb-2 flex items-start justify-between gap-2">
                                                        <div className="min-w-0">
                                                            <span className="font-mono font-semibold text-primary">{row.case_number}</span>
                                                            <div className="mt-1"><span className={badgeClassName(caseStatusTone(row.status))}>{caseStatus(row.status)}</span></div>
                                                        </div>
                                                        <span className="shrink-0 text-xs text-(--app-muted-foreground)">{row.created_relative}</span>
                                                    </div>
                                                    {row.title && <h4 className="mb-3 font-medium text-(--app-foreground)">{row.title}</h4>}
                                                    <div className="flex items-center justify-between gap-2">
                                                        {row.case_type ? <span className={badgeClassName('info')}>{row.case_type}</span> : <span />}
                                                        <a href={route('court.applicant.cases.show', row.id)} className={link}>{t('cm.dashboard.view')}</a>
                                                    </div>
                                                </Item>
                                            ))}
                                        </ul>
                                    </>
                                )}
                            </div>
                        )}

                        {panel === 'letters' && (
                            <div>
                                <PanelHeader
                                    icon={<ScrollText className="h-5 w-5" />}
                                    title={t('cm.dashboard.letters')}
                                    description={t('cm.dashboard.letters_description')}
                                    actions={<><a href={route('court.applicant.response-replies.index')} className={link}>{t('cm.respondent.response_of_response')}</a>{toRecent}</>}
                                />
                                {letters.length === 0 ? <Empty>{t('cm.dashboard.no_letters')}</Empty> : (
                                    <ul className="space-y-2.5">
                                        {letters.map((letter) => (
                                            <Item key={letter.id}>
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="truncate font-semibold text-(--app-foreground)">{letter.subject ?? t('cm.dashboard.letters')}</div>
                                                    <div className="whitespace-nowrap text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={letter.created_at} fallback="" /></div>
                                                </div>
                                                <div className="mt-1 flex flex-wrap gap-3 text-xs text-(--app-muted-foreground)">
                                                    <span>{t('cm.dashboard.case_number')}: {letter.case_number ?? '--'}</span>
                                                    {letter.reference_number && <span>{t('cm.dashboard.reference_number')}: {letter.reference_number}</span>}
                                                </div>
                                                <div className="mt-2">
                                                    <a href={route('court.letters.case-preview', letter.id)} target="_blank" rel="noopener" className={link}>{t('cm.dashboard.view')}</a>
                                                </div>
                                            </Item>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}

                        {panel === 'responses' && (
                            <div>
                                <PanelHeader icon={<MessageSquareIcon className="h-5 w-5" />} title={t('cm.dashboard.responses')} description={t('cm.dashboard.responses_description')} actions={toRecent} />
                                {responses.length === 0 ? <Empty>{t('cm.dashboard.no_responses')}</Empty> : (
                                    <ul className="space-y-2.5">
                                        {responses.map((response) => (
                                            <Item key={response.id}>
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="truncate font-semibold text-(--app-foreground)">{response.title}</div>
                                                    <div className="whitespace-nowrap text-xs text-(--app-muted-foreground)">{response.created_relative}</div>
                                                </div>
                                                <div className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.dashboard.case_number')}: {response.case_number ?? '--'}</div>
                                                <div className="mt-3 flex justify-end">
                                                    {response.case_id ? (
                                                        <a href={route('court.applicant.cases.respondentResponses.show', [response.case_id, response.id])} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.dashboard.view')}</a>
                                                    ) : (
                                                        <button type="button" disabled className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.dashboard.view')}</button>
                                                    )}
                                                </div>
                                            </Item>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}

                        {panel === 'response-replies' && (
                            <div>
                                <PanelHeader
                                    icon={<ArchiveIcon className="h-5 w-5" />}
                                    title={t('cm.respondent.response_of_response')}
                                    description={t('cm.respondent.applicant_response_replies')}
                                    actions={<a href={route('court.applicant.response-replies.index')} className={link}>{t('cm.dashboard.view_all')}</a>}
                                />
                                {responseReplies.length === 0 ? <Empty>{t('cm.respondent.no_applicant_response_replies')}</Empty> : (
                                    <ul className="space-y-2.5">
                                        {responseReplies.map((reply) => {
                                            const status = ['accepted', 'returned', 'rejected'].includes(reply.review_status) ? reply.review_status : 'awaiting_review';
                                            return (
                                                <Item key={reply.id}>
                                                    <div className="flex items-center justify-between gap-2">
                                                        <div className="truncate font-semibold text-(--app-foreground)">{reply.case_number}</div>
                                                        <span className={badgeClassName(replyTone[status] ?? 'warning')}>{t(`cm.cases.review_status.${status}`)}</span>
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap gap-3 text-xs text-(--app-muted-foreground)">
                                                        <span>{t('cm.respondent.response_number_label')}: {reply.response_number ?? t('cm.cases.labels.not_available')}</span>
                                                        <span><LocalizedDateDisplay value={reply.created_at} withTime fallback="" /></span>
                                                    </div>
                                                    {reply.review_note && (
                                                        <div className="mt-2 text-xs text-(--app-muted-foreground)">
                                                            <span className="font-semibold text-(--app-foreground)">{t('cm.cases.reviewer_note')}</span> {reply.review_note}
                                                        </div>
                                                    )}
                                                    <div className="mt-3 flex justify-end">
                                                        <a href={route('court.applicant.cases.respondentResponses.replies.show', [reply.case_id, reply.respondent_response_id, reply.id])} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('cm.dashboard.view')}</a>
                                                    </div>
                                                </Item>
                                            );
                                        })}
                                    </ul>
                                )}
                            </div>
                        )}

                        {panel === 'decisions' && (
                            <div>
                                <PanelHeader icon={<ScaleIcon className="h-5 w-5" />} title={t('cm.dashboard.decisions')} description={t('cm.dashboard.decisions_description')} actions={toRecent} />
                                {decisions.length === 0 ? <Empty>{t('cm.dashboard.no_decisions')}</Empty> : (
                                    <ul className="space-y-2.5">
                                        {decisions.map((decision) => (
                                            <Item key={decision.id}>
                                                <div className="flex items-center justify-between gap-2">
                                                    <div className="truncate font-semibold text-(--app-foreground)">{decision.name ?? t('cm.dashboard.decisions')}</div>
                                                    <div className="whitespace-nowrap text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={decision.date} fallback="" /></div>
                                                </div>
                                                <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-(--app-muted-foreground)">
                                                    <span>{t('cm.dashboard.case_number')}: {decision.case_number ?? '--'}</span>
                                                    {decision.status && <span className={badgeClassName('neutral')}>{tx(`cm.decisions.status.${decision.status}`, decision.status)}</span>}
                                                    {decision.downloadable && (
                                                        <a href={route('court.applicant.decisions.download', decision.id)} className={badgeClassName('success', 'hover:underline')}>{t('cm.decisions.download')}</a>
                                                    )}
                                                </div>
                                            </Item>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </Card>
                </div>

                <Card>
                    <div className="mb-4 flex items-center gap-3">
                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary"><InfoIcon className="h-5 w-5" /></span>
                        <h3 className="text-base font-semibold text-(--app-foreground)">{t('cm.dashboard.quick_tips')}</h3>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {[1, 2, 3].map((n) => (
                            <div key={n} className="rounded-card border border-(--app-border) bg-(--app-surface-muted) p-4">
                                <div className="mb-1.5 font-medium text-(--app-foreground)">{t(`cm.dashboard.tip${n}_title`)}</div>
                                <p className="text-sm text-(--app-muted-foreground)">{t(`cm.dashboard.tip${n}_description`)}</p>
                            </div>
                        ))}
                    </div>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
