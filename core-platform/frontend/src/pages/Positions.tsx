import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { api } from '../api';
import { useAuth } from '../auth';
import FormDialog, { type FieldDef, type FieldValue } from '../components/FormDialog';
import { Briefcase, ClipboardTextIcon, PenIcon } from '../components/NavIcons';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useLocale } from '../i18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout';
import { formatDate, localized } from '../lib/format';
import { useList } from '../lib/useList';
import { nameOptions, useLookups } from '../lib/useLookups';
import { Link, navigate } from '../router';
import { useSettings } from '../settings';
import type { OrgRef, Position } from '../types';
import { Alert, Button, Card, CardHeader, CardTitle, EmptyState, FilterField, FilterPanel, Input, Table, TBody, THead, Td, Th, Tr, actionLinkClassName, selectClassName } from '../ui';
import { ListFrame, useFilterForm } from './Lists';

type PositionDetail = {
    position: Position & { organization_id: string; organization_unit_id: string | null; grade_level: string | null; effective_from: string | null; organization: OrgRef | null; unit: OrgRef | null };
    capacity: number;
    holders: { id: string; employee_id: string; effective_from: string | null; employee: { id: string; employee_number: string; full_name: string } | null }[];
    establishments: { id: string; establishment_number: string; approved_slots: number; effective_from: string; effective_to: string | null; status: string; approval_reference: string | null }[];
};

/** The create / edit position dialog; the unit list follows the chosen organization. */
function PositionDialog({ open, position, onClose, onSaved }: {
    open: boolean;
    position: PositionDetail['position'] | null;
    onClose: () => void;
    onSaved: (id: string) => void;
}) {
    const { t, locale } = useLocale();
    const [organizationId, setOrganizationId] = useState<string | null>(position?.organization_id ?? null);
    const { lookups } = useLookups(organizationId);
    const fields: FieldDef[] = [
        { name: 'organization_id', label: t('platform.structure.organization'), type: 'select', required: true, options: nameOptions(locale, lookups.organizations) },
        { name: 'organization_unit_id', label: t('platform.positionsEdit.unit'), type: 'select', options: nameOptions(locale, lookups.units) },
        { name: 'job_position_code', label: t('platform.structure.code'), description: t('platform.structure.codeHint') },
        { name: 'grade_level', label: t('platform.positionsEdit.grade') },
        { name: 'title_en', label: t('platform.positionsEdit.titleEn'), required: true },
        { name: 'title_am', label: t('platform.positionsEdit.titleAm') },
        ...(position ? [{ name: 'is_active', label: t('platform.positionsEdit.inUse'), type: 'checkbox' as const }] : []),
    ];
    const initial: Record<string, FieldValue> = position
        ? { organization_id: position.organization_id, organization_unit_id: position.organization_unit_id, job_position_code: position.job_position_code, grade_level: position.grade_level, title_en: position.title_en, title_am: position.title_am, is_active: position.is_active }
        : { organization_id: null, organization_unit_id: null, job_position_code: null, grade_level: null, title_en: null, title_am: null };

    return (
        <FormDialog
            open={open}
            title={position ? t('platform.positionsEdit.editPosition') : t('platform.positionsEdit.newPosition')}
            fields={fields}
            initial={initial}
            submitLabel={t('platform.actions.save')}
            onClose={onClose}
            onValuesChange={(values) => setOrganizationId((values.organization_id as string | null) ?? null)}
            onSubmit={async (values) => {
                const response = await api<{ data: { id: string } }>(position ? `/v1/positions/${position.id}` : '/v1/positions', { method: position ? 'PUT' : 'POST', body: values });
                toast.success(t('platform.actions.saved'));
                onSaved(response.data.id);
            }}
        />
    );
}

