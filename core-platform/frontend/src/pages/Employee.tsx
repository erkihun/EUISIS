import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { api, ApiError, firstError } from '../api';
import { useAuth } from '../auth';
import { PositionPicker } from '../components/PositionPicker';
import { buttonClass, Card, Detail, Field, inputClass, Modal, Notice, StatusBadge } from '../components/ui';
import { useI18n } from '../i18n';
import { navigate } from '../router';
import type { Assignment, IdCard, Position } from '../types';

type EmployeeDetail = {
    employee: { id: string; employee_number: string; full_name: string; first_name: string; middle_name: string | null; last_name: string; gender: string | null; status: string };
    current_assignment: Assignment | null;
    assignments: Assignment[];
    cards: IdCard[];
};

const today = () => new Date().toISOString().slice(0, 10);

export function EmployeeDetailPage({ id }: { id: string }) {
    const { t, date } = useI18n();
    const { user } = useAuth();
    const [detail, setDetail] = useState<EmployeeDetail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [transferring, setTransferring] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const load = useCallback(() => {
        api<{ data: EmployeeDetail }>(`/v1/employees/${id}`)
            .then((response) => setDetail(response.data))
            .catch((caught) => setError(caught instanceof ApiError && caught.status === 404 ? t('common.empty') : t('common.error')));
    }, [id, t]);

    useEffect(load, [load]);

    if (error) return <Notice kind="error">{error}</Notice>;
    if (!detail) return <p className="text-muted">{t('common.loading')}</p>;

    const { employee, current_assignment: placement } = detail;
    const canTransfer = user?.can_change_master_data && placement?.assignment_status === 'active';

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <button type="button" onClick={() => navigate('/employees')} className="text-xs text-muted hover:underline">
                        ← {t('common.back')}
                    </button>
                    <h1 className="text-xl font-semibold text-ink">{employee.full_name}</h1>
                    <p className="font-mono text-xs text-muted">{employee.employee_number}</p>
                </div>
                {canTransfer && (
                    <button type="button" className={buttonClass.primary} onClick={() => (setNotice(null), setTransferring(true))}>
                        {t('emp.transfer')}
                    </button>
                )}
            </div>

            {notice && <Notice kind="ok">{notice}</Notice>}

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title={t('emp.details')}>
                    <dl className="grid grid-cols-2 gap-3">
                        <Detail label={t('emp.firstName')}>{employee.first_name}</Detail>
                        <Detail label={t('emp.middleName')}>{employee.middle_name ?? '—'}</Detail>
                        <Detail label={t('emp.lastName')}>{employee.last_name}</Detail>
                        <Detail label={t('emp.gender')}>{employee.gender ? t(employee.gender === 'female' ? 'emp.female' : 'emp.male') : '—'}</Detail>
                        <Detail label={t('common.status')}>
                            <StatusBadge value={employee.status} />
                        </Detail>
                    </dl>
                </Card>
                <Card title={t('emp.placement')}>
                    {placement ? (
                        <dl className="grid grid-cols-2 gap-3">
                            <Detail label={t('common.position')}>{placement.position?.title_en ?? '—'}</Detail>
                            <Detail label={t('common.organization')}>{placement.organization?.name_en ?? '—'}</Detail>
                            <Detail label={t('common.from')}>{date(placement.effective_from)}</Detail>
                            <Detail label={t('common.status')}>
                                <StatusBadge value={placement.assignment_status} />
                            </Detail>
                        </dl>
                    ) : (
                        <p className="text-sm text-muted">{t('emp.noPlacement')}</p>
                    )}
                </Card>
            </div>

            <Card title={t('emp.history')}>
                <ul className="divide-y divide-line">
                    {detail.assignments.map((assignment) => (
                        <li key={assignment.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <span>
                                <strong>{assignment.position?.title_en ?? '—'}</strong> · {assignment.organization?.name_en}
                                {assignment.is_current && <span className="ml-2 text-xs text-brand">({t('asg.current')})</span>}
                            </span>
                            <span className="flex items-center gap-2 text-muted">
                                {date(assignment.effective_from)} → {date(assignment.effective_to)} <StatusBadge value={assignment.assignment_status} />
                            </span>
                        </li>
                    ))}
                </ul>
            </Card>

            <Card title={t('emp.cards')}>
                {detail.cards.length === 0 ? (
                    <p className="text-sm text-muted">{t('common.empty')}</p>
                ) : (
                    <ul className="divide-y divide-line">
                        {detail.cards.map((card) => (
                            <li key={card.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span className="font-mono text-xs">{card.card_number}</span>
                                <span className="flex items-center gap-2 text-muted">
                                    {t('card.issued')} {date(card.issued_at)} · {t('card.expires')} {date(card.expires_at)} <StatusBadge value={card.status} />
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {transferring && placement && (
                <TransferDialog
                    employeeId={employee.id}
                    minimumDate={placement.effective_from}
                    onClose={() => setTransferring(false)}
                    onDone={() => {
                        setTransferring(false);
                        setNotice(t('emp.transferred'));
                        load();
                    }}
                />
            )}
        </div>
    );
}

function TransferDialog({ employeeId, minimumDate, onClose, onDone }: { employeeId: string; minimumDate: string | null; onClose: () => void; onDone: () => void }) {
    const { t } = useI18n();
    const [position, setPosition] = useState<Position | null>(null);
    const [effectiveDate, setEffectiveDate] = useState(today);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (!position) return;
        setBusy(true);
        setErrors({});
        setError(null);
        try {
            await api(`/v1/employees/${employeeId}/transfer`, { method: 'POST', body: { position_id: position.id, effective_date: effectiveDate, reason: reason || null } });
            onDone();
        } catch (caught) {
            if (caught instanceof ApiError) setErrors(caught.errors);
            setError(firstError(caught) ?? t('common.error'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal title={t('emp.transferTitle')} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                {error && Object.keys(errors).length === 0 && <Notice kind="error">{error}</Notice>}
                <Field label={t('common.position')} error={errors.position_id?.[0] ?? errors.employee?.[0]}>
                    <PositionPicker value={position} onChange={setPosition} />
                </Field>
                <Field label={t('emp.effectiveDate')} error={errors.effective_date?.[0]}>
                    <input type="date" required min={minimumDate?.slice(0, 10)} value={effectiveDate} onChange={(event) => setEffectiveDate(event.target.value)} className={inputClass} />
                </Field>
                <Field label={t('common.reason')} error={errors.reason?.[0]}>
                    <textarea rows={2} maxLength={1000} value={reason} onChange={(event) => setReason(event.target.value)} className={inputClass} />
                </Field>
                <div className="flex justify-end gap-2">
                    <button type="button" className={buttonClass.secondary} onClick={onClose}>
                        {t('common.cancel')}
                    </button>
                    <button type="submit" disabled={busy || !position} className={buttonClass.primary}>
                        {busy ? t('common.saving') : t('emp.transfer')}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

export function RegisterEmployee() {
    const { t } = useI18n();
    const [form, setForm] = useState({ employee_number: '', first_name: '', middle_name: '', last_name: '', gender: '', effective_from: today() });
    const [position, setPosition] = useState<Position | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const set = (name: keyof typeof form) => (event: { target: { value: string } }) => setForm((current) => ({ ...current, [name]: event.target.value }));

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (!position) return;
        setBusy(true);
        setErrors({});
        setError(null);
        try {
            const response = await api<{ data: { id: string } }>('/v1/employees', {
                method: 'POST',
                body: { ...form, middle_name: form.middle_name || null, gender: form.gender || null, position_id: position.id },
            });
            navigate(`/employees/${response.data.id}`);
        } catch (caught) {
            if (caught instanceof ApiError) setErrors(caught.errors);
            setError(firstError(caught) ?? t('common.error'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="max-w-2xl space-y-4">
            <h1 className="text-xl font-semibold text-ink">{t('nav.register')}</h1>
            <p className="text-sm text-muted">{t('emp.registerHelp')}</p>
            {error && Object.keys(errors).length === 0 && <Notice kind="error">{error}</Notice>}
            <form onSubmit={submit} className="space-y-4 rounded-lg border border-line bg-surface p-5">
                <Field label={t('emp.number')} error={errors.employee_number?.[0]}>
                    <input required maxLength={255} value={form.employee_number} onChange={set('employee_number')} className={inputClass} />
                </Field>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label={t('emp.firstName')} error={errors.first_name?.[0]}>
                        <input required maxLength={255} value={form.first_name} onChange={set('first_name')} className={inputClass} />
                    </Field>
                    <Field label={t('emp.middleName')} error={errors.middle_name?.[0]}>
                        <input maxLength={255} value={form.middle_name} onChange={set('middle_name')} className={inputClass} />
                    </Field>
                    <Field label={t('emp.lastName')} error={errors.last_name?.[0]}>
                        <input required maxLength={255} value={form.last_name} onChange={set('last_name')} className={inputClass} />
                    </Field>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('emp.gender')} error={errors.gender?.[0]}>
                        <select value={form.gender} onChange={set('gender')} className={inputClass}>
                            <option value="">—</option>
                            <option value="female">{t('emp.female')}</option>
                            <option value="male">{t('emp.male')}</option>
                        </select>
                    </Field>
                    <Field label={t('emp.effectiveFrom')} error={errors.effective_from?.[0]}>
                        <input type="date" required value={form.effective_from} onChange={set('effective_from')} className={inputClass} />
                    </Field>
                </div>
                <Field label={t('common.position')} error={errors.position_id?.[0]}>
                    <PositionPicker value={position} onChange={setPosition} />
                </Field>
                <div className="flex justify-end">
                    <button type="submit" disabled={busy || !position} className={buttonClass.primary}>
                        {busy ? t('common.saving') : t('common.save')}
                    </button>
                </div>
            </form>
        </div>
    );
}
