import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import { AppActionMenu, AppMetricCard, FilterField, FilterPanel } from '@/Components/ui';
import { AlertTriangle, CheckCircle, ChevronRight, PencilIcon, Plus, UserPlusIcon, XCircle } from '@/Components/Icons';
import { Building2 } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import { Button, FormField, Input, Select, Textarea, cx } from '@euisis/ui';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';

type CaseType = 'feedback' | 'suggestion' | 'complaint';
type Responsibility = 'head' | 'officer';

type Member = {
    id: string;
    employee_id: string;
    name: string | null;
    employee_number: string | null;
    position: string | null;
    placed_unit: string | null;
    responsibility: Responsibility;
    effective_from: string | null;
    effective_to: string | null;
    appointment_reference: string | null;
    placed: boolean;
    has_account: boolean;
    signature_url: string;
    signature_recorded_at: string | null;
};

type HandlingUnit = {
    id: string;
    case_type: CaseType;
    status: 'active' | 'inactive';
    description_en: string | null;
    description_am: string | null;
    organization_id: string;
    organization: string | null;
    unit: string | null;
    problems: string[];
    members: Member[];
    can_manage: boolean;
};

type Props = {
    units: HandlingUnit[];
    organizations: { id: string; name: string }[];
    filters: { search: string; organization_id: string; status: string };
    caseType: CaseType;
    responsibilities: Responsibility[];
    summary: { units: number; members: number; attention: number };
    can: { designate: boolean };
};

type Dialog =
    | { kind: 'designate' }
    | { kind: 'edit'; unit: HandlingUnit }
    | { kind: 'register'; unit: HandlingUnit }
    | { kind: 'editMember'; unit: HandlingUnit; member: Member }
    | { kind: 'endMember'; unit: HandlingUnit; member: Member };

/** Each module's own unit pages: /feedback-admin/units, /tip-off-admin/units, /complaint-admin/units (ADR 0034). */
const UNIT_ROUTES: Record<CaseType, string> = { feedback: 'service-feedback.units', suggestion: 'tip-offs.units', complaint: 'public-complaints.units' };
const unitRoute = (caseType: CaseType, action: string, params?: Record<string, string> | string): string => route(`${UNIT_ROUTES[caseType]}.${action}`, params as never) as string;

const AVATAR: Record<Responsibility, string> = {
    head: 'bg-primary text-white',
    officer: 'bg-(--app-surface-muted) text-(--app-foreground) ring-1 ring-(--app-border-strong)',
};
const SIGNATURE_ACCEPT = 'image/png,image/jpeg,image/webp';

