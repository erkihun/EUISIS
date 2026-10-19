import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import JobDescriptionForm from '@/Components/organizationDesign/JobDescriptionForm';
import SectionForm from '@/Components/organizationDesign/SectionForm';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import ValidationReport, { type Report } from '@/Components/organizationDesign/ValidationReport';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, Table, TBody, Td, Th, THead, Tr, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Named = { id: string; code: string; name_en: string; name_am: string | null };
type Section = { id: string; section_key: string; source: 'derived' | 'custom'; sequence: number; title_en: string; title_am: string | null; content_en: string | null; content_am: string | null; payload: unknown };
type DutyRow = { id: string; code?: string; service_no?: string; name_en: string; name_am: string | null; required_output?: string | null; is_primary?: boolean };
type Duties = { services: DutyRow[]; processes: DutyRow[]; activities: DutyRow[]; tasks: DutyRow[]; position_services: DutyRow[] };
type CompetencyRow = { competency: Named | null; level: (Named & { rank: number }) | null; is_mandatory: boolean; source: string };
type QualificationRow = { education_level: Named | null; field_of_study: string | null; certification: string | null; license: string | null; experience_years: number; is_mandatory: boolean; source: string };
type StandardRow = { task_code: string | null; version_no: number; planned_quantity: string | null; quantity_unit: string | null; planned_time_minutes: string | null; planned_quality: string | null; quality_unit: string | null; effective_from: string | null };
type Props = {
    jobDescription: {
        id: string; reference_number: string | null; version_no: number; status: string; effective_from: string | null; effective_to: string | null; review_comment: string | null;
        position: { id: string; job_position_code: string; title_en: string; title_am: string | null; grade_level: string | null; organization: Named | null; unit: Named | null };
        profile: { id: string; version_no: number; status: string } | null;
        prepared_by: string | null; reviewed_by: string | null; approved_by: string | null; published_by: string | null; submitted_at: string | null; approved_at: string | null; published_at: string | null; generated_at: string | null;
    };
    sections: Section[];
    versions: { id: string; version_no: number; status: string; effective_from: string | null; effective_to: string | null }[];
    validation: Report;
    history: { from: string | null; to: string; actor: string | null; reason: string | null; at: string }[];
    can: { update: boolean; submit: boolean; review: boolean; approve: boolean; publish: boolean; cancel: boolean; version: boolean };
};

const num = (v: string | null) => (v == null ? '—' : String(Number(v)));

