import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { ApiError, api, fieldErrors, firstError } from '../api';
import { useAuth } from '../auth';
import FormDialog from '../components/FormDialog';
import { ArrowLeftRightIcon, CardReceiveIcon, CreditCard, PenIcon, UserCheckIcon } from '../components/NavIcons';
import PageHeader from '../components/PageHeader';
import PositionSelect from '../components/PositionSelect';
import StatusBadge from '../components/StatusBadge';
import { useLocale } from '../i18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout';
import { formatDate } from '../lib/format';
import { navigate } from '../router';
import type { Assignment, IdCard } from '../types';
import { Alert, AppDialog, Button, Card, CardHeader, CardTitle, EmptyState, FormField, Input, Select, Table, TBody, THead, Td, Textarea, Th, Tr } from '../ui';
import { CardActions, CardChangeDialog, type Transition } from './Cards';

type EmployeeDetail = {
    employee: { id: string; employee_number: string; full_name: string; first_name: string; middle_name: string | null; last_name: string; gender: string | null; status: string };
    current_assignment: Assignment | null;
    assignments: Assignment[];
    cards: IdCard[];
};

const today = () => new Date().toISOString().slice(0, 10);

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-1 text-sm text-(--app-foreground)">{children}</dd>
        </div>
    );
}