function today(): string {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function initials(name: string | null): string {
    return (name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || '?';
}

/**
 * The Feedback, Tip-off and Complaint Unit boards (ADR 0034), each on its
 * own module's address. Same board as the grievance Teams and Directorate:
 * summary cards, one card per unit that expands, ⋮ menus, every action in a
 * popup. An institution's existing unit is designated — nothing is created —
 * and its own staff are given a responsibility and a signature.
 */
export default function HandlingUnitsIndex({ units, organizations, filters, caseType, responsibilities, summary, can }: Props) {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    const b = (key: string) => t(`serviceFeedback.handling.board.${key}`);
    const title = h(`byType.${caseType}.title`);
    const designateLabel = h(`byType.${caseType}.designate`);
    const [query, setQuery] = useState(filters);
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const [bulk, setBulk] = useState<{ open: boolean; n: number } | null>(null);
    const close = () => setDialog(null);
    const filtered = Object.values(filters).some(Boolean);
    const index = unitRoute(caseType, 'index');

    return <AuthenticatedLayout header={<PageHeader title={title} description={h(`byType.${caseType}.intro`)}
        actions={can.designate && <Button type="button" variant="primary" onClick={() => setDialog({ kind: 'designate' })}><Plus className="h-4 w-4" aria-hidden />{designateLabel}</Button>} />}>
        <Head title={title} />
        <div className="space-y-5 p-4 sm:p-6">
            <section aria-label={title} className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-3">
                <AppMetricCard label={b('units')} value={summary.units} detail={b('unitsHelp')} variant="primary" icon={<Building2 className="h-5 w-5" />} />
                <AppMetricCard label={b('members')} value={summary.members} detail={b('membersHelp')} variant="success" icon={<UserPlusIcon className="h-5 w-5" />} />
                <AppMetricCard label={b('attention')} value={summary.attention} detail={b('attentionHelp')} variant={summary.attention > 0 ? 'warning' : 'neutral'} icon={<AlertTriangle className="h-5 w-5" />} />
            </section>

            <ResponsibilityGuide />

            <FilterPanel onSubmit={() => router.get(index, Object.fromEntries(Object.entries(query).filter(([, v]) => v !== '')), { preserveState: true })} onReset={() => router.get(index)} hasActiveFilters={filtered}>
                <FilterField label={b('search')} htmlFor="hu-search" wide><Input id="hu-search" type="search" value={query.search} onChange={(e) => setQuery({ ...query, search: e.target.value })} /></FilterField>
                <FilterField label={t('serviceFeedback.filterOrganization')} htmlFor="hu-org">
                    <Select id="hu-org" value={query.organization_id} onChange={(e) => setQuery({ ...query, organization_id: e.target.value })}>
                        <option value="">{b('all')}</option>
                        {organizations.map((org) => <option key={org.id} value={org.id}>{org.name}</option>)}
                    </Select>
                </FilterField>
                <FilterField label={b('status')} htmlFor="hu-status">
                    <Select id="hu-status" value={query.status} onChange={(e) => setQuery({ ...query, status: e.target.value })}>
                        <option value="">{b('all')}</option>
                        <option value="active">{h('active')}</option>
                        <option value="inactive">{h('inactive')}</option>
                    </Select>
                </FilterField>
            </FilterPanel>

            {units.length === 0
                ? <div className="flex flex-col items-center rounded-card border border-dashed border-(--app-border-strong) bg-(--app-surface) px-6 py-14 text-center">
                    <span className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"><Building2 className="h-6 w-6" /></span>
                    <h2 className="mt-4 text-base font-semibold text-(--app-foreground)">{filtered ? b('noMatches') : h(`byType.${caseType}.none`)}</h2>
                    {!filtered && <p className="mt-1 max-w-md text-sm text-(--app-muted-foreground)">{h(`byType.${caseType}.noneHint`)}</p>}
                    {!filtered && can.designate && <Button type="button" variant="primary" className="mt-5" onClick={() => setDialog({ kind: 'designate' })}><Plus className="h-4 w-4" aria-hidden />{designateLabel}</Button>}
                </div>
                : <div className="space-y-4">
                    <div className="flex justify-end gap-2">
                        <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => setBulk((prev) => ({ open: true, n: (prev?.n ?? 0) + 1 }))}>{b('expandAll')}</button>
                        <span className="text-(--app-muted-foreground)" aria-hidden>·</span>
                        <button type="button" className="text-sm font-medium text-primary hover:underline" onClick={() => setBulk((prev) => ({ open: false, n: (prev?.n ?? 0) + 1 }))}>{b('collapseAll')}</button>
                    </div>
                    {units.map((unit) => <UnitCard key={unit.id} unit={unit} onOpen={setDialog} bulk={bulk} />)}
                </div>}
        </div>

        <Modal show={dialog !== null} onClose={close} maxWidth="2xl">
            {dialog?.kind === 'designate' && <DialogShell title={designateLabel} help={h('designateHint')}><DesignateForm caseType={caseType} organizations={organizations} onDone={close} /></DialogShell>}
            {dialog?.kind === 'edit' && <DialogShell title={`${b('editDesignation')}: ${dialog.unit.unit ?? ''}`}><EditUnitForm unit={dialog.unit} onDone={close} /></DialogShell>}
            {dialog?.kind === 'register' && <DialogShell title={`${h('addMember')}: ${dialog.unit.unit ?? ''}`} help={h('memberHint')}><MemberForm unit={dialog.unit} member={null} responsibilities={responsibilities} onDone={close} /></DialogShell>}
            {dialog?.kind === 'editMember' && <DialogShell title={`${h('editMember')}: ${dialog.member.name ?? ''}`}><MemberForm unit={dialog.unit} member={dialog.member} responsibilities={responsibilities} onDone={close} /></DialogShell>}
            {dialog?.kind === 'endMember' && <DialogShell title={`${h('endTerm')}: ${dialog.member.name ?? ''}`} help={b('endHelp')}><EndForm unit={dialog.unit} member={dialog.member} onDone={close} /></DialogShell>}
        </Modal>
    </AuthenticatedLayout>;
}

function DialogShell({ title, help, children }: { title: string; help?: string; children: ReactNode }) {
    return <div className="p-6">
        <h2 className="text-lg font-semibold text-(--app-foreground)">{title}</h2>
        {help && <p className="mt-1 text-sm text-(--app-muted-foreground)">{help}</p>}
        <div className="mt-5">{children}</div>
    </div>;
}

function FormActions({ processing, onCancel }: { processing: boolean; onCancel: () => void }) {
    const { t } = useLocale();
    return <div className="flex items-center gap-3 pt-2">
        <Button type="submit" variant="primary" loading={processing}>{t('common.save')}</Button>
        <Button type="button" variant="outline" onClick={onCancel}>{t('common.cancel')}</Button>
    </div>;
}

/** What each responsibility means, folded by default. */
function ResponsibilityGuide() {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    return <details className="group rounded-card border border-(--app-border) bg-(--app-surface) text-sm">
        <summary className="flex cursor-pointer list-none items-center gap-2 px-5 py-3 font-medium text-(--app-foreground) [&::-webkit-details-marker]:hidden">
            <ChevronRight className="h-4 w-4 text-(--app-muted-foreground) transition-transform group-open:rotate-90" aria-hidden />{h('board.responsibilities')}
        </summary>
        <dl className="grid gap-4 border-t border-(--app-border) px-5 py-4 md:grid-cols-2">
            {(['head', 'officer'] as const).map((r) => <div key={r}>
                <dt className="flex items-center gap-2 font-semibold text-(--app-foreground)"><span className={`h-2.5 w-2.5 rounded-full ${AVATAR[r]}`} aria-hidden />{h(`responsibility.${r}`)}</dt>
                <dd className="mt-1 text-(--app-muted-foreground)">{h(`board.${r}Duty`)}</dd>
            </div>)}
        </dl>
    </details>;
}

function Pill({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'info' | 'success' }) {
    const tones = { neutral: 'bg-(--app-surface-muted) text-(--app-foreground)', info: 'bg-primary/10 text-primary', success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' };
    return <span className={cx('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', tones[tone])}>{children}</span>;
}

function UnitCard({ unit, onOpen, bulk }: { unit: HandlingUnit; onOpen: (dialog: Dialog) => void; bulk: { open: boolean; n: number } | null }) {
    const { t, locale } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    const b = (key: string) => t(`serviceFeedback.handling.board.${key}`);
    const [open, setOpen] = useState(false);
    useEffect(() => { if (bulk) { setOpen(bulk.open); } }, [bulk]);
    const status = useForm({ status: unit.status === 'active' ? 'inactive' : 'active' });
    const head = unit.members.find((member) => member.responsibility === 'head');
    const ready = unit.status === 'active' && unit.problems.length === 0;
    const title = `${unit.unit ?? '—'} · ${h(`byType.${unit.case_type}.title`)}`;
    const bodyId = `unit-${unit.id}-body`;
    const description = locale === 'am' ? unit.description_am || unit.description_en : unit.description_en || unit.description_am;

    const marker = <span className={`inline-flex items-center gap-1 text-xs font-medium ${ready ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'}`}>
        {ready ? <CheckCircle className="h-3.5 w-3.5" aria-hidden /> : <AlertTriangle className="h-3.5 w-3.5" aria-hidden />}{ready ? b('ready') : b('needsAttention')}
    </span>;

    if (!open) {
        return <article className="overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface) shadow-xs">
            <h2 className="m-0">
                <button type="button" aria-expanded={false} aria-controls={bodyId} onClick={() => setOpen(true)}
                    className="flex w-full items-center gap-3 px-5 py-4 text-left hover:bg-(--app-surface-muted) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden><Building2 className="h-5 w-5" /></span>
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-base font-medium text-(--app-foreground)">{title}</span>
                        <span className="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-(--app-muted-foreground)">
                            <span>{unit.organization ?? '—'}</span>
                            <span>{b('membersHeading')}: {unit.members.length}</span>
                            {head && <span>{h('responsibility.head')}: {head.name}</span>}
                            {marker}
                        </span>
                    </span>
                    <span className="hidden text-xs font-medium text-primary sm:inline">{b('expand')}</span>
                    <ChevronRight className="h-5 w-5 shrink-0 text-(--app-muted-foreground)" aria-hidden />
                </button>
            </h2>
        </article>;
    }

    return <article id={bodyId} className="relative overflow-hidden rounded-card border border-(--app-border) bg-(--app-surface) shadow-xs" aria-labelledby={`unit-${unit.id}`}>
        <header className="flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:justify-between">
            <div className="flex min-w-0 gap-4 pr-20 lg:pr-0">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden><Building2 className="h-6 w-6" /></span>
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 id={`unit-${unit.id}`} className="text-base font-semibold text-(--app-foreground)">{unit.unit ?? '—'}</h2>
                        <Pill tone="info">{h(`byType.${unit.case_type}.title`)}</Pill>
                        <Pill tone={unit.status === 'active' ? 'success' : 'neutral'}>{h(unit.status === 'active' ? 'active' : 'inactive')}</Pill>
                        {marker}
                    </div>
                    <p className="mt-1 text-sm text-(--app-muted-foreground)">{unit.organization ?? '—'}</p>
                    {description && <p className="mt-1 text-sm text-(--app-foreground)">{description}</p>}
                </div>
            </div>
            <div className="absolute top-4 right-4 flex items-center gap-1 lg:static lg:shrink-0">
                <button type="button" aria-expanded={true} aria-controls={bodyId} onClick={() => setOpen(false)} aria-label={`${b('collapse')}: ${title}`} title={b('collapse')}
                    className="rounded-md p-1.5 text-(--app-muted-foreground) hover:bg-(--app-surface-muted) hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                    <ChevronRight className="h-5 w-5 -rotate-90" aria-hidden />
                </button>
                <AppActionMenu label={`${t('common.actions')}: ${title}`} items={[
                    { label: h('addMember'), icon: <UserPlusIcon className="h-4 w-4" />, onClick: () => onOpen({ kind: 'register', unit }), show: unit.can_manage && unit.status === 'active' },
                    { label: b('editDesignation'), icon: <PencilIcon className="h-4 w-4" />, onClick: () => onOpen({ kind: 'edit', unit }), show: unit.can_manage },
                    { label: unit.status === 'active' ? h('deactivate') : h('activate'), icon: unit.status === 'active' ? <XCircle className="h-4 w-4" /> : <CheckCircle className="h-4 w-4" />, variant: unit.status === 'active' ? 'danger' : 'default',
                        onClick: () => status.patch(unitRoute(unit.case_type, 'update', unit.id), { preserveScroll: true }), show: unit.can_manage, separatorBefore: true, disabled: status.processing },
                ]} />
            </div>
        </header>

        {unit.problems.length > 0 && <ul className="space-y-1 border-t border-amber-200 bg-amber-50/60 px-5 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200">
            {unit.problems.map((problem) => <li key={problem} className="flex items-start gap-2"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden /><span>{problem}</span></li>)}
        </ul>}

        <div className="border-t border-(--app-border) bg-(--app-surface-muted)/40 px-5 py-4">
            <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{b('membersHeading')} · {unit.members.length}</h3>
            {unit.members.length === 0
                ? <p className="text-sm text-(--app-muted-foreground)">{h('noMembers')}</p>
                : <ul className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">{unit.members.map((member) => {
                    const name = member.name ?? member.employee_number ?? '—';
                    return <li key={member.id} className="flex min-w-0 items-start gap-3 rounded-lg border border-(--app-border) bg-(--app-surface) p-3">
                        <span className={cx('flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold', AVATAR[member.responsibility])} aria-hidden>{initials(member.name)}</span>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2"><span className="truncate font-medium text-(--app-foreground)">{name}</span><Pill tone={member.responsibility === 'head' ? 'info' : 'neutral'}>{h(`responsibility.${member.responsibility}`)}</Pill></div>
                            {!member.placed && <p className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-red-700 dark:text-red-400"><AlertTriangle className="h-3.5 w-3.5" aria-hidden />{h('notPlaced')}</p>}
                            {!member.has_account && <p className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-400"><AlertTriangle className="h-3.5 w-3.5" aria-hidden />{h('noAccount')}</p>}
                            {member.position && <p className="mt-0.5 truncate text-sm text-(--app-foreground)">{member.position}</p>}
                            {member.placed_unit && <p className="mt-0.5 truncate text-xs text-(--app-muted-foreground)">{member.placed_unit}</p>}
                            {/* Every member is registered with a signature; the image opens in the edit popup. */}
                            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-(--app-muted-foreground)">
                                <span className="inline-flex items-center gap-1 font-medium text-emerald-700 dark:text-emerald-400"><CheckCircle className="h-3.5 w-3.5" aria-hidden />{b('signatureRegistered')}</span>
                                <LocalizedDateDisplay value={member.effective_from} />
                            </div>
                        </div>
                        <div className="shrink-0">
                            <AppActionMenu label={`${t('common.actions')}: ${name}`} items={[
                                { label: t('common.edit'), icon: <PencilIcon className="h-4 w-4" />, onClick: () => onOpen({ kind: 'editMember', unit, member }), show: unit.can_manage },
                                { label: h('endTerm'), icon: <XCircle className="h-4 w-4" />, onClick: () => onOpen({ kind: 'endMember', unit, member }), variant: 'danger', show: unit.can_manage, separatorBefore: true },
                            ]} />
                        </div>
                    </li>;
                })}</ul>}
        </div>
    </article>;
}

function DesignateForm({ caseType, organizations, onDone }: { caseType: CaseType; organizations: Props['organizations']; onDone: () => void }) {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    const form = useForm({ organization_id: organizations.length === 1 ? organizations[0].id : '', organization_unit_id: '', description_en: '', description_am: '' });
    const [units, setUnits] = useState<{ id: string; name: string }[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!form.data.organization_id) { setUnits([]); return; }
        const controller = new AbortController();
        setLoading(true);
        fetch(unitRoute(caseType, 'unit-options', { organization_id: form.data.organization_id }), { signal: controller.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { data: [] }))
            .then((body: { data: { id: string; name: string }[] }) => setUnits(body.data))
            .catch(() => undefined)
            .finally(() => setLoading(false));
        return () => controller.abort();
    }, [caseType, form.data.organization_id]);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.submit('post', unitRoute(caseType, 'store'), { preserveScroll: true, onSuccess: onDone });
    }

    return <form onSubmit={submit} className="grid gap-4">
        <FormField id="hu-designate-org" label={t('serviceFeedback.filterOrganization')} error={form.errors.organization_id} required>
            {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.organization_id} onChange={(e) => form.setData((d) => ({ ...d, organization_id: e.target.value, organization_unit_id: '' }))}>
                <option value="">{h('chooseOrganization')}</option>
                {organizations.map((org) => <option key={org.id} value={org.id}>{org.name}</option>)}
            </Select>}
        </FormField>
        <FormField id="hu-designate-unit" label={h('workUnit')} description={h('workUnitHint')} error={form.errors.organization_unit_id} required>
            {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.organization_unit_id} disabled={loading || !form.data.organization_id} onChange={(e) => form.setData('organization_unit_id', e.target.value)}>
                <option value="">{loading ? t('common.loading') : h('chooseUnit')}</option>
                {units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
            </Select>}
        </FormField>
        <FormField id="hu-designate-desc-am" label={h('descriptionAm')} error={form.errors.description_am}>
            {({ id }) => <Textarea id={id} rows={2} maxLength={2000} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}
        </FormField>
        <FormField id="hu-designate-desc-en" label={h('descriptionEn')} error={form.errors.description_en}>
            {({ id }) => <Textarea id={id} rows={2} maxLength={2000} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}
        </FormField>
        <FormActions processing={form.processing} onCancel={onDone} />
    </form>;
}

