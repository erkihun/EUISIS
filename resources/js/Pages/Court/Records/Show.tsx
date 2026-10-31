import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Alert, Button, Card, FormField, Input, Select, Textarea, badgeClassName, buttonClassName, cx, type Tone } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useInspectionPagesText } from '@/court/i18n/useInspectionPagesText';
import type { AppealOutcome, RecordAppeal, RecordSection, RecordShowProps } from '@/court/types/records';

const SECTIONS: RecordSection[] = ['origin', 'case', 'pleadings', 'hearings', 'witnesses-letters', 'decision', 'appeal', 'documents'];

const OUTCOME_TONES: Record<AppealOutcome, Tone> = { confirmed: 'success', modified: 'warning', reversed: 'danger' };
const STATUS_TONES: Record<RecordAppeal['status'], Tone> = { draft: 'neutral', submitted: 'info', under_review: 'info', approved: 'success', rejected: 'danger', closed: 'neutral' };

/** `:name` placeholders filled in. */
const fill = (template: string, values: Record<string, string | number>): string =>
    Object.entries(values).reduce((out, [key, value]) => out.split(`:${key}`).join(String(value)), template);

/** A text with its `:date` shown in the app's calendar. */
function Dated({ text, value }: { text: string; value: string | null }) {
    const [before, after = ''] = text.split(':date');
    return <>{before}<LocalizedDateDisplay value={value} fallback="—" />{after}</>;
}

/**
 * One case's record, organized as it is taken to the appellate court (EUISIS
 * ADR 0013 #14): where the case started in EUISIS (the grievance or the
 * discipline case), the case at this court, the pleadings, the hearings and the
 * bench's notes, witnesses and letters, the decision, the appeal and the
 * documents, in that order, on one page with its contents at the left. The
 * appeal section is where the registry releases the record to a party (the case
 * is then marked as appealed) and records the appellate court's outcome.
 */
