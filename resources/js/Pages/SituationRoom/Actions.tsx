import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { fmt, useDateLabel, useNamer, type Name } from '@/Components/SituationRoom/communityVoice/shared';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Card, EmptyState, FieldError, FilterField, FilterPanel, FormLabel, Input, SearchableSelect, Select, StatusBadge, Textarea, buttonClassName, type SearchableOption } from '@euisis/ui';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Owner = { user: { id: number; name: string } | null; unit: ({ id: string } & Name) | null };
type ActionEvent = { id: string; event: string; from: string | null; to: string | null; note: string | null; actor: string | null; occurred_at: string | null };
type ActionRow = {
    id: string;
    organization: ({ id: string; code: string | null } & Name) | null;
    source_type: string | null;
    source_reference: string | null;
    title: string;
    action_text: string | null;
    owner_user: Owner['user'];
    owner_unit: Owner['unit'];
    due_date: string | null;
    priority: string;
    status: string;
    created_by: string | null;
    created_at: string | null;
    closed_at: string | null;
    events: ActionEvent[];
};
type Paginated<T> = { data: T[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null; total: number };
type Props = {
    scope: { node: ({ id: string; code: string | null } & Name) | null; breadcrumb: ({ id: string } & Name)[] };
    filters: { status: string | null };
    actions: Paginated<ActionRow>;
    options: { statuses: string[]; priorities: string[]; sourceTypes: string[] };
    can: { manage: boolean };
};

const ownerValue = (row: { owner_user: Owner['user']; owner_unit: Owner['unit'] }) => (row.owner_user ? `user:${row.owner_user.id}` : row.owner_unit ? `unit:${row.owner_unit.id}` : '');
const splitOwner = (value: string) => ({
    owner_user_id: value.startsWith('user:') ? Number(value.slice(5)) : null,
    owner_organization_unit_id: value.startsWith('unit:') ? value.slice(5) : null,
});

function useOwnerSearch(organizationId: string | null) {
    const name = useNamer();
    return async (query: string, signal: AbortSignal): Promise<SearchableOption[]> => {
        if (!organizationId) return [];
        const response = await fetch(route('situation-room.actions.owners', { organization: organizationId, q: query }), { signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) return [];
        const body = (await response.json()) as { users: { id: number; name: string }[]; units: ({ id: string } & Name)[] };
        return [
            ...body.users.map((user) => ({ value: `user:${user.id}`, label: user.name })),
            ...body.units.map((unit) => ({ value: `unit:${unit.id}`, label: name(unit) })),
        ];
    };
}

/**
 * The Action Register (docs/executive-situation-room.md): management
 * follow-ups per institution. Recording an action never changes who handles
 * a complaint.
 */
export default function SituationRoomActions({ scope, filters, actions, options, can }: Props) {
    const { t } = useLocale();
    const name = useNamer();
    const params = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
    const [creating, setCreating] = useState(params.get('new') === '1');
    const backQuery = scope.node ? { organization: scope.node.id } : {};
    const [statusDraft, setStatusDraft] = useState(filters.status ?? '');
    const applyStatus = (status: string) => {
        setStatusDraft(status);
        router.get(route('situation-room.actions.index'), { ...backQuery, ...(status ? { status } : {}) }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={t('situationRoom.actionsTitle')}
                description={t('situationRoom.actionsIntro')}
                backHref={scope.node ? route('situation-room.organization', { organization: scope.node.id }) : route('situation-room.index')}
                actions={can.manage && !creating ? <Button variant="primary" onClick={() => setCreating(true)}>{t('situationRoom.newAction')}</Button> : undefined}
            />
        )}>
            <Head title={t('situationRoom.actionsTitle')} />

            <div className="space-y-5">
                <h2 className="text-xl font-semibold">{scope.node ? name(scope.node) : t('situationRoom.cityWide')}</h2>

                <FilterPanel onSubmit={() => applyStatus(statusDraft)} onReset={() => applyStatus('')} hasActiveFilters={Boolean(filters.status)}>
                    <FilterField label={t('situationRoom.actionStatusLabel')}>
                        <Select value={statusDraft} onChange={(e) => setStatusDraft(e.target.value)}>
                            <option value="">{t('situationRoom.showOpenActions')}</option>
                            {options.statuses.map((status) => <option key={status} value={status}>{t(`situationRoom.action_status_${status}`)}</option>)}
                        </Select>
                    </FilterField>
                </FilterPanel>

                <Alert tone="info">{t('situationRoom.actionsWorkflowNote')}</Alert>

                {creating && can.manage && (
                    <CreateAction
                        organization={scope.node}
                        options={options}
                        sourceType={params.get('source_type')}
                        sourceReference={params.get('source_reference')}
                        onDone={() => setCreating(false)}
                    />
                )}

                {actions.data.length === 0 ? <Card><EmptyState title={t('situationRoom.actionsEmpty')} /></Card> : (
                    <ul className="space-y-3">
                        {actions.data.map((action) => <li key={action.id}><ActionCard action={action} options={options} canManage={can.manage} /></li>)}
                    </ul>
                )}

                {actions.last_page > 1 && (
                    <nav className="flex items-center justify-between gap-3 text-sm" aria-label={fmt(t('situationRoom.pageOf'), { page: actions.current_page, pages: actions.last_page })}>
                        {actions.prev_page_url ? <Link href={actions.prev_page_url} preserveScroll className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('situationRoom.previousPage')}</Link> : <span />}
                        <span>{fmt(t('situationRoom.pageOf'), { page: actions.current_page, pages: actions.last_page })}</span>
                        {actions.next_page_url ? <Link href={actions.next_page_url} preserveScroll className={buttonClassName({ variant: 'outline', size: 'sm' })}>{t('situationRoom.nextPage')}</Link> : <span />}
                    </nav>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function CreateAction({ organization, options, sourceType, sourceReference, onDone }: {
    organization: Props['scope']['node'];
    options: Props['options'];
    sourceType: string | null;
    sourceReference: string | null;
    onDone: () => void;
}) {
    const { t } = useLocale();
    const name = useNamer();
    const [owner, setOwner] = useState('');
    const form = useForm({
        organization_id: organization?.id ?? '',
        source_type: sourceType && options.sourceTypes.includes(sourceType) ? sourceType : (organization ? 'organization' : ''),
        source_reference: sourceReference ?? '',
        title: '',
        action_text: '',
        due_date: '',
        priority: 'normal',
        note: '',
    });
    const searchOwners = useOwnerSearch(form.data.organization_id || null);
    const orgOption: SearchableOption[] = organization ? [{ value: organization.id, label: name(organization) }] : [];

    async function searchInstitutions(query: string, signal: AbortSignal): Promise<SearchableOption[]> {
        const response = await fetch(route('situation-room.organization-options', { q: query }), { signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) return [];
        const rows = (await response.json()) as ({ id: string; code: string | null } & Name)[];
        return rows.map((row) => ({ value: row.id, label: name(row), description: row.code ?? undefined }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => Object.fromEntries(Object.entries({ ...data, ...splitOwner(owner) }).filter(([, value]) => value !== '' && value !== null)));
        form.post(route('situation-room.actions.store'), { preserveScroll: true, onSuccess: () => { form.reset(); setOwner(''); onDone(); } });
    }

    return (
        <Card className="p-5">
            <form onSubmit={submit} className="grid gap-4 md:grid-cols-2">
                <div className="md:col-span-2">
                    <FormLabel htmlFor="sra-org">{t('situationRoom.institutionLabel')}</FormLabel>
                    <SearchableSelect id="sra-org" label={t('situationRoom.institutionLabel')} value={form.data.organization_id} options={organization ? orgOption : undefined} loadOptions={organization ? undefined : searchInstitutions} onChange={(value) => { form.setData('organization_id', value); setOwner(''); }} placeholder={t('situationRoom.searchInstitutions')} clearable={!organization} disabled={!!organization} />
                    <FieldError>{form.errors.organization_id}</FieldError>
                </div>
                <div className="md:col-span-2">
                    <FormLabel htmlFor="sra-title">{t('situationRoom.actionTitleLabel')}</FormLabel>
                    <Input id="sra-title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} maxLength={200} required />
                    <FieldError>{form.errors.title}</FieldError>
                </div>
                <RichTextEditor
                    id="sra-text"
                    className="md:col-span-2"
                    label={t('situationRoom.actionTextLabel')}
                    value={form.data.action_text}
                    onChange={(html) => form.setData('action_text', html)}
                    error={form.errors.action_text}
                    preset="basic"
                    maxLength={5000}
                />
                <div>
                    <FormLabel htmlFor="sra-owner">{t('situationRoom.ownerLabel')}</FormLabel>
                    <SearchableSelect id="sra-owner" label={t('situationRoom.ownerLabel')} value={owner} loadOptions={searchOwners} onChange={(value) => setOwner(value)} placeholder={t('situationRoom.searchOwner')} disabled={!form.data.organization_id} />
                    <FieldError>{ownerError(form.errors)}</FieldError>
                </div>
                <div>
                    <FormLabel htmlFor="sra-due">{t('situationRoom.dueDateLabel')}</FormLabel>
                    <LocalizedDatePicker id="sra-due" value={form.data.due_date} onChange={(value) => form.setData('due_date', value)} />
                    <FieldError>{form.errors.due_date}</FieldError>
                </div>
                <div>
                    <FormLabel htmlFor="sra-priority">{t('situationRoom.priorityLabel')}</FormLabel>
                    <Select id="sra-priority" value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                        {options.priorities.map((priority) => <option key={priority} value={priority}>{t(`situationRoom.priority_${priority}`)}</option>)}
                    </Select>
                </div>
                <div>
                    <FormLabel htmlFor="sra-source">{t('situationRoom.sourceLabel')}</FormLabel>
                    <Select id="sra-source" value={form.data.source_type} onChange={(e) => form.setData('source_type', e.target.value)}>
                        <option value="">—</option>
                        {options.sourceTypes.map((source) => <option key={source} value={source}>{t(`situationRoom.source_${source}`)}</option>)}
                    </Select>
                </div>
                <div className="flex gap-2 md:col-span-2">
                    <Button type="submit" variant="primary" loading={form.processing}>{t('situationRoom.save')}</Button>
                    <Button type="button" variant="ghost" onClick={onDone}>{t('situationRoom.cancel')}</Button>
                </div>
            </form>
        </Card>
    );
}

function ActionCard({ action, options, canManage }: { action: ActionRow; options: Props['options']; canManage: boolean }) {
    const { t } = useLocale();
    const name = useNamer();
    const { date, dateTime } = useDateLabel();
    const [editing, setEditing] = useState(false);
    const [owner, setOwner] = useState(ownerValue(action));
    const searchOwners = useOwnerSearch(action.organization?.id ?? null);
    const form = useForm({ status: action.status, due_date: action.due_date ?? '', priority: action.priority, note: '' });
    const closed = action.status === 'done' || action.status === 'cancelled';
    const pastDue = !closed && action.due_date !== null && action.due_date < new Date().toISOString().slice(0, 10);
    const ownerName = action.owner_user?.name ?? (action.owner_unit ? name(action.owner_unit) : t('situationRoom.noOwner'));
    const ownerOptions: SearchableOption[] = owner ? [{ value: owner, label: ownerName }] : [];

    function submit(event: FormEvent) {
        event.preventDefault();
        const ownerChanged = owner !== ownerValue(action);
        form.transform((data) => ({ ...data, due_date: data.due_date || null, ...(ownerChanged ? splitOwner(owner) : {}) }));
        form.patch(route('situation-room.actions.update', action.id), { preserveScroll: true, onSuccess: () => { form.setData('note', ''); setEditing(false); } });
    }

    return (
        <Card className="p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge tone={action.status === 'done' ? 'success' : action.status === 'cancelled' ? 'neutral' : 'info'}>{t(`situationRoom.action_status_${action.status}`)}</StatusBadge>
                        {action.priority === 'high' && <StatusBadge tone="warning">{t('situationRoom.priority_high')}</StatusBadge>}
                        {pastDue && <StatusBadge tone="danger">{t('situationRoom.pastDue')}</StatusBadge>}
                    </div>
                    <h3 className="mt-2 font-semibold">{action.title}</h3>
                    <p className="text-sm text-(--app-muted-foreground)">{name(action.organization)}{action.source_type && ` · ${t(`situationRoom.source_${action.source_type}`)}`}</p>
                    <RichTextViewer value={action.action_text} className="mt-2 text-sm" />
                </div>
                <dl className="grid gap-1 text-sm sm:text-end">
                    <div><dt className="inline text-(--app-muted-foreground)">{t('situationRoom.ownerLabel')}: </dt><dd className="inline">{ownerName}</dd></div>
                    <div><dt className="inline text-(--app-muted-foreground)">{t('situationRoom.dueDateLabel')}: </dt><dd className="inline">{date(action.due_date)}</dd></div>
                    {action.created_by && <div className="text-xs text-(--app-muted-foreground)">{fmt(t('situationRoom.createdBy'), { name: action.created_by })}</div>}
                </dl>
            </div>

            {canManage && !editing && <Button variant="outline" size="sm" className="mt-3" onClick={() => setEditing(true)}>{t('situationRoom.updateAction')}</Button>}
            {editing && (
                <form onSubmit={submit} className="mt-4 grid gap-3 border-t border-(--app-border) pt-4 md:grid-cols-4">
                    <div>
                        <FormLabel htmlFor={`sra-status-${action.id}`}>{t('situationRoom.actionStatusLabel')}</FormLabel>
                        <Select id={`sra-status-${action.id}`} value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            {options.statuses.map((status) => <option key={status} value={status}>{t(`situationRoom.action_status_${status}`)}</option>)}
                        </Select>
                    </div>
                    <div>
                        <FormLabel htmlFor={`sra-owner-${action.id}`}>{t('situationRoom.ownerLabel')}</FormLabel>
                        <SearchableSelect id={`sra-owner-${action.id}`} label={t('situationRoom.ownerLabel')} value={owner} options={owner === ownerValue(action) ? ownerOptions : undefined} loadOptions={searchOwners} onChange={(value) => setOwner(value)} placeholder={t('situationRoom.searchOwner')} />
                        <FieldError>{ownerError(form.errors)}</FieldError>
                    </div>
                    <div>
                        <FormLabel htmlFor={`sra-due-${action.id}`}>{t('situationRoom.dueDateLabel')}</FormLabel>
                        <LocalizedDatePicker id={`sra-due-${action.id}`} value={form.data.due_date} onChange={(value) => form.setData('due_date', value)} />
                    </div>
                    <div>
                        <FormLabel htmlFor={`sra-priority-${action.id}`}>{t('situationRoom.priorityLabel')}</FormLabel>
                        <Select id={`sra-priority-${action.id}`} value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                            {options.priorities.map((priority) => <option key={priority} value={priority}>{t(`situationRoom.priority_${priority}`)}</option>)}
                        </Select>
                    </div>
                    <div className="md:col-span-4">
                        <FormLabel htmlFor={`sra-note-${action.id}`}>{t('situationRoom.noteLabel')}</FormLabel>
                        <Textarea id={`sra-note-${action.id}`} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} rows={2} maxLength={2000} />
                    </div>
                    <div className="flex gap-2 md:col-span-4">
                        <Button type="submit" variant="primary" size="sm" loading={form.processing}>{t('situationRoom.save')}</Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(false)}>{t('situationRoom.cancel')}</Button>
                    </div>
                </form>
            )}

            {action.events.length > 0 && (
                <details className="mt-3 text-sm">
                    <summary className="cursor-pointer text-(--app-muted-foreground)">{t('situationRoom.historyLabel')} ({action.events.length})</summary>
                    <ol className="mt-2 space-y-1.5 border-s border-(--app-border) ps-3">
                        {action.events.map((event) => (
                            <li key={event.id}>
                                <span className="font-medium">{t(`situationRoom.event_${event.event}`)}</span>
                                {(event.from || event.to) && <span> · {event.event === 'status_changed' ? [event.from, event.to].map((s) => (s ? t(`situationRoom.action_status_${s}`) : '—')).join(' → ') : event.event === 'due_date_changed' ? [event.from, event.to].map((d) => date(d)).join(' → ') : [event.from, event.to].filter(Boolean).join(' → ')}</span>}
                                {event.note && <p className="whitespace-pre-line wrap-break-word text-(--app-muted-foreground)">{event.note}</p>}
                                <p className="text-xs text-(--app-muted-foreground)">{dateTime(event.occurred_at)}{event.actor && ` · ${event.actor}`}</p>
                            </li>
                        ))}
                    </ol>
                </details>
            )}
        </Card>
    );
}

/** The owner is sent as one of two fields; show whichever the server rejected. */
function ownerError(errors: Record<string, string | undefined>): string | undefined {
    return errors.owner_user_id ?? errors.owner_organization_unit_id;
}