export function PositionsPage() {
    const { t, locale } = useLocale();
    const canWrite = Boolean(useAuth().user?.can_change_master_data);
    const list = useList<Position>('/v1/positions');
    const { form, setForm, set } = useFilterForm({ search: '', occupancy: '' });
    const [creating, setCreating] = useState(false);
    const hasFilters = Boolean(list.filters.search || list.filters.occupancy);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.positions.title')} description={t('platform.positions.description')}
            actions={canWrite ? <Button variant="primary" icon={<Briefcase className="h-4 w-4" />} onClick={() => setCreating(true)}>{t('platform.positionsEdit.newPosition')}</Button> : undefined} />}>
            <div className="space-y-4">
                <FilterPanel label={t('platform.positions.title')} onSubmit={() => list.apply(form)} onReset={() => { setForm({ search: '', occupancy: '' }); list.apply({}); }} hasActiveFilters={hasFilters}>
                    <FilterField label={t('platform.filters.search')} htmlFor="position-search" wide>
                        <Input id="position-search" type="search" placeholder={t('platform.positions.searchPlaceholder')} value={form.search} onChange={set('search')} />
                    </FilterField>
                    <FilterField label={t('platform.positions.occupancy')} htmlFor="position-occupancy">
                        <select id="position-occupancy" className={selectClassName} value={form.occupancy} onChange={set('occupancy')}>
                            <option value="">{t('platform.positions.anyOccupancy')}</option>
                            <option value="vacant">{t('platform.positions.vacant')}</option>
                            <option value="held">{t('platform.positions.held')}</option>
                        </select>
                    </FilterField>
                </FilterPanel>
                <ListFrame {...list} onPage={list.setPage} hasFilters={hasFilters} icon={Briefcase} empty={t('platform.positions.empty')} noMatch={t('platform.positions.noMatch')}>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('core.assignments.position')}</Th>
                                <Th>{t('platform.positions.organization')}</Th>
                                <Th align="right">{t('platform.positions.holders')}</Th>
                                <Th>{t('common.status')}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {list.result?.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td>
                                        <Link href={`/positions/${row.id}`} className="font-medium text-(--app-foreground) hover:text-primary">{localized(locale, row.title_en, row.title_am)}</Link>
                                        <div className="font-mono text-xs text-(--app-muted-foreground)">{row.job_position_code}</div>
                                    </Td>
                                    <Td muted>{row.organization?.name_en ?? '—'}</Td>
                                    <Td numeric>{row.holders_count}</Td>
                                    <Td><StatusBadge status={row.is_active ? 'active' : 'inactive'} /></Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </ListFrame>
            </div>
            <PositionDialog open={creating} position={null} onClose={() => setCreating(false)} onSaved={(id) => { setCreating(false); navigate(`/positions/${id}`); }} />
        </AuthenticatedLayout>
    );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-1 text-sm text-(--app-foreground)">{children}</dd>
        </div>
    );
}