export default function RecordShow(props: RecordShowProps) {
    const { case: kase, appeal } = props;
    const { t, tx } = useInspectionPagesText();
    const b = (key: string) => t(`cm.appeal_records.bundle.${key}`);
    const title = t('cm.recordes.titles.record');
    const na = t('cm.recordes.messages.not_available');
    const statusLabel = kase.status ? tx(`cm.cases.status.${kase.status}`, kase.status) : na;
    const labels: Record<RecordSection, string> = {
        origin: b('origin'),
        case: b('the_case'),
        pleadings: b('pleadings'),
        hearings: b('hearings'),
        'witnesses-letters': b('witnesses_letters'),
        decision: b('decision'),
        appeal: b('appeal'),
        documents: b('annexes'),
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={[kase.case_number, kase.title ?? t('cm.recordes.messages.untitled_case')].filter(Boolean).join(' — ')} backHref={route('court.recordes.index')} actions={(
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={route('court.cases.show', kase.id)} className={buttonClassName({ variant: 'outline' })}>{tx('cm.recordes.buttons.open_case', 'Open case')}</Link>
                    <a href={route('court.recordes.pdf', kase.id)} target="_blank" rel="noopener" className={buttonClassName({ variant: 'outline' })}>{t('cm.recordes.buttons.pdf')}</a>
                    <a href={route('court.recordes.appeal-pdf', kase.id)} className={buttonClassName({})}>{t('cm.appeal_records.panel.download')}</a>
                </div>
            )} />
        )}>
            <Head title={`${title} ${kase.case_number ?? ''}`.trim()} />

            <div className="space-y-6">
                <Card className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <Fact label={b('case_number')}><span className="font-mono">{kase.case_number ?? na}</span></Fact>
                    <Fact label={t('cm.recordes.labels.status_label')}>{statusLabel}</Fact>
                    <Fact label={b('filed')}><LocalizedDateDisplay value={kase.filing_date} /></Fact>
                    <Fact label={b('respondent')}>{kase.respondent_name ?? na}</Fact>
                    <Fact label={b('appeal')}>
                        {appeal.appealedAt ? (
                            <span className="flex flex-wrap items-center gap-1.5">
                                <span className={badgeClassName('warning')}>{t('cm.appeal_records.register.appealed')}</span>
                                {appeal.outcome && <span className={badgeClassName(OUTCOME_TONES[appeal.outcome])}>{t(`cm.appeal_records.outcomes.${appeal.outcome}`)}</span>}
                            </span>
                        ) : <span className="text-(--app-muted-foreground)">{t('cm.appeal_records.register.none')}</span>}
                    </Fact>
                </Card>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-4">
                    <aside className="lg:sticky lg:top-24 lg:self-start">
                        <Card className="space-y-3">
                            <p className="text-xs text-(--app-muted-foreground)">{b('cover_note')}</p>
                            <nav aria-label={title}>
                                <ol className="space-y-0.5">
                                    {SECTIONS.map((section, index) => (
                                        <li key={section}>
                                            <a href={`#section-${section}`} className="flex items-center gap-2 rounded-control px-2.5 py-2 text-sm text-(--app-muted-foreground) transition-colors hover:bg-(--app-surface-muted) hover:text-(--app-foreground)">
                                                <span className="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">{index + 1}</span>
                                                <span>{labels[section]}</span>
                                            </a>
                                        </li>
                                    ))}
                                </ol>
                            </nav>
                        </Card>
                    </aside>

                    <div className="space-y-6 lg:col-span-3">
                        <Section id="origin" number={1} title={labels.origin}>
                            {props.origins.length === 0 ? <Empty>{b('no_origin')}</Empty> : props.origins.map((origin) => (
                                <Item key={origin.id}>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold">
                                                <span className={cx(badgeClassName(origin.kind === 'discipline' ? 'warning' : 'info'), 'mr-2')}>{b(`origin_${origin.kind}`)}</span>
                                                <span className="font-mono">{origin.reference ?? na}</span> — {origin.subject}
                                            </p>
                                            <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                                {origin.category && <>{origin.category} · </>}
                                                {b('opened')} <LocalizedDateDisplay value={origin.submitted_at} fallback={na} /> · {b('closed')} <LocalizedDateDisplay value={origin.closed_at} fallback={na} />
                                            </p>
                                        </div>
                                        {origin.status && <span className={badgeClassName('neutral')}>{origin.status}</span>}
                                    </div>
                                    {origin.description && <p className="whitespace-pre-line">{origin.description}</p>}
                                    {origin.responses.length > 0 && (
                                        <div className="space-y-1.5">
                                            <h4 className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{b('origin_responses')}</h4>
                                            {origin.responses.map((response, index) => (
                                                <p key={index} className="whitespace-pre-line border-l-2 border-(--app-border) pl-3"><span className="block text-xs text-(--app-muted-foreground)">{response.at}</span>{response.body}</p>
                                            ))}
                                        </div>
                                    )}
                                    {origin.decisions.length > 0 && (
                                        <div className="space-y-1.5">
                                            <h4 className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{b('origin_decisions')}</h4>
                                            {origin.decisions.map((item, index) => (
                                                <p key={index} className="whitespace-pre-line border-l-2 border-primary/40 pl-3">
                                                    <span className="block text-xs font-semibold">{[item.number, item.type].filter(Boolean).join(' ')} <span className="font-normal text-(--app-muted-foreground)">{item.at}</span></span>
                                                    {item.text}
                                                </p>
                                            ))}
                                        </div>
                                    )}
                                </Item>
                            ))}
                        </Section>

                        <Section id="case" number={2} title={labels.case}>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="text-xs uppercase tracking-wide text-(--app-muted-foreground)">
                                        <tr><th className="py-2 pr-4 font-medium">{b('name')}</th><th className="py-2 pr-4 font-medium">{b('role')}</th><th className="py-2 font-medium">{b('lawyer')}</th></tr>
                                    </thead>
                                    <tbody className="divide-y divide-(--app-border)">
                                        {props.parties.map((party) => (
                                            <tr key={party.id}>
                                                <td className="py-2 pr-4 font-medium text-(--app-foreground)">{party.name}</td>
                                                <td className="py-2 pr-4">{t(`cm.case_parties.panel.roles.${party.role}`)}</td>
                                                <td className="py-2 text-(--app-muted-foreground)">{party.lawyers.map((lawyer) => `${lawyer.name} (${lawyer.license_number ?? '—'})`).join(', ') || '—'}</td>
                                            </tr>
                                        ))}
                                        <tr>
                                            <td className="py-2 pr-4 font-medium text-(--app-foreground)">{kase.respondent_name ?? na}</td>
                                            <td className="py-2 pr-4">{b('respondent')}</td>
                                            <td className="py-2 text-(--app-muted-foreground)">{kase.respondent_address ?? '—'}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div className="space-y-2">
                                <h3 className="text-sm font-semibold text-(--app-foreground)">{b('claim')}</h3>
                                <RichTextViewer html={kase.description} className="text-sm" empty={<Empty>{t('cm.recordes.messages.no_details_provided')}</Empty>} />
                            </div>
                            {kase.relief_requested && (
                                <div className="space-y-2">
                                    <h3 className="text-sm font-semibold text-(--app-foreground)">{b('relief')}</h3>
                                    <RichTextViewer html={kase.relief_requested} className="text-sm" />
                                </div>
                            )}
                        </Section>

                        <Section id="pleadings" number={3} title={labels.pleadings}>
                            {props.respondentResponses.length === 0 ? <Empty>{b('no_pleadings')}</Empty> : props.respondentResponses.map((response) => (
                                <Item key={response.id}>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <p className="font-semibold">{response.title ?? b('response')}</p>
                                        <span className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={response.created_at} withTime fallback="" /></span>
                                    </div>
                                    {response.description && <p>{response.description}</p>}
                                    {response.download_url && <a href={response.download_url} target="_blank" rel="noopener" className="text-xs font-medium text-primary hover:underline">{t('cm.recordes.labels.attachment')}</a>}
                                    {props.replies.filter((reply) => reply.response_id === response.id).map((reply) => (
                                        <div key={reply.id} className="ml-4 space-y-1 border-l-2 border-primary/40 pl-3">
                                            <p className="text-xs font-semibold text-(--app-muted-foreground)">{b('reply')} · <LocalizedDateDisplay value={reply.created_at} fallback="" /></p>
                                            <RichTextViewer html={reply.description} className="text-sm" />
                                        </div>
                                    ))}
                                </Item>
                            ))}
                        </Section>

                        <Section id="hearings" number={4} title={labels.hearings}>
                            {props.hearings.length === 0 ? <Empty>{b('no_hearings')}</Empty> : props.hearings.map((hearing) => (
                                <Item key={hearing.id}>
                                    <p className="font-semibold">
                                        {hearing.hearing_at ? <LocalizedDateDisplay value={hearing.hearing_at} withTime /> : na}
                                        {hearing.type && <span className="font-normal text-(--app-muted-foreground)"> · {hearing.type}</span>}
                                        {hearing.location && <span className="font-normal text-(--app-muted-foreground)"> · {hearing.location}</span>}
                                    </p>
                                    {hearing.judge_notes && <p><span className="font-semibold">{t('cm.recordes.labels.judge_notes')}</span> {hearing.judge_notes}</p>}
                                    {hearing.notes && <p className="text-(--app-muted-foreground)">{hearing.notes}</p>}
                                </Item>
                            ))}
                            {props.benchNotes.length > 0 && (
                                <div className="space-y-2">
                                    <h3 className="text-sm font-semibold text-(--app-foreground)">{b('bench_notes')}</h3>
                                    {props.benchNotes.map((note) => (
                                        <Item key={note.id}>
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <p className="font-semibold">{note.title ?? b('bench_note')}</p>
                                                <span className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={note.created_at} withTime fallback="" /></span>
                                            </div>
                                            <RichTextViewer html={note.note} className="text-sm" />
                                            {note.author && <p className="text-xs text-(--app-muted-foreground)">{note.author}</p>}
                                        </Item>
                                    ))}
                                </div>
                            )}
                        </Section>

                        <Section id="witnesses-letters" number={5} title={labels['witnesses-letters']}>
                            {props.witnesses.length === 0 ? <Empty>{b('no_witnesses')}</Empty> : (
                                <ul className="grid gap-2 sm:grid-cols-2">
                                    {props.witnesses.map((witness) => (
                                        <li key={witness.id} className="rounded-control border border-(--app-border) p-3 text-sm">
                                            <p className="font-semibold">{witness.full_name ?? na}</p>
                                            <p className="text-xs text-(--app-muted-foreground)">{[witness.phone, witness.email, witness.address].filter(Boolean).join(' · ') || na}</p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {props.letters.map((letter) => (
                                <Item key={letter.id}>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold">{letter.subject ?? t('cm.recordes.labels.letter')}</p>
                                            <p className="text-xs text-(--app-muted-foreground)">{letter.reference_number ?? na}{letter.author_name && <> · {letter.author_name}</>}</p>
                                        </div>
                                        <span className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={letter.created_at} fallback="" /></span>
                                    </div>
                                    {letter.body && <RichTextViewer html={letter.body} className="text-sm" />}
                                </Item>
                            ))}
                        </Section>

                        <Section id="decision" number={6} title={labels.decision}>
                            {props.decision ? (
                                <Item>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <p className="font-semibold">{props.decision.title ?? t('cm.recordes.labels.decision')}</p>
                                        <span className="text-xs text-(--app-muted-foreground)"><LocalizedDateDisplay value={props.decision.created_at} fallback="" /></span>
                                    </div>
                                    {props.decision.content && <RichTextViewer html={props.decision.content} className="text-sm" />}
                                </Item>
                            ) : <Empty>{b('no_decision')}</Empty>}
                        </Section>

                        <Section id="appeal" number={7} title={t('cm.appeal_records.panel.title')}>
                            <AppealPanel caseId={kase.id} caseNumber={kase.case_number ?? ''} panel={appeal} />
                        </Section>

                        <Section id="documents" number={8} title={labels.documents}>
                            {props.submittedDocuments.length + props.otherDocuments.length + props.evidences.length === 0 ? <Empty>{b('no_annexes')}</Empty> : (
                                <ol className="divide-y divide-(--app-border) rounded-control border border-(--app-border)">
                                    {[...props.evidences.map((evidence) => ({ key: `e${evidence.id}`, title: evidence.title, at: evidence.created_at, stage: 'filing' })),
                                        ...props.submittedDocuments.map((document, index) => ({ key: `s${document.id ?? index}`, title: document.label, at: document.created_at, stage: 'filing' })),
                                        ...props.otherDocuments.map((document, index) => ({ key: `o${document.id ?? index}`, title: document.label, at: document.created_at, stage: 'proceedings' }))]
                                        .map((row, index) => (
                                            <li key={row.key} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5 text-sm">
                                                <span className="flex min-w-0 items-center gap-3">
                                                    <span className="w-6 shrink-0 text-right text-xs tabular-nums text-(--app-muted-foreground)">{index + 1}</span>
                                                    <span className="font-medium text-(--app-foreground)">{row.title ?? b('document')}</span>
                                                </span>
                                                <span className="text-xs text-(--app-muted-foreground)">{b(`stages.${row.stage}`)} · <LocalizedDateDisplay value={row.at} fallback="" /></span>
                                            </li>
                                        ))}
                                </ol>
                            )}
                            <p className="text-xs text-(--app-muted-foreground)">{t('cm.appeal_records.panel.download_help')}</p>
                        </Section>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

/**
 * The appeal to the appellate court: every request on the case with what the
 * registry did about it, the release and refusal, and the appellate outcome.
 */
function AppealPanel({ caseId, caseNumber, panel }: { caseId: number; caseNumber: string; panel: RecordShowProps['appeal'] }) {
    const { t } = useInspectionPagesText();
    const a = (key: string, values: Record<string, string | number> = {}) => fill(t(`cm.appeal_records.panel.${key}`), values);
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState(false);
    const [recording, setRecording] = useState<number | null>(null);
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    const release = async (appeal: RecordAppeal) => {
        const result = await confirm({ title: a('release'), description: a('release_confirm', { number: caseNumber, name: appeal.appellant ?? '' }), confirmLabel: a('release'), cancelLabel: a('cancel'), variant: 'success', requireReason: false });
        if (result.confirmed) router.post(route('court.appeals.decide', appeal.id), { decision: 'approved' }, options);
    };
    const refuse = async (appeal: RecordAppeal) => {
        const result = await confirm({ title: a('refuse'), confirmLabel: a('refuse'), cancelLabel: a('cancel'), variant: 'danger', requireReason: true, reasonLabel: a('reason') });
        if (result.confirmed) router.post(route('court.appeals.decide', appeal.id), { decision: 'rejected', decision_notes: result.reason ?? '' }, options);
    };

    return (
        <div className="space-y-4">
            <p className="text-sm text-(--app-muted-foreground)">{a('help')}</p>
            {panel.appealedAt && <Alert tone="warning"><Dated text={a('marked')} value={panel.appealedAt} />{panel.outcome && <> · {a('outcome_marked', { outcome: t(`cm.appeal_records.outcomes.${panel.outcome}`) })}</>}</Alert>}
            {!panel.appealable && panel.rows.length === 0 && <Empty>{a('not_appealable')}</Empty>}
            {panel.appealable && panel.rows.length === 0 && <Empty>{a('none')}</Empty>}

            {panel.rows.map((appeal) => (
                <Item key={appeal.id} className={cx(busy && 'opacity-60')}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="font-semibold">
                                <Link href={route('court.appeals.show', appeal.id)} className="font-mono hover:underline">{appeal.appeal_number}</Link>
                                {appeal.appellant && <> — {appeal.appellant}</>}
                                {appeal.side && <span className="font-normal text-(--app-muted-foreground)"> ({t(`cm.appeal_records.sides.${appeal.side}`)})</span>}
                            </p>
                            <p className="text-xs text-(--app-muted-foreground)">
                                <Dated text={a('requested_on', { via: appeal.via ? t(`cm.appeal_records.via.${appeal.via}`) : '' })} value={appeal.requested_at} />
                            </p>
                            {appeal.released_at && <p className="text-xs text-(--app-muted-foreground)"><Dated text={a('released_on', { name: appeal.released_by ?? '—' })} value={appeal.released_at} /></p>}
                            {appeal.status === 'rejected' && <p className="text-xs text-red-700 dark:text-red-400"><Dated text={a('refused_on', { note: appeal.note ?? '' })} value={appeal.decided_at} /></p>}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className={badgeClassName(STATUS_TONES[appeal.status])}>{t(`cm.appeal_records.statuses.${appeal.status}`)}</span>
                            {appeal.outcome && <span className={badgeClassName(OUTCOME_TONES[appeal.outcome])}>{t(`cm.appeal_records.outcomes.${appeal.outcome}`)}</span>}
                        </div>
                    </div>
                    {appeal.grounds && <p className="whitespace-pre-line"><span className="font-semibold">{a('grounds')}:</span> {appeal.grounds}</p>}
                    {appeal.outcome && (
                        <p className="text-sm">
                            {[appeal.appellate_court, appeal.appellate_case_number, appeal.outcome_date].filter(Boolean).join(' · ')}
                            {appeal.outcome_notes && <span className="block text-(--app-muted-foreground)">{appeal.outcome_notes}</span>}
                            {appeal.outcome_document_url && <a href={appeal.outcome_document_url} className="mt-1 inline-block text-xs font-medium text-primary hover:underline">{a('outcome_document')}</a>}
                        </p>
                    )}

                    {['submitted', 'under_review'].includes(appeal.status) && panel.can.release && (
                        <div className="flex flex-wrap gap-2 pt-1">
                            <Button type="button" size="sm" disabled={busy} onClick={() => { void release(appeal); }}>{a('release')}</Button>
                            <Button type="button" size="sm" variant="destructiveOutline" disabled={busy} onClick={() => { void refuse(appeal); }}>{a('refuse')}</Button>
                        </div>
                    )}
                    {appeal.status === 'approved' && panel.can.outcome && (recording === appeal.id
                        ? <OutcomeForm appealId={appeal.id} onDone={() => setRecording(null)} />
                        : <div className="pt-1"><Button type="button" size="sm" variant="secondary" onClick={() => setRecording(appeal.id)}>{a('record_outcome')}</Button></div>)}
                </Item>
            ))}

            {panel.appealable && panel.can.request && (
                <Link href={route('court.appeals.create', { case: caseId })} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{a('record_request')}</Link>
            )}
            <p className="text-xs text-(--app-muted-foreground)">{a('judges_note')}</p>
        </div>
    );
}

/** The appellate court's outcome on a released record. */
function OutcomeForm({ appealId, onDone }: { appealId: number; onDone: () => void }) {
    const { t } = useInspectionPagesText();
    const a = (key: string) => t(`cm.appeal_records.panel.${key}`);
    const form = useForm({ outcome: '', outcome_date: '', appellate_court: '', appellate_case_number: '', outcome_notes: '', document: null as File | null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.appeals.outcome', appealId), { forceFormData: true, preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-control border border-(--app-border) bg-(--app-surface) p-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <FormField label={a('outcome')} required error={form.errors.outcome}>
                    {({ id, describedBy }) => (
                        <Select id={id} value={form.data.outcome} onChange={(event) => form.setData('outcome', event.target.value)} required aria-describedby={describedBy}>
                            <option value="">—</option>
                            {(['confirmed', 'modified', 'reversed'] as const).map((outcome) => <option key={outcome} value={outcome}>{t(`cm.appeal_records.outcomes.${outcome}`)}</option>)}
                        </Select>
                    )}
                </FormField>
                <FormField label={a('outcome_date')} required error={form.errors.outcome_date}>
                    {({ id, describedBy }) => <Input id={id} type="date" value={form.data.outcome_date} onChange={(event) => form.setData('outcome_date', event.target.value)} required aria-describedby={describedBy} />}
                </FormField>
                <FormField label={a('appellate_court')} error={form.errors.appellate_court}>
                    {({ id }) => <Input id={id} value={form.data.appellate_court} onChange={(event) => form.setData('appellate_court', event.target.value)} />}
                </FormField>
                <FormField label={a('appellate_case_number')} error={form.errors.appellate_case_number}>
                    {({ id }) => <Input id={id} value={form.data.appellate_case_number} onChange={(event) => form.setData('appellate_case_number', event.target.value)} />}
                </FormField>
            </div>
            <FormField label={a('notes')} error={form.errors.outcome_notes}>
                {({ id }) => <Textarea id={id} rows={2} value={form.data.outcome_notes} onChange={(event) => form.setData('outcome_notes', event.target.value)} />}
            </FormField>
            <FormField label={a('document')} error={form.errors.document}>
                {({ id }) => (
                    <input id={id} type="file" accept="application/pdf" onChange={(event) => form.setData('document', event.target.files?.[0] ?? null)}
                        className="block w-full text-sm text-(--app-muted-foreground) file:mr-3 file:rounded-control file:border-0 file:bg-(--app-surface-muted) file:px-3 file:py-2 file:text-sm file:font-medium" />
                )}
            </FormField>
            <div className="flex flex-wrap gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>{a('save_outcome')}</Button>
                <Button type="button" size="sm" variant="ghost" onClick={onDone}>{a('cancel')}</Button>
            </div>
        </form>
    );
}

function Section({ id, number, title, children }: { id: RecordSection; number: number; title: ReactNode; children: ReactNode }) {
    return (
        <Card id={`section-${id}`} className="scroll-mt-24 space-y-4">
            <h2 className="flex items-center gap-2.5 border-b border-(--app-border) pb-3 text-base font-semibold text-(--app-foreground)">
                <span className="grid h-7 w-7 place-items-center rounded-full bg-primary text-xs font-bold text-white">{number}</span>
                {title}
            </h2>
            {children}
        </Card>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{label}</p>
            <div className="mt-1 text-sm font-medium text-(--app-foreground)">{children}</div>
        </div>
    );
}

function Item({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cx('space-y-2 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4 text-sm text-(--app-foreground)', className)}>{children}</div>;
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="text-sm text-(--app-muted-foreground)">{children}</p>;
}