export default function JobDescriptionShow({ jobDescription: jd, sections, versions, validation, history, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState<Section | null>(null);
    const [versionOpen, setVersionOpen] = useState(false);
    const text = (en: string | null, am: string | null) => (locale === 'am' && am) || en || null;
    const title = `${jd.reference_number ?? tt('positionDesign.jd.title')} · ${titleOf(jd.position, locale)}`;

    async function act(action: 'submit' | 'approve' | 'publish' | 'cancel' | 'regenerate', variant: 'warning' | 'danger' = 'warning') {
        const { confirmed } = await confirm({ title: tt(`positionDesign.jd.${action}`), description: tt(`positionDesign.jd.${action}Confirm`), variant });
        if (confirmed) router.post(route(`organization-design.job-descriptions.${action}`, jd.id), {}, { preserveScroll: true });
    }
    async function returnToDraft() {
        const { confirmed, reason } = await confirm({ title: tt('positionDesign.jd.return'), description: tt('positionDesign.jd.returnPrompt'), variant: 'warning', requireReason: true });
        if (confirmed) router.post(route('organization-design.job-descriptions.return', jd.id), { reason }, { preserveScroll: true });
    }

    const detail = (label: string, value: React.ReactNode) => (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 wrap-break-word text-sm text-(--app-foreground)">{value === null || value === undefined || value === '' ? '—' : value}</dd>
        </div>
    );
    const empty = <p className="text-sm text-(--app-muted-foreground)">{tt('positionDesign.jd.empty')}</p>;
    const dutyList = (label: string, rows: DutyRow[]) => rows.length === 0 ? null : (
        <div key={label}>
            <p className="text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{label}</p>
            <ul className="mt-1 space-y-1 text-sm">
                {rows.map((r) => (
                    <li key={r.id} className="flex flex-wrap items-baseline gap-x-2">
                        <span className="font-mono text-xs text-(--app-muted-foreground)">{r.code ?? r.service_no}</span>
                        <span>{nameOf(r, locale)}</span>
                        {r.is_primary && <span className={badgeClassName('success')}>{tt('positionDesign.primary')}</span>}
                        {r.required_output && <span className="text-xs text-(--app-muted-foreground)">{tt('positionDesign.jd.duties.requiredOutput')}: {r.required_output}</span>}
                    </li>
                ))}
            </ul>
        </div>
    );

    function body(section: Section): React.ReactNode {
        const payload = section.payload;
        switch (section.section_key) {
            case 'duties': {
                const d = (payload ?? {}) as Partial<Duties>;
                const blocks = (['services', 'processes', 'activities', 'tasks', 'position_services'] as const).map((k) => dutyList(tt(`positionDesign.jd.duties.${k}`), d[k] ?? [])).filter(Boolean);
                return blocks.length === 0 ? empty : <div className="space-y-3">{blocks}</div>;
            }
            case 'competencies': {
                const rows = (payload ?? []) as CompetencyRow[];
                return rows.length === 0 ? empty : (
                    <Table>
                        <THead><tr><Th>{tt('fields.competency')}</Th><Th>{tt('fields.minimumLevel')}</Th><Th>{tt('positionDesign.mandatory')}</Th></tr></THead>
                        <TBody>{rows.map((r, i) => <Tr key={i}><Td>{r.competency ? `${r.competency.code} · ${nameOf(r.competency, locale)}` : '—'}</Td><Td muted>{r.level ? `${r.level.rank} · ${nameOf(r.level, locale)}` : '—'}</Td><Td muted>{r.is_mandatory ? tt('positionDesign.mandatory') : tt('positionDesign.optional')}</Td></Tr>)}</TBody>
                    </Table>
                );
            }
            case 'qualifications': {
                const rows = (payload ?? []) as QualificationRow[];
                return rows.length === 0 ? empty : (
                    <Table>
                        <THead><tr><Th>{tt('fields.educationLevel')}</Th><Th>{tt('fields.fieldOfStudy')}</Th><Th>{tt('fields.certification')}</Th><Th>{tt('fields.license')}</Th><Th align="right">{tt('fields.experienceYears')}</Th></tr></THead>
                        <TBody>{rows.map((r, i) => <Tr key={i}><Td>{nameOf(r.education_level, locale) || '—'}</Td><Td muted>{r.field_of_study ?? '—'}</Td><Td muted>{r.certification ?? '—'}</Td><Td muted>{r.license ?? '—'}</Td><Td align="right">{r.experience_years}</Td></Tr>)}</TBody>
                    </Table>
                );
            }
            case 'standards': {
                const rows = (payload ?? []) as StandardRow[];
                return rows.length === 0 ? empty : (
                    <Table>
                        <THead><tr><Th>{tt('positionDesign.jd.standards.task')}</Th><Th align="right">{tt('positionDesign.jd.standards.quantity')}</Th><Th align="right">{tt('positionDesign.jd.standards.time')}</Th><Th align="right">{tt('positionDesign.jd.standards.quality')}</Th><Th>{tt('positionDesign.jd.standards.from')}</Th></tr></THead>
                        <TBody>{rows.map((r, i) => <Tr key={i}><Td className="font-mono text-xs">{r.task_code ?? '—'} <span className="text-(--app-muted-foreground)">v{r.version_no}</span></Td><Td align="right">{num(r.planned_quantity)} {r.quantity_unit ?? ''}</Td><Td align="right">{num(r.planned_time_minutes)}</Td><Td align="right">{num(r.planned_quality)} {r.quality_unit ?? ''}</Td><Td muted><LocalizedDateDisplay value={r.effective_from} /></Td></Tr>)}</TBody>
                    </Table>
                );
            }
            default: {
                return <RichTextViewer html={text(section.content_en, section.content_am)} empty={empty} />;
            }
        }
    }

    return (
        <AuthenticatedLayout header={
            <PageHeader
                backHref={route('organization-design.position-design.show', jd.position.id)}
                title={title}
                description={[nameOf(jd.position.organization, locale), jd.position.unit ? `${jd.position.unit.code} · ${nameOf(jd.position.unit, locale)}` : null, jd.position.grade_level].filter(Boolean).join(' · ')}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        <a href={route('organization-design.job-descriptions.pdf', { jobDescription: jd.id, locale: 'en' })} className={actionLinkClassName()}>{tt('positionDesign.jd.pdfEn')}</a>
                        <a href={route('organization-design.job-descriptions.pdf', { jobDescription: jd.id, locale: 'am' })} className={actionLinkClassName()}>{tt('positionDesign.jd.pdfAm')}</a>
                        {can.update && <Button variant="outline" onClick={() => act('regenerate')}>{tt('positionDesign.jd.regenerate')}</Button>}
                        {can.cancel && <Button variant="destructiveOutline" onClick={() => act('cancel', 'danger')}>{tt('positionDesign.jd.cancel')}</Button>}
                        {can.submit && <Button variant="primary" onClick={() => act('submit')}>{tt('positionDesign.jd.submit')}</Button>}
                        {can.review && <Button variant="outline" onClick={returnToDraft}>{tt('positionDesign.jd.return')}</Button>}
                        {can.approve && <Button variant="primary" onClick={() => act('approve')}>{tt('positionDesign.jd.approve')}</Button>}
                        {can.publish && <Button variant="primary" onClick={() => act('publish')}>{tt('positionDesign.jd.publish')}</Button>}
                        {can.version && <Button variant="primary" onClick={() => setVersionOpen(true)}>{tt('positionDesign.jd.newVersion')}</Button>}
                    </div>
                )}
            />
        }>
            <Head title={title} />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="flex items-center justify-between gap-3">
                            <CardTitle>{tt('positionDesign.jd.details')}</CardTitle>
                            <div className="flex items-center gap-2"><StatusPill status={jd.status} /><span className={badgeClassName('neutral')}>v{jd.version_no}</span></div>
                        </CardHeader>
                        <dl className="grid gap-4 p-5 sm:grid-cols-2">
                            {detail(tt('fields.effectiveFrom'), <><LocalizedDateDisplay value={jd.effective_from} />{jd.effective_to && <> – <LocalizedDateDisplay value={jd.effective_to} /></>}</>)}
                            {detail(tt('positionDesign.jd.profile'), jd.profile ? <>v{jd.profile.version_no} <StatusPill status={jd.profile.status} /></> : null)}
                            {detail(tt('positionDesign.jd.preparedBy'), jd.prepared_by)}
                            {detail(tt('positionDesign.jd.reviewedBy'), jd.reviewed_by)}
                            {detail(tt('positionDesign.jd.reviewComment'), jd.review_comment)}
                            {detail(tt('positionDesign.jd.approvedBy'), jd.approved_by)}
                            {detail(tt('positionDesign.jd.publishedBy'), jd.published_by)}
                            {detail(tt('positionDesign.jd.generatedAt'), jd.generated_at ? <LocalizedDateDisplay value={jd.generated_at} withTime /> : null)}
                        </dl>
                        {jd.status === 'under_review' && !can.approve && can.review && <p className="border-t border-(--app-border) px-5 py-3 text-xs text-(--app-muted-foreground)">{tt('positionDesign.jd.approveByOther')}</p>}
                    </Card>

                    {sections.map((s) => (
                        <Card key={s.id}>
                            <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-2">
                                    <CardTitle>{(locale === 'am' && s.title_am) || s.title_en}</CardTitle>
                                    <span className={badgeClassName(s.source === 'derived' ? 'info' : 'neutral')}>{tt(`positionDesign.jd.${s.source}`)}</span>
                                </div>
                                {can.update && s.source === 'custom' && editing?.id !== s.id && <button type="button" className={actionLinkClassName()} onClick={() => setEditing(s)}>{t('common.edit')}</button>}
                            </CardHeader>
                            <div className="p-5">
                                {editing?.id === s.id
                                    ? <SectionForm key={s.id} jobDescriptionId={jd.id} sectionKey={s.section_key} title={(locale === 'am' && s.title_am) || s.title_en} contentEn={s.content_en} contentAm={s.content_am} onClose={() => setEditing(null)} />
                                    : body(s)}
                            </div>
                        </Card>
                    ))}
                </div>

                <div className="space-y-4">
                    <ValidationReport report={validation} compact />
                    <Card>
                        <CardHeader><CardTitle>{tt('positionDesign.versions')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-(--app-border)">
                            {versions.map((v) => (
                                <li key={v.id} className="flex items-center justify-between gap-2 px-5 py-2 text-sm">
                                    <span>{v.id === jd.id ? <strong>v{v.version_no}</strong> : <Link href={route('organization-design.job-descriptions.show', v.id)} className={actionLinkClassName({ size: 'sm' })}>v{v.version_no}</Link>} · <LocalizedDateDisplay value={v.effective_from} /></span>
                                    <StatusPill status={v.status} />
                                </li>
                            ))}
                        </ul>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>{tt('positionDesign.history')}</CardTitle></CardHeader>
                        <ul className="divide-y divide-(--app-border)">
                            {history.map((h, i) => (
                                <li key={i} className="px-5 py-3 text-sm">
                                    <p className="font-medium text-(--app-foreground)">{h.from ? `${tt(`status.${h.from}`)} → ` : ''}{tt(`status.${h.to}`)}</p>
                                    <p className="text-xs text-(--app-muted-foreground)">{h.actor ?? '—'} · <LocalizedDateDisplay value={h.at} withTime /></p>
                                    {h.reason && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{h.reason}</p>}
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>

            {can.version && <JobDescriptionForm show={versionOpen} onClose={() => setVersionOpen(false)} title={tt('positionDesign.jd.newVersion')} url={route('organization-design.job-descriptions.versions.store', jd.id)} />}
        </AuthenticatedLayout>
    );
}
