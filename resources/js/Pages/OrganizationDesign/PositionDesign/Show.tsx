import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import JobDescriptionForm, { type ProfileOption } from '@/Components/organizationDesign/JobDescriptionForm';
import PositionRequirementsForm, { type PositionRequirementCatalog } from '@/Components/organizationDesign/PositionRequirementsForm';
import ProfileForm, { emptyProfile, type ProfileFormValues } from '@/Components/organizationDesign/ProfileForm';
import WorkLinksForm, { type WorkObject, type WorkObjectType } from '@/Components/organizationDesign/WorkLinksForm';
import { StatusPill, nameOf, titleOf, useDesignText } from '@/Components/organizationDesign/ui';
import { Button, Card, CardHeader, CardTitle, Table, TBody, Td, Th, THead, Tr, actionLinkClassName, badgeClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppActionMenu from '@/Components/ui/AppActionMenu';

type Named = { id: string; code: string; name_en: string; name_am: string | null };
type Level = Named & { rank: number };
type Profile = {
    id: string; version_no: number; lineage_key: string; status: string; purpose_en: string | null; purpose_am: string | null; reports_to_position_id: string | null;
    working_conditions_en: string | null; working_conditions_am: string | null; authorities_en: string | null; authorities_am: string | null; relationships_en: string | null; relationships_am: string | null;
    effective_from: string | null; effective_to: string | null; reports_to: { id: string; job_position_code: string; title_en: string; title_am: string | null } | null; created_by: string | null; approved_by: string | null;
    can: { update: boolean; publish: boolean; version: boolean };
};
type WorkLink = { id: string; object_type: WorkObjectType; object_id: string; is_primary: boolean; object: WorkObject | null };
type CompetencyRow = { id: string; competency_id: string; minimum_level_id: string; is_mandatory: boolean; source: 'manual' | 'derived'; competency: Named | null; level: Level | null };
type QualificationRow = { id: string; education_level_id: string; field_of_study: string | null; certification: string | null; license: string | null; experience_years: number; is_mandatory: boolean; source: 'manual' | 'derived'; education_level: Level | null };
type Jd = { id: string; reference_number: string | null; version_no: number; status: string; effective_from: string | null; effective_to: string | null; prepared_by: string | null; published_by: string | null };
type Props = {
    position: { id: string; job_position_code: string; title_en: string; title_am: string | null; grade_level: string | null; organization: Named | null; organization_id: string; unit: Named | null; occupation: (Named & { isco_code: string | null }) | null };
    profile: Profile | null;
    profiles: Profile[];
    workLinks: WorkLink[];
    competencies: CompetencyRow[];
    qualifications: QualificationRow[];
    jobDescriptions: Jd[];
    history: { from: string | null; to: string; actor: string | null; reason: string | null; at: string }[];
    catalog: { organization_id: string; competencies: PositionRequirementCatalog['competencies']; levels: Level[]; educationLevels: Level[] } | null;
    can: { design: boolean; approveProfile: boolean; draftJobDescription: boolean };
};

export default function PositionDesignShow({ position, profile, profiles, workLinks, competencies, qualifications, jobDescriptions, history, catalog, can }: Props) {
    const { t, locale, tt } = useDesignText();
    const { confirm } = useConfirm();
    const [profileEdit, setProfileEdit] = useState<'new' | 'edit' | 'version' | null>(null);
    const [linksOpen, setLinksOpen] = useState(false);
    const [requirementsOpen, setRequirementsOpen] = useState(false);
    const [jdOpen, setJdOpen] = useState(false);

    const text = (en: string | null, am: string | null) => (locale === 'am' && am) || en || null;
    const detail = (label: string, value: React.ReactNode) => (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-0.5 whitespace-pre-wrap wrap-break-word text-sm text-(--app-foreground)">{value === null || value === undefined || value === '' ? '—' : value}</dd>
        </div>
    );
    const profileValues = (p: Profile, version: boolean): ProfileFormValues => ({
        purpose_en: p.purpose_en ?? '', purpose_am: p.purpose_am ?? '', reports_to_position_id: p.reports_to_position_id ?? '', working_conditions_en: p.working_conditions_en ?? '', working_conditions_am: p.working_conditions_am ?? '',
        authorities_en: p.authorities_en ?? '', authorities_am: p.authorities_am ?? '', relationships_en: p.relationships_en ?? '', relationships_am: p.relationships_am ?? '',
        effective_from: version ? '' : p.effective_from ?? '', effective_to: version ? '' : p.effective_to ?? '',
    });
    const published = profiles.filter((p) => p.status === 'published' || p.status === 'superseded').map<ProfileOption>((p) => ({ id: p.id, version_no: p.version_no, status: p.status, effective_from: p.effective_from }));
    const reportsToLabel = (p: Profile) => (p.reports_to ? `${p.reports_to.job_position_code} · ${titleOf(p.reports_to, locale)}` : '');

    async function publishProfile(p: Profile) {
        const { confirmed } = await confirm({ title: tt('positionDesign.publishProfile'), description: tt('positionDesign.publishProfileConfirm'), variant: 'warning' });
        if (confirmed) router.post(route('organization-design.position-profiles.publish', p.id), {}, { preserveScroll: true });
    }
    async function cancelProfile(p: Profile) {
        const { confirmed, reason } = await confirm({ title: tt('positionDesign.cancelProfile'), description: tt('positionDesign.cancelProfileConfirm'), variant: 'danger', requireReason: true });
        if (confirmed) router.post(route('organization-design.position-profiles.cancel', p.id), { reason }, { preserveScroll: true });
    }
    async function derive() {
        const { confirmed } = await confirm({ title: tt('positionDesign.derive'), description: tt('positionDesign.deriveConfirm'), variant: 'warning' });
        if (confirmed) router.post(route('organization-design.position-requirements.derive', position.id), {}, { preserveScroll: true });
    }
    const sourcePill = (source: 'manual' | 'derived') => <span className={badgeClassName(source === 'derived' ? 'info' : 'neutral')}>{tt(`positionDesign.source.${source}`)}</span>;
    const objectLabel = (l: WorkLink) => l.object ? `${l.object.code} · ${nameOf(l.object, locale)}` : '—';

    return (
        <AuthenticatedLayout header={
            <PageHeader
                backHref={route('organization-design.position-design.index')}
                title={`${position.job_position_code} · ${titleOf(position, locale)}`}
                description={[nameOf(position.organization, locale), position.unit ? `${position.unit.code} · ${nameOf(position.unit, locale)}` : null, position.grade_level].filter(Boolean).join(' · ')}
                actions={(
                    <div className="flex flex-wrap gap-2">
                        {can.design && !profiles.some((p) => p.status === 'draft') && !profile?.can.version && <Button variant="primary" onClick={() => setProfileEdit('new')}>{tt('positionDesign.draftProfile')}</Button>}
                        {can.draftJobDescription && <Button variant="primary" onClick={() => setJdOpen(true)}>{tt('positionDesign.draftJd')}</Button>}
                    </div>
                )}
            />
        }>
            <Head title={`${position.job_position_code} · ${titleOf(position, locale)}`} />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                            <div><CardTitle>{tt('positionDesign.profileTitle')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('positionDesign.profileHelp')}</p></div>
                            {profile && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusPill status={profile.status} />
                                    <span className={badgeClassName('neutral')}>v{profile.version_no}</span>
                                    {profile.can.update && <Button size="sm" variant="outline" onClick={() => setProfileEdit('edit')}>{tt('positionDesign.editProfile')}</Button>}
                                    {profile.can.update && <Button size="sm" variant="destructiveOutline" onClick={() => cancelProfile(profile)}>{tt('positionDesign.cancelProfile')}</Button>}
                                    {profile.can.publish && <Button size="sm" variant="primary" onClick={() => publishProfile(profile)}>{tt('positionDesign.publishProfile')}</Button>}
                                    {profile.can.version && !profiles.some((p) => p.status === 'draft') && <Button size="sm" variant="outline" onClick={() => setProfileEdit('version')}>{tt('positionDesign.newVersion')}</Button>}
                                </div>
                            )}
                        </CardHeader>
                        {!profile ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('positionDesign.noProfile')}</p> : (
                            <>
                                <dl className="grid gap-4 p-5 sm:grid-cols-2">
                                    {detail(tt('positionDesign.purposeEn').replace(/ \(.*\)$/, ''), text(profile.purpose_en, profile.purpose_am))}
                                    {detail(tt('positionDesign.reportsTo'), profile.reports_to ? `${profile.reports_to.job_position_code} · ${titleOf(profile.reports_to, locale)}` : null)}
                                    {detail(tt('positionDesign.workingConditionsEn').replace(/ \(.*\)$/, ''), text(profile.working_conditions_en, profile.working_conditions_am))}
                                    {detail(tt('positionDesign.authoritiesEn').replace(/ \(.*\)$/, ''), text(profile.authorities_en, profile.authorities_am))}
                                    {detail(tt('positionDesign.relationshipsEn').replace(/ \(.*\)$/, ''), text(profile.relationships_en, profile.relationships_am))}
                                    {detail(tt('fields.effectiveFrom'), <><LocalizedDateDisplay value={profile.effective_from} />{profile.effective_to && <> – <LocalizedDateDisplay value={profile.effective_to} /></>}</>)}
                                    {detail(tt('positionDesign.draftedBy'), profile.created_by)}
                                    {detail(tt('positionDesign.publishedBy'), profile.approved_by)}
                                </dl>
                                {profile.status === 'draft' && can.approveProfile && !profile.can.publish && <p className="border-t border-(--app-border) px-5 py-3 text-xs text-(--app-muted-foreground)">{tt('positionDesign.publishByOther')}</p>}
                                {profiles.length > 1 && (
                                    <div className="border-t border-(--app-border) px-5 py-3">
                                        <p className="text-xs font-medium text-(--app-muted-foreground)">{tt('positionDesign.versions')}</p>
                                        <ul className="mt-1 flex flex-wrap gap-2 text-xs">
                                            {profiles.map((p) => (
                                                <li key={p.id} className="flex items-center gap-1">
                                                    <span className={badgeClassName('neutral')}>v{p.version_no}</span><StatusPill status={p.status} />
                                                    {p.status === 'draft' && p.can.update && p.id !== profile.id && <button type="button" className={actionLinkClassName({ size: 'sm' })} onClick={() => setProfileEdit('edit')}>{t('common.edit')}</button>}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </>
                        )}
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                            <div><CardTitle>{tt('positionDesign.workLinks')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('positionDesign.workLinksHelp')}</p></div>
                            {can.design && catalog && <Button size="sm" variant="outline" onClick={() => setLinksOpen(true)}>{tt('positionDesign.editWorkLinks')}</Button>}
                        </CardHeader>
                        {workLinks.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('positionDesign.noWorkLinks')}</p> : (
                            <Table>
                                <THead><tr><Th>{tt('fields.objectType')}</Th><Th>{tt('fields.object')}</Th><Th>{tt('positionDesign.primary')}</Th></tr></THead>
                                <TBody>
                                    {workLinks.map((l) => (
                                        <Tr key={l.id}>
                                            <Td muted>{tt(`positionDesign.objectTypes.${l.object_type}`)}</Td>
                                            <Td>{objectLabel(l)}</Td>
                                            <Td>{l.is_primary && <span className={badgeClassName('success')}>{tt('positionDesign.primary')}</span>}</Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                            <div><CardTitle>{tt('positionDesign.requirements')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('positionDesign.requirementsHelp')}</p></div>
                            {can.design && catalog && (
                                <div className="flex gap-2">
                                    <Button size="sm" variant="outline" onClick={derive} disabled={workLinks.length === 0}>{tt('positionDesign.derive')}</Button>
                                    <Button size="sm" variant="outline" onClick={() => setRequirementsOpen(true)}>{tt('positionDesign.editRequirements')}</Button>
                                </div>
                            )}
                        </CardHeader>
                        <div className="divide-y divide-(--app-border)">
                            <div>
                                <p className="px-5 pt-4 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{tt('positionDesign.competencies')}</p>
                                {competencies.length === 0 ? <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{tt('positionDesign.noCompetencies')}</p> : (
                                    <Table>
                                        <THead><tr><Th>{tt('fields.competency')}</Th><Th>{tt('fields.minimumLevel')}</Th><Th>{tt('positionDesign.mandatory')}</Th><Th>{tt('fields.kind')}</Th></tr></THead>
                                        <TBody>
                                            {competencies.map((r) => (
                                                <Tr key={r.id}>
                                                    <Td>{r.competency ? `${r.competency.code} · ${nameOf(r.competency, locale)}` : '—'}</Td>
                                                    <Td muted>{r.level ? `${r.level.rank} · ${nameOf(r.level, locale)}` : '—'}</Td>
                                                    <Td muted>{r.is_mandatory ? tt('positionDesign.mandatory') : tt('positionDesign.optional')}</Td>
                                                    <Td>{sourcePill(r.source)}</Td>
                                                </Tr>
                                            ))}
                                        </TBody>
                                    </Table>
                                )}
                            </div>
                            <div>
                                <p className="px-5 pt-4 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{tt('positionDesign.qualifications')}</p>
                                {qualifications.length === 0 ? <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">{tt('positionDesign.noQualifications')}</p> : (
                                    <Table>
                                        <THead><tr><Th>{tt('fields.educationLevel')}</Th><Th>{tt('fields.fieldOfStudy')}</Th><Th>{tt('fields.certification')}</Th><Th>{tt('fields.license')}</Th><Th align="right">{tt('fields.experienceYears')}</Th><Th>{tt('fields.kind')}</Th></tr></THead>
                                        <TBody>
                                            {qualifications.map((r) => (
                                                <Tr key={r.id}>
                                                    <Td>{nameOf(r.education_level, locale) || '—'}</Td>
                                                    <Td muted>{r.field_of_study ?? '—'}</Td>
                                                    <Td muted>{r.certification ?? '—'}</Td>
                                                    <Td muted>{r.license ?? '—'}</Td>
                                                    <Td align="right">{r.experience_years}</Td>
                                                    <Td>{sourcePill(r.source)}</Td>
                                                </Tr>
                                            ))}
                                        </TBody>
                                    </Table>
                                )}
                            </div>
                        </div>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                            <div><CardTitle>{tt('positionDesign.jobDescriptions')}</CardTitle><p className="mt-0.5 text-xs text-(--app-muted-foreground)">{tt('positionDesign.jobDescriptionsHelp')}</p></div>
                        </CardHeader>
                        {jobDescriptions.length === 0 ? <p className="px-5 py-6 text-center text-sm text-(--app-muted-foreground)">{tt('positionDesign.noJobDescriptions')}</p> : (
                            <Table>
                                <THead><tr><Th>{tt('fields.reference')}</Th><Th>{tt('positionDesign.version')}</Th><Th>{tt('fields.effectiveFrom')}</Th><Th>{tt('positionDesign.jd.preparedBy')}</Th><Th>{tt('fields.status')}</Th><Th align="right">{t('common.actions')}</Th></tr></THead>
                                <TBody>
                                    {jobDescriptions.map((jd) => (
                                        <Tr key={jd.id}>
                                            <Td className="font-mono text-xs">{jd.reference_number ?? '—'}</Td>
                                            <Td>v{jd.version_no}</Td>
                                            <Td muted className="whitespace-nowrap"><LocalizedDateDisplay value={jd.effective_from} />{jd.effective_to && <> – <LocalizedDateDisplay value={jd.effective_to} /></>}</Td>
                                            <Td muted>{jd.prepared_by ?? '—'}</Td>
                                            <Td><StatusPill status={jd.status} /></Td>
                                            <Td align="right">
                                                <div className="flex justify-end">
                                                    <AppActionMenu
                                                        items={[
                                                            { label: t('common.view'), href: route('organization-design.job-descriptions.show', jd.id) },
                                                        ]}
                                                    />
                                                </div>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader><CardTitle>{tt('positionDesign.history')}</CardTitle></CardHeader>
                        {history.length === 0 ? <p className="px-5 py-4 text-sm text-(--app-muted-foreground)">—</p> : (
                            <ul className="divide-y divide-(--app-border)">
                                {history.map((h, i) => (
                                    <li key={i} className="px-5 py-3 text-sm">
                                        <p className="font-medium text-(--app-foreground)">{h.from ? `${tt(`status.${h.from}`)} → ` : ''}{tt(`status.${h.to}`)}</p>
                                        <p className="text-xs text-(--app-muted-foreground)">{h.actor ?? '—'} · <LocalizedDateDisplay value={h.at} withTime /></p>
                                        {h.reason && <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{h.reason}</p>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            {catalog && profileEdit === 'new' && <ProfileForm show onClose={() => setProfileEdit(null)} title={tt('positionDesign.draftProfile')} initial={emptyProfile()} url={route('organization-design.position-profiles.store', position.id)} method="post" organizationId={position.organization_id} />}
            {catalog && profileEdit === 'edit' && profile && <ProfileForm show onClose={() => setProfileEdit(null)} title={tt('positionDesign.editProfile')} initial={profileValues(profiles.find((p) => p.status === 'draft') ?? profile, false)} url={route('organization-design.position-profiles.update', (profiles.find((p) => p.status === 'draft') ?? profile).id)} method="put" organizationId={position.organization_id} reportsToLabel={reportsToLabel(profiles.find((p) => p.status === 'draft') ?? profile)} />}
            {catalog && profileEdit === 'version' && profile && <ProfileForm show onClose={() => setProfileEdit(null)} title={tt('positionDesign.newVersion')} initial={profileValues(profile, true)} url={route('organization-design.position-profiles.versions.store', profile.id)} method="post" organizationId={position.organization_id} reportsToLabel={reportsToLabel(profile)} />}
            {catalog && linksOpen && <WorkLinksForm positionId={position.id} organizationId={position.organization_id} current={workLinks} onClose={() => setLinksOpen(false)} />}
            {catalog && requirementsOpen && (
                <PositionRequirementsForm
                    positionId={position.id}
                    competencies={competencies.filter((r) => r.source === 'manual').map((r) => ({ competency_id: r.competency_id, minimum_level_id: r.minimum_level_id, is_mandatory: r.is_mandatory }))}
                    qualifications={qualifications.filter((r) => r.source === 'manual').map((r) => ({ education_level_id: r.education_level_id, field_of_study: r.field_of_study ?? '', certification: r.certification ?? '', license: r.license ?? '', experience_years: r.experience_years, is_mandatory: r.is_mandatory }))}
                    catalog={{ competencies: catalog.competencies, levels: catalog.levels, educationLevels: catalog.educationLevels }}
                    onClose={() => setRequirementsOpen(false)}
                />
            )}
            {can.draftJobDescription && <JobDescriptionForm show={jdOpen} onClose={() => setJdOpen(false)} title={tt('positionDesign.draftJd')} url={route('organization-design.job-descriptions.store', position.id)} profiles={published} />}
        </AuthenticatedLayout>
    );
}
