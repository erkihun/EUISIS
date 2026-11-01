import { router, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';
import { AppDialog } from '@euisis/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedTimePicker from '@/Components/Calendar/LocalizedTimePicker';
import { Button, FormField, Input, Textarea, actionLinkClassName, badgeClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableContainerClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import type { CaseDetailPermissions, CaseHearing } from '@/court/types/caseDetail';
import SectionCard, { EmptyBox } from './SectionCard';
import { useCaseText } from './useCaseText';
import { CalendarIcon, PencilIcon, PlusIcon, TrashIcon } from './icons';

interface Props {
    caseId: number;
    hearings: CaseHearing[];
    can: CaseDetailPermissions;
    caseLocked: boolean;
}

type HearingForm = { hearing_at: string; type: string; location: string; notes: string };

const DEFAULT_TIME = '08:00';
const pad = (n: number) => String(n).padStart(2, '0');
const todayIso = (): string => {
    const d = new Date();
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};
/** The local date (YYYY-MM-DD) and wall-clock time (HH:MM) of an ISO 8601 hearing timestamp. */
const splitHearingAt = (iso: string | null): { date: string; time: string } => {
    if (!iso) return { date: '', time: DEFAULT_TIME };
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return { date: iso.slice(0, 10), time: iso.slice(11, 16) || DEFAULT_TIME };
    return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: `${pad(d.getHours())}:${pad(d.getMinutes())}` };
};

/**
 * The hearings of the case: add one (date + time + type + location + notes;
 * one hearing per day), edit or remove a future one. A hearing on or before
 * today is locked, as the server enforces.
 */
export default function HearingsSection({ caseId, hearings, can, caseLocked }: Props) {
    const { t, text } = useCaseText();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState<CaseHearing | null>(null);
    const [removing, setRemoving] = useState<number | null>(null);
    const ordered = useMemo(() => [...hearings].sort((a, b) => (b.hearing_at ?? '').localeCompare(a.hearing_at ?? '')), [hearings]);
    const takenDates = useMemo(() => new Set(hearings.map((h) => splitHearingAt(h.hearing_at).date).filter(Boolean)), [hearings]);

    const remove = async (hearing: CaseHearing) => {
        const result = await confirm({ title: t('cm.cases.hearings.remove_confirm'), variant: 'danger', confirmLabel: t('cm.cases.general.delete'), cancelLabel: t('cm.cases.general.cancel') });
        if (!result.confirmed) return;
        router.delete(route('court.cases.hearings.delete', hearing.id), { preserveScroll: true, onStart: () => setRemoving(hearing.id), onFinish: () => setRemoving(null) });
    };

    return (
        <SectionCard id="hearings" title={t('cm.cases.hearings.title')} icon={<CalendarIcon />} count={`${hearings.length} ${t('cm.cases.hearings.total')}`}>
            {!caseLocked && can.createHearings && <HearingCreateForm caseId={caseId} takenDates={takenDates} />}

            {ordered.length === 0 ? (
                <EmptyBox>
                    <CalendarIcon className="mx-auto mb-2 h-10 w-10 opacity-50" />
                    {t('cm.cases.hearings.no_hearings')}
                </EmptyBox>
            ) : (
                <div className={tableContainerClassName}>
                    <div className="overflow-x-auto">
                        <table className={cx(tableClassName, 'min-w-full text-left')}>
                            <thead className={tableHeadClassName}>
                                <tr>
                                    <th className={cx(tableHeaderCellClassName, 'w-12 text-left')}>#</th>
                                    {[t('cm.cases.show.when'), t('cm.cases.hearings.type_placeholder'), t('cm.cases.hearings.location_placeholder'), t('cm.cases.hearings.notes_placeholder'), t('cm.cases.table.actions')].map((heading) => (
                                        <th key={heading} className={cx(tableHeaderCellClassName, 'text-left')}>{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className={tableBodyClassName}>
                                {ordered.map((hearing, index) => {
                                    const editable = !caseLocked && !hearing.date_locked;
                                    return (
                                        <tr key={hearing.id} className={cx(tableRowClassName, 'align-top')}>
                                            <td className={cx(tableCellClassName, 'text-(--app-muted-foreground)')}>{index + 1}</td>
                                            <td className={cx(tableCellClassName, 'font-medium text-(--app-foreground)')}><LocalizedDateDisplay value={hearing.hearing_at} withTime /></td>
                                            <td className={tableCellClassName}>{hearing.type || '-'}</td>
                                            <td className={tableCellClassName}>{hearing.location || '-'}</td>
                                            <td className={tableCellClassName}>{hearing.notes || '-'}</td>
                                            <td className={tableCellClassName}>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {editable && can.updateHearings && (
                                                        <button type="button" onClick={() => setEditing(hearing)} className={actionLinkClassName({ tone: 'neutral' })}><PencilIcon className="h-3 w-3" /> {t('cm.cases.general.edit')}</button>
                                                    )}
                                                    {editable && can.deleteHearings && (
                                                        <button type="button" onClick={() => remove(hearing)} disabled={removing === hearing.id} className={actionLinkClassName({ tone: 'danger' })}><TrashIcon className="h-3 w-3" /> {t('cm.cases.general.delete')}</button>
                                                    )}
                                                    {hearing.date_locked && <span className={badgeClassName('warning')}>{text('hearing_locked')}</span>}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {editing && <HearingEditDialog key={editing.id} hearing={editing} takenDates={takenDates} onClose={() => setEditing(null)} />}
        </SectionCard>
    );
}

function HearingCreateForm({ caseId, takenDates }: { caseId: number; takenDates: Set<string> }) {
    const { t, text } = useCaseText();
    const [date, setDate] = useState('');
    const [time, setTime] = useState(DEFAULT_TIME);
    const [dateError, setDateError] = useState<string | null>(null);
    const form = useForm<HearingForm>({ hearing_at: '', type: '', location: '', notes: '' });

    const pickDate = (value: string) => {
        setDate(value);
        if (value && value < todayIso()) setDateError(t('cm.cases.show.select_today_or_future'));
        else if (value && takenDates.has(value)) setDateError(t('cm.cases.show.duplicate_hearing_date'));
        else setDateError(null);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!date) { setDateError(t('cm.cases.show.select_hearing_date')); return; }
        if (dateError) return;
        form.transform((data) => ({ ...data, hearing_at: `${date} ${time || DEFAULT_TIME}:00` }));
        form.post(route('court.cases.hearings.store', caseId), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setDate(''); setTime(DEFAULT_TIME); },
        });
    };

    return (
        <div className="space-y-3">
            <h4 className="text-sm font-medium text-(--app-foreground)">{t('cm.cases.hearings.add_new_hearing')}</h4>
            <form onSubmit={submit} className="grid gap-3 rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4 md:grid-cols-2 xl:grid-cols-4">
                <FormField id="hearing-date-new" label={text('hearing_date')} required error={dateError ?? form.errors.hearing_at}>
                    {({ id }) => <LocalizedDatePicker id={id} value={date} onChange={pickDate} min={todayIso()} required placeholder={t('cm.cases.hearings.add_new_hearing')} />}
                </FormField>
                <FormField id="hearing-time-new" label={text('hearing_time')} required>
                    <LocalizedTimePicker value={time} onChange={setTime} />
                </FormField>
                <FormField id="hearing-type-new" label={t('cm.cases.hearings.type_placeholder')} required error={form.errors.type}>
                    {({ id, describedBy, invalid }) => <Input id={id} required value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} placeholder={t('cm.cases.hearings.type_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                </FormField>
                <FormField id="hearing-location-new" label={t('cm.cases.hearings.location_placeholder')} error={form.errors.location}>
                    {({ id, describedBy, invalid }) => <Input id={id} value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} placeholder={t('cm.cases.hearings.location_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                </FormField>
                <FormField id="hearing-notes-new" label={t('cm.cases.hearings.notes_placeholder')} required error={form.errors.notes} className="md:col-span-2 xl:col-span-4">
                    {({ id, describedBy, invalid }) => <Textarea id={id} rows={2} required value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder={t('cm.cases.hearings.notes_placeholder')} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                </FormField>
                <div className="md:col-span-2 xl:col-span-4">
                    <Button type="submit" variant="primary" loading={form.processing} icon={<PlusIcon className="h-4 w-4" />} className="w-full">{t('cm.cases.hearings.add_hearing')}</Button>
                </div>
            </form>
        </div>
    );
}

function HearingEditDialog({ hearing, takenDates, onClose }: { hearing: CaseHearing; takenDates: Set<string>; onClose: () => void }) {
    const { t, text } = useCaseText();
    const initial = splitHearingAt(hearing.hearing_at);
    const [date, setDate] = useState(initial.date);
    const [time, setTime] = useState(initial.time);
    const [dateError, setDateError] = useState<string | null>(null);
    const form = useForm<HearingForm>({ hearing_at: hearing.hearing_at ?? '', type: hearing.type ?? '', location: hearing.location ?? '', notes: hearing.notes ?? '' });

    const pickDate = (value: string) => {
        setDate(value);
        if (value && value !== initial.date && takenDates.has(value)) setDateError(t('cm.cases.show.duplicate_hearing_date'));
        else setDateError(null);
    };

    const save = (event: FormEvent) => {
        event.preventDefault();
        if (!date) { setDateError(t('cm.cases.show.select_hearing_date')); return; }
        if (dateError) return;
        form.transform((data) => ({ ...data, hearing_at: `${date} ${time || DEFAULT_TIME}:00` }));
        form.patch(route('court.cases.hearings.update', hearing.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <AppDialog open onClose={onClose} title={text('edit_hearing')} maxWidth="lg" footer={(
            <>
                <Button variant="outline" onClick={onClose} disabled={form.processing}>{t('cm.cases.general.cancel')}</Button>
                <Button variant="primary" type="submit" form={`hearing-edit-${hearing.id}`} loading={form.processing}>{t('cm.cases.general.save')}</Button>
            </>
        )}>
            <form id={`hearing-edit-${hearing.id}`} onSubmit={save} className="space-y-3">
                <div className="grid gap-3 sm:grid-cols-2">
                    <FormField label={text('hearing_date')} required error={dateError ?? form.errors.hearing_at}>
                        {({ id }) => <LocalizedDatePicker id={id} value={date} onChange={pickDate} min={todayIso()} required />}
                    </FormField>
                    <FormField label={text('hearing_time')} required>
                        <LocalizedTimePicker value={time} onChange={setTime} />
                    </FormField>
                </div>
                <FormField label={t('cm.cases.hearings.type_placeholder')} required error={form.errors.type}>
                    {({ id, describedBy, invalid }) => <Input id={id} required value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                </FormField>
                <FormField label={t('cm.cases.hearings.location_placeholder')} error={form.errors.location}>
                    {({ id, describedBy, invalid }) => <Input id={id} value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                </FormField>
                <FormField label={t('cm.cases.hearings.notes_placeholder')} required error={form.errors.notes}>
                    {({ id, describedBy, invalid }) => <Textarea id={id} rows={2} required value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="w-full" />}
                </FormField>
            </form>
        </AppDialog>
    );
}