function EditUnitForm({ unit, onDone }: { unit: HandlingUnit; onDone: () => void }) {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    const form = useForm({ description_en: unit.description_en ?? '', description_am: unit.description_am ?? '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.submit('patch', unitRoute(unit.case_type, 'update', unit.id), { preserveScroll: true, onSuccess: onDone });
    }

    return <form onSubmit={submit} className="grid gap-4">
        <FormField id="hu-edit-desc-am" label={h('descriptionAm')} error={form.errors.description_am}>
            {({ id }) => <Textarea id={id} rows={2} maxLength={2000} value={form.data.description_am} onChange={(e) => form.setData('description_am', e.target.value)} />}
        </FormField>
        <FormField id="hu-edit-desc-en" label={h('descriptionEn')} error={form.errors.description_en}>
            {({ id }) => <Textarea id={id} rows={2} maxLength={2000} value={form.data.description_en} onChange={(e) => form.setData('description_en', e.target.value)} />}
        </FormField>
        <FormActions processing={form.processing} onCancel={onDone} />
    </form>;
}

function MemberForm({ unit, member, responsibilities, onDone }: { unit: HandlingUnit; member: Member | null; responsibilities: Responsibility[]; onDone: () => void }) {
    const { t } = useLocale();
    const h = (key: string) => t(`serviceFeedback.handling.${key}`);
    const hasHead = unit.members.some((m) => m.responsibility === 'head');
    const form = useForm<{ employee_id: string; responsibility: Responsibility; effective_from: string; effective_to: string; appointment_reference: string; signature: File | null }>({
        employee_id: member?.employee_id ?? '',
        responsibility: member?.responsibility ?? (hasHead ? 'officer' : 'head'),
        effective_from: member?.effective_from ?? today(),
        effective_to: member?.effective_to ?? '',
        appointment_reference: member?.appointment_reference ?? '',
        signature: null,
    });

    // The unit's own staff (and of the units beneath it) who are not yet members.
    const [staff, setStaff] = useState<{ id: string; name: string | null; employee_number: string | null; position: string | null; unit: string | null }[] | null>(null);
    useEffect(() => {
        if (member !== null) return;
        const controller = new AbortController();
        fetch(unitRoute(unit.case_type, 'staff', unit.id), { signal: controller.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { data: [] }))
            .then((body: { data: NonNullable<typeof staff> }) => setStaff(body.data))
            .catch(() => undefined);
        return () => controller.abort();
    }, [unit.case_type, unit.id, member]);

    function submit(event: FormEvent) {
        event.preventDefault();
        const url = member === null ? unitRoute(unit.case_type, 'members.store', unit.id) : unitRoute(unit.case_type, 'members.update', { unit: unit.id, member: member.id });
        form.submit('post', url, { forceFormData: true, preserveScroll: true, onSuccess: onDone });
    }

    return <form onSubmit={submit} className="grid gap-4">
        {member === null && <FormField id="hu-member-employee" label={h('employee')} description={staff !== null && staff.length === 0 ? h('noStaff') : undefined} error={form.errors.employee_id} required>
            {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.employee_id} disabled={staff === null || staff.length === 0} onChange={(e) => form.setData('employee_id', e.target.value)}>
                <option value="">{staff === null ? t('common.loading') : h('chooseEmployee')}</option>
                {(staff ?? []).map((row) => <option key={row.id} value={row.id}>{[row.name, row.employee_number, row.position, row.unit].filter(Boolean).join(' · ')}</option>)}
            </Select>}
        </FormField>}
        <FormField id="hu-member-responsibility" label={h('responsibilityLabel')} error={form.errors.responsibility} required>
            {({ id, describedBy, invalid }) => <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.responsibility} onChange={(e) => form.setData('responsibility', e.target.value as Responsibility)}>
                {responsibilities.map((r) => <option key={r} value={r}>{h(`responsibility.${r}`)}</option>)}
            </Select>}
        </FormField>
        {member === null && <div className="grid gap-4 sm:grid-cols-2">
            <FormField id="hu-member-from" label={h('from')} error={form.errors.effective_from} required>
                {({ id }) => <Input id={id} type="date" value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} />}
            </FormField>
            <FormField id="hu-member-to" label={h('to')} error={form.errors.effective_to}>
                {({ id }) => <Input id={id} type="date" value={form.data.effective_to} min={form.data.effective_from || undefined} onChange={(e) => form.setData('effective_to', e.target.value)} />}
            </FormField>
        </div>}
        <FormField id="hu-member-ref" label={h('appointmentReference')} error={form.errors.appointment_reference}>
            {({ id }) => <Input id={id} maxLength={255} value={form.data.appointment_reference} onChange={(e) => form.setData('appointment_reference', e.target.value)} />}
        </FormField>
        {member !== null && <figure>
            <img src={member.signature_url} alt={h('signatureOf').replace('{name}', member.name ?? '')} className="h-16 w-40 rounded-control border border-(--app-border) bg-white object-contain p-1" />
            <figcaption className="mt-1 text-xs text-(--app-muted-foreground)">{h('currentSignature')}</figcaption>
        </figure>}
        <FormField id="hu-member-signature" label={h('signature')} description={h(member === null ? 'signatureHint' : 'signatureReplaceHint')} error={form.errors.signature} required={member === null}>
            {({ id, describedBy, invalid }) => <input id={id} type="file" accept={SIGNATURE_ACCEPT} aria-describedby={describedBy} aria-invalid={invalid} required={member === null}
                className="block w-full text-sm file:mr-3 file:rounded-control file:border file:border-(--app-border) file:bg-(--app-surface-muted) file:px-3 file:py-1.5 file:text-sm"
                onChange={(e) => form.setData('signature', e.target.files?.[0] ?? null)} />}
        </FormField>
        <FormActions processing={form.processing} onCancel={onDone} />
    </form>;
}

function EndForm({ unit, member, onDone }: { unit: HandlingUnit; member: Member; onDone: () => void }) {
    const { t } = useLocale();
    const form = useForm({ reason: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.submit('post', unitRoute(unit.case_type, 'members.end', { unit: unit.id, member: member.id }), { preserveScroll: true, onSuccess: onDone });
    }

    return <form onSubmit={submit} className="grid gap-4">
        <FormField id="hu-end-reason" label={t('serviceFeedback.transitionReason')} error={form.errors.reason} required>
            {({ id }) => <Textarea id={id} rows={3} maxLength={1000} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />}
        </FormField>
        <FormActions processing={form.processing} onCancel={onDone} />
    </form>;
}