export function EmployeeDetailPage({ id }: { id: string }) {
    const { t } = useLocale();
    const { user } = useAuth();
    const [detail, setDetail] = useState<EmployeeDetail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [transferring, setTransferring] = useState(false);
    const [editing, setEditing] = useState(false);
    const [changingStatus, setChangingStatus] = useState(false);
    const [requestingCard, setRequestingCard] = useState(false);
    const [cardChange, setCardChange] = useState<{ card: IdCard; transition: Transition } | null>(null);

    const load = useCallback(() => {
        api<{ data: EmployeeDetail }>(`/v1/employees/${id}`)
            .then((response) => setDetail(response.data))
            .catch((caught) => setError(caught instanceof ApiError && caught.status === 404 ? t('platform.employee.notFound') : t('platform.error')));
    }, [id, t]);

    useEffect(load, [load]);

    const canChange = Boolean(user?.can_change_master_data);
    const placement = detail?.current_assignment ?? null;
    const actions = canChange && detail ? (
        <>
            <Button variant="outline" icon={<PenIcon className="h-4 w-4" />} onClick={() => setEditing(true)}>{t('platform.employeeEdit.edit')}</Button>
            <Button variant="outline" icon={<UserCheckIcon className="h-4 w-4" />} onClick={() => setChangingStatus(true)}>{t('platform.employeeEdit.changeStatus')}</Button>
            {detail.employee.status === 'active' && <Button variant="outline" icon={<CardReceiveIcon className="h-4 w-4" />} onClick={() => setRequestingCard(true)}>{t('platform.employeeEdit.requestCard')}</Button>}
            {placement?.assignment_status === 'active' && <Button variant="primary" icon={<ArrowLeftRightIcon className="h-4 w-4" />} onClick={() => setTransferring(true)}>{t('platform.employee.transfer')}</Button>}
        </>
    ) : undefined;

    return (
        <AuthenticatedLayout header={(
            <PageHeader
                title={detail?.employee.full_name ?? t('platform.employee.details')}
                description={detail?.employee.employee_number}
                backHref="/employees"
                actions={actions}
            />
        )}>
            {error && <Alert tone="danger">{error}</Alert>}
            {!error && !detail && <Card><EmptyState title={t('common.loading')} /></Card>}
            {detail && (
                <div className="space-y-6">
                    <div className="grid gap-6 lg:grid-cols-2">
                        <Card>
                            <CardHeader><CardTitle>{t('platform.employee.details')}</CardTitle><StatusBadge status={detail.employee.status} /></CardHeader>
                            <dl className="grid grid-cols-2 gap-4">
                                <Detail label={t('platform.employee.firstName')}>{detail.employee.first_name}</Detail>
                                <Detail label={t('platform.employee.middleName')}>{detail.employee.middle_name ?? '—'}</Detail>
                                <Detail label={t('platform.employee.lastName')}>{detail.employee.last_name}</Detail>
                                <Detail label={t('platform.employee.gender')}>{detail.employee.gender ? t(`platform.employee.${detail.employee.gender === 'female' ? 'female' : 'male'}`) : '—'}</Detail>
                            </dl>
                        </Card>
                        <Card>
                            <CardHeader><CardTitle>{t('platform.employee.placement')}</CardTitle>{placement && <StatusBadge status={placement.assignment_status} label={t(`core.assignments.statuses.${placement.assignment_status}`)} />}</CardHeader>
                            {placement ? (
                                <dl className="grid grid-cols-2 gap-4">
                                    <Detail label={t('core.assignments.position')}>{placement.position?.title_en ?? '—'}</Detail>
                                    <Detail label={t('core.assignments.organization')}>{placement.organization?.name_en ?? '—'}</Detail>
                                    <Detail label={t('core.assignments.from')}>{formatDate(placement.effective_from)}</Detail>
                                </dl>
                            ) : (
                                <p className="text-sm text-(--app-muted-foreground)">{t('platform.employee.noPlacement')}</p>
                            )}
                        </Card>
                    </div>

                    <Card className="overflow-hidden p-0">
                        <div className="border-b border-(--app-border) px-5 py-4"><CardTitle>{t('platform.employee.history')}</CardTitle></div>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('core.assignments.position')}</Th>
                                    <Th>{t('core.assignments.organization')}</Th>
                                    <Th>{t('core.assignments.from')}</Th>
                                    <Th>{t('core.assignments.to')}</Th>
                                    <Th>{t('core.assignments.status')}</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {detail.assignments.map((row) => (
                                    <Tr key={row.id}>
                                        <Td>
                                            <span className="font-medium">{row.position?.title_en ?? '—'}</span>
                                            {row.is_current && <span className="ms-2 text-xs text-primary">({t('platform.employee.current')})</span>}
                                        </Td>
                                        <Td muted>{row.organization?.name_en ?? '—'}</Td>
                                        <Td>{formatDate(row.effective_from)}</Td>
                                        <Td muted>{formatDate(row.effective_to)}</Td>
                                        <Td><StatusBadge status={row.assignment_status} label={t(`core.assignments.statuses.${row.assignment_status}`)} /></Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </Card>

                    <Card className="overflow-hidden p-0">
                        <div className="border-b border-(--app-border) px-5 py-4"><CardTitle>{t('platform.employee.cards')}</CardTitle></div>
                        {detail.cards.length === 0 ? (
                            <EmptyState icon={<CreditCard className="h-8 w-8" />} title={t('platform.employee.noCards')} />
                        ) : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>{t('platform.cards.number')}</Th>
                                        <Th>{t('platform.cards.issued')}</Th>
                                        <Th>{t('platform.cards.expires')}</Th>
                                        <Th>{t('common.status')}</Th>
                                        {canChange && <Th align="right">{t('common.actions')}</Th>}
                                    </Tr>
                                </THead>
                                <TBody>
                                    {detail.cards.map((card) => (
                                        <Tr key={card.id}>
                                            <Td className="font-mono text-xs">{card.card_number}</Td>
                                            <Td muted>{formatDate(card.issued_at)}</Td>
                                            <Td muted>{formatDate(card.expires_at)}</Td>
                                            <Td><StatusBadge status={card.status} /></Td>
                                            {canChange && (
                                                <Td align="right">
                                                    <CardActions card={card} onChoose={(transition) => setCardChange({ card, transition })} />
                                                </Td>
                                            )}
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                </div>
            )}

            {detail && placement && (
                <TransferDialog
                    open={transferring}
                    employeeId={detail.employee.id}
                    minimumDate={placement.effective_from}
                    onClose={() => setTransferring(false)}
                    onDone={() => { setTransferring(false); toast.success(t('platform.employee.transferred')); load(); }}
                />
            )}
            <CardChangeDialog
                pending={cardChange}
                onClose={() => setCardChange(null)}
                onDone={() => { setCardChange(null); load(); }}
            />
            {detail && (
                <>
                    <FormDialog
                        open={editing}
                        title={t('platform.employeeEdit.edit')}
                        description={t('platform.employeeEdit.numberFixed')}
                        fields={[
                            { name: 'first_name', label: t('platform.employee.firstName'), required: true },
                            { name: 'middle_name', label: t('platform.employee.middleName') },
                            { name: 'last_name', label: t('platform.employee.lastName'), required: true },
                            { name: 'gender', label: t('platform.employee.gender'), type: 'select', options: [{ value: 'female', label: t('platform.employee.female') }, { value: 'male', label: t('platform.employee.male') }] },
                        ]}
                        initial={{ first_name: detail.employee.first_name, middle_name: detail.employee.middle_name, last_name: detail.employee.last_name, gender: detail.employee.gender }}
                        submitLabel={t('platform.actions.save')}
                        onClose={() => setEditing(false)}
                        onSubmit={async (values) => {
                            await api(`/v1/employees/${detail.employee.id}`, { method: 'PUT', body: values });
                            toast.success(t('platform.employeeEdit.saved'));
                            setEditing(false);
                            load();
                        }}
                    />
                    <FormDialog
                        open={changingStatus}
                        title={t('platform.employeeEdit.changeStatusTitle')}
                        description={t('platform.employeeEdit.statusHelp')}
                        fields={[
                            { name: 'status', label: t('platform.employeeEdit.newStatus'), type: 'select', required: true, emptyOption: null, options: EMPLOYMENT_STATUSES.filter((status) => status !== detail.employee.status).map((status) => ({ value: status, label: t(`platform.status.${status}`) })) },
                            { name: 'reason', label: t('platform.employee.reason'), type: 'textarea', required: true },
                        ]}
                        initial={{ status: EMPLOYMENT_STATUSES.find((status) => status !== detail.employee.status) ?? null, reason: null }}
                        submitLabel={t('platform.employeeEdit.changeStatus')}
                        onClose={() => setChangingStatus(false)}
                        onSubmit={async (values) => {
                            await api(`/v1/employees/${detail.employee.id}/status`, { method: 'POST', body: values });
                            toast.success(t('platform.employeeEdit.statusChanged'));
                            setChangingStatus(false);
                            load();
                        }}
                    />
                    <FormDialog
                        open={requestingCard}
                        title={t('platform.employeeEdit.requestCard')}
                        fields={[
                            { name: 'request_type', label: t('platform.employeeEdit.requestType'), type: 'select', required: true, emptyOption: null, options: ['new', 'renewal', 'correction'].map((type) => ({ value: type, label: t(`platform.requestTypes.${type}`) })) },
                            { name: 'reason', label: t('platform.cards.optionalReason'), type: 'textarea' },
                        ]}
                        initial={{ request_type: 'new', reason: null }}
                        submitLabel={t('platform.employeeEdit.requestCard')}
                        onClose={() => setRequestingCard(false)}
                        onSubmit={async (values) => {
                            await api(`/v1/employees/${detail.employee.id}/card-requests`, { method: 'POST', body: values });
                            toast.success(t('platform.employeeEdit.requested'));
                            setRequestingCard(false);
                        }}
                    />
                </>
            )}
        </AuthenticatedLayout>
    );
}

/** The statuses an employee can be given (EUISIS EmploymentStatusTransition). */
const EMPLOYMENT_STATUSES = ['active', 'suspended', 'retired', 'terminated', 'deceased', 'transferred'];

function TransferDialog({ open, employeeId, minimumDate, onClose, onDone }: { open: boolean; employeeId: string; minimumDate: string | null; onClose: () => void; onDone: () => void }) {
    const { t } = useLocale();
    const [positionId, setPositionId] = useState('');
    const [effectiveDate, setEffectiveDate] = useState(today);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    async function submit() {
        setBusy(true);
        setErrors({});
        try {
            await api(`/v1/employees/${employeeId}/transfer`, { method: 'POST', body: { position_id: positionId, effective_date: effectiveDate, reason: reason || null } });
            setPositionId('');
            setReason('');
            onDone();
        } catch (caught) {
            const fields = fieldErrors(caught);
            setErrors(Object.keys(fields).length > 0 ? fields : { position_id: firstError(caught) ?? t('platform.error') });
        } finally {
            setBusy(false);
        }
    }

    return (
        <AppDialog
            open={open}
            onClose={() => { if (!busy) onClose(); }}
            title={t('platform.employee.transferTitle')}
            description={t('platform.employee.transferDescription')}
            maxWidth="lg"
            footer={(
                <>
                    <Button variant="outline" onClick={onClose} disabled={busy}>{t('common.cancel')}</Button>
                    <Button variant="primary" onClick={() => void submit()} loading={busy} disabled={!positionId}>{t('platform.employee.transfer')}</Button>
                </>
            )}
        >
            <div className="space-y-4">
                {errors.employee && <Alert tone="danger">{errors.employee}</Alert>}
                <FormField label={t('core.assignments.position')} required error={errors.position_id}>
                    {({ id }) => <PositionSelect id={id} value={positionId} onChange={setPositionId} />}
                </FormField>
                <FormField label={t('platform.employee.effectiveDate')} required error={errors.effective_date}>
                    {({ id, invalid }) => <Input id={id} type="date" min={minimumDate?.slice(0, 10)} value={effectiveDate} aria-invalid={invalid || undefined} onChange={(e) => setEffectiveDate(e.target.value)} />}
                </FormField>
                <FormField label={`${t('platform.employee.reason')} (${t('common.optional')})`} error={errors.reason}>
                    {({ id }) => <Textarea id={id} maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} />}
                </FormField>
            </div>
        </AppDialog>
    );
}

export function RegisterEmployeePage() {
    const { t } = useLocale();
    const [form, setForm] = useState({ employee_number: '', first_name: '', middle_name: '', last_name: '', gender: '', effective_from: today(), position_id: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const set = (name: keyof typeof form) => (event: { target: { value: string } }) => setForm((current) => ({ ...current, [name]: event.target.value }));

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            const response = await api<{ data: { id: string } }>('/v1/employees', {
                method: 'POST',
                body: { ...form, middle_name: form.middle_name || null, gender: form.gender || null },
            });
            toast.success(t('platform.register.registered'));
            navigate(`/employees/${response.data.id}`);
        } catch (caught) {
            const fields = fieldErrors(caught);
            setErrors(Object.keys(fields).length > 0 ? fields : { form: firstError(caught) ?? t('platform.error') });
        } finally {
            setBusy(false);
        }
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.register.title')} description={t('platform.register.description')} backHref="/employees" />}>
            <form onSubmit={submit} className="space-y-6">
                {errors.form && <Alert tone="danger">{errors.form}</Alert>}
                <Card>
                    <CardHeader><CardTitle>{t('platform.register.personal')}</CardTitle></CardHeader>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <FormField label={t('platform.register.employeeNumber')} required error={errors.employee_number}>
                            {({ id, invalid }) => <Input id={id} required maxLength={255} value={form.employee_number} aria-invalid={invalid || undefined} onChange={set('employee_number')} />}
                        </FormField>
                        <FormField label={t('platform.employee.gender')} error={errors.gender}>
                            {({ id }) => (
                                <Select id={id} value={form.gender} onChange={set('gender')}>
                                    <option value="">—</option>
                                    <option value="female">{t('platform.employee.female')}</option>
                                    <option value="male">{t('platform.employee.male')}</option>
                                </Select>
                            )}
                        </FormField>
                        <div className="hidden lg:block" />
                        <FormField label={t('platform.employee.firstName')} required error={errors.first_name}>
                            {({ id, invalid }) => <Input id={id} required maxLength={255} value={form.first_name} aria-invalid={invalid || undefined} onChange={set('first_name')} />}
                        </FormField>
                        <FormField label={t('platform.employee.middleName')} error={errors.middle_name}>
                            {({ id }) => <Input id={id} maxLength={255} value={form.middle_name} onChange={set('middle_name')} />}
                        </FormField>
                        <FormField label={t('platform.employee.lastName')} required error={errors.last_name}>
                            {({ id, invalid }) => <Input id={id} required maxLength={255} value={form.last_name} aria-invalid={invalid || undefined} onChange={set('last_name')} />}
                        </FormField>
                    </div>
                </Card>
                <Card>
                    <CardHeader><CardTitle>{t('platform.register.placement')}</CardTitle></CardHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label={t('platform.register.position')} required error={errors.position_id}>
                            {({ id }) => <PositionSelect id={id} value={form.position_id} onChange={(positionId) => setForm((current) => ({ ...current, position_id: positionId }))} />}
                        </FormField>
                        <FormField label={t('platform.register.startDate')} required error={errors.effective_from}>
                            {({ id, invalid }) => <Input id={id} type="date" required value={form.effective_from} aria-invalid={invalid || undefined} onChange={set('effective_from')} />}
                        </FormField>
                    </div>
                </Card>
                <div className="flex justify-end gap-2">
                    <Button variant="outline" onClick={() => navigate('/employees')} disabled={busy}>{t('common.cancel')}</Button>
                    <Button type="submit" variant="primary" loading={busy} disabled={!form.position_id}>{t('platform.register.submit')}</Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