export function PositionDetailPage({ id }: { id: string }) {
    const { t, locale } = useLocale();
    const canWrite = Boolean(useAuth().user?.can_change_master_data);
    const { getNumber } = useSettings();
    const [detail, setDetail] = useState<PositionDetail | null>(null);
    const [error, setError] = useState(false);
    const [editing, setEditing] = useState(false);
    const [approving, setApproving] = useState(false);
    const [ending, setEnding] = useState<PositionDetail['establishments'][number] | null>(null);

    const load = useCallback(() => {
        api<{ data: PositionDetail }>(`/v1/positions/${id}`).then((response) => setDetail(response.data)).catch(() => setError(true));
    }, [id]);
    useEffect(load, [load]);

    const position = detail?.position;

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={position ? localized(locale, position.title_en, position.title_am) : t('platform.positions.title')}
                description={position?.job_position_code}
                backHref="/positions"
                actions={canWrite && position ? <Button variant="primary" icon={<PenIcon className="h-4 w-4" />} onClick={() => setEditing(true)}>{t('platform.actions.edit')}</Button> : undefined}
            />
        )}>
            {error && <Alert tone="danger">{t('platform.error')}</Alert>}
            {!detail && !error && <Card><EmptyState title={t('common.loading')} /></Card>}
            {detail && position && (
                <div className="space-y-6">
                    <div className="grid gap-6 lg:grid-cols-3">
                        <Card className="lg:col-span-2">
                            <CardHeader><CardTitle>{t('common.details')}</CardTitle><StatusBadge status={position.is_active ? 'active' : 'inactive'} /></CardHeader>
                            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                <Detail label={t('platform.structure.organization')}>
                                    {position.organization ? <Link href={`/organizations/${position.organization.id}`} className={actionLinkClassName({ size: 'sm' })}>{localized(locale, position.organization.name_en, position.organization.name_am)}</Link> : '—'}
                                </Detail>
                                <Detail label={t('platform.positionsEdit.unit')}>{position.unit ? localized(locale, position.unit.name_en, position.unit.name_am) : '—'}</Detail>
                                <Detail label={t('platform.positionsEdit.grade')}>{position.grade_level ?? '—'}</Detail>
                                <Detail label={t('platform.positionsEdit.seats')}><span className="tabular-nums">{detail.holders.length} / {detail.capacity}</span></Detail>
                                <Detail label={t('platform.structure.effectiveFrom')}>{formatDate(position.effective_from)}</Detail>
                            </dl>
                        </Card>
                        <Card>
                            <CardHeader><CardTitle>{t('platform.positionsEdit.holders')}</CardTitle></CardHeader>
                            {detail.holders.length === 0 ? (
                                <p className="text-sm text-(--app-muted-foreground)">{t('platform.positionsEdit.noHolders')}</p>
                            ) : (
                                <ul className="divide-y divide-(--app-border) text-sm">
                                    {detail.holders.map((holder) => (
                                        <li key={holder.id} className="flex items-center justify-between gap-2 py-2">
                                            <Link href={`/employees/${holder.employee_id}`} className="font-medium hover:text-primary">{holder.employee?.full_name ?? '—'}</Link>
                                            <span className="text-xs text-(--app-muted-foreground)">{t('platform.positionsEdit.since')} {formatDate(holder.effective_from)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>

                    <Card className="overflow-hidden p-0">
                        <div className="flex items-center justify-between gap-2 border-b border-(--app-border) px-5 py-4">
                            <CardTitle>{t('platform.positionsEdit.establishments')}</CardTitle>
                            {canWrite && <Button variant="outline" size="sm" icon={<ClipboardTextIcon className="h-4 w-4" />} onClick={() => setApproving(true)}>{t('platform.positionsEdit.approveSeats')}</Button>}
                        </div>
                        {detail.establishments.length === 0 ? (
                            <EmptyState icon={<ClipboardTextIcon className="h-8 w-8" />} title={t('platform.positionsEdit.noEstablishments', { n: getNumber('workforce.default_seat_capacity', 1) })} />
                        ) : (
                            <Table>
                                <THead><Tr><Th>{t('platform.positionsEdit.number')}</Th><Th align="right">{t('platform.positionsEdit.approvedSlots')}</Th><Th>{t('core.assignments.from')}</Th><Th>{t('core.assignments.to')}</Th><Th>{t('common.status')}</Th>{canWrite && <Th align="right">{t('common.actions')}</Th>}</Tr></THead>
                                <TBody>
                                    {detail.establishments.map((row) => (
                                        <Tr key={row.id}>
                                            <Td className="font-mono text-xs">{row.establishment_number}</Td>
                                            <Td numeric>{row.approved_slots}</Td>
                                            <Td>{formatDate(row.effective_from)}</Td>
                                            <Td muted>{formatDate(row.effective_to)}</Td>
                                            <Td><StatusBadge status={row.status} /></Td>
                                            {canWrite && <Td align="right">{row.effective_to === null && <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => setEnding(row)}>{t('platform.positionsEdit.end')}</button>}</Td>}
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                </div>
            )}
            <PositionDialog open={editing} position={position ?? null} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); load(); }} />
            <FormDialog
                open={approving}
                title={t('platform.positionsEdit.approveSeats')}
                fields={[
                    { name: 'approved_slots', label: t('platform.positionsEdit.approvedSlots'), type: 'number', required: true },
                    { name: 'effective_from', label: t('platform.structure.effectiveFrom'), type: 'date', required: true },
                    { name: 'establishment_number', label: t('platform.positionsEdit.number'), description: t('platform.positionsEdit.numberHint') },
                    { name: 'approval_reference', label: t('platform.positionsEdit.reference') },
                    { name: 'notes', label: t('platform.cardRequests.notes'), type: 'textarea' },
                ]}
                initial={{ approved_slots: 1, effective_from: new Date().toISOString().slice(0, 10), establishment_number: null, approval_reference: null, notes: null }}
                submitLabel={t('platform.positionsEdit.approveSeats')}
                onClose={() => setApproving(false)}
                onSubmit={async (values) => {
                    await api(`/v1/positions/${id}/establishments`, { method: 'POST', body: values });
                    toast.success(t('platform.actions.saved'));
                    setApproving(false);
                    load();
                }}
            />
            <FormDialog
                open={ending !== null}
                title={t('platform.positionsEdit.endTitle', { number: ending?.establishment_number ?? '' })}
                fields={[
                    { name: 'effective_to', label: t('platform.positionsEdit.lastDay'), type: 'date', required: true },
                    { name: 'reason', label: t('platform.cards.optionalReason'), type: 'textarea' },
                ]}
                initial={{ effective_to: new Date().toISOString().slice(0, 10), reason: null }}
                submitLabel={t('platform.positionsEdit.end')}
                destructive
                onClose={() => setEnding(null)}
                onSubmit={async (values) => {
                    await api(`/v1/establishments/${ending?.id}/end`, { method: 'POST', body: values });
                    toast.success(t('platform.actions.saved'));
                    setEnding(null);
                    load();
                }}
            />
        </AuthenticatedLayout>
    );
}
