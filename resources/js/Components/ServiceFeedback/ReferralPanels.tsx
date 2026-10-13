import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, FormField, Input, SearchableSelect, Textarea, actionLinkClassName, type SearchableOption } from '@euisis/ui';
import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export type SubjectRow = {
    id: string;
    employee: { id: string; full_name: string | null; employee_number: string | null };
    subject_role: string | null;
    added_by: string | null;
    added_at: string | null;
};

export type ReferralRow = {
    id: string;
    kind: string;
    status: 'requested' | 'accepted' | 'declined' | 'withdrawn';
    notes: string | null;
    requested_by: string | null;
    requested_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_notes: string | null;
    disciplinary_case_id: string | null;
    mine: boolean;
};

const PANEL = 'space-y-4 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5';
const REFERRAL_TONE: Record<ReferralRow['status'], string> = { requested: 'pending', accepted: 'active', declined: 'rejected', withdrawn: 'inactive' };

/** Investigation subjects of a complaint (brief §61–62): named employees, never a change of target. */
export function SubjectsPanel({ submissionId, subjects, canManage }: { submissionId: string; subjects: SubjectRow[]; canManage: boolean }) {
    const { t } = useLocale();
    const form = useForm({ employee_id: '', subject_role: '' });

    async function searchEmployees(query: string, signal: AbortSignal): Promise<SearchableOption[]> {
        const response = await fetch(route('lookup.employees', { q: query }), { signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) return [];
        const body = (await response.json()) as { data: { id: string; full_name: string | null; employee_number: string | null; organization: { name_en: string | null } | null }[] };
        return body.data.map((row) => ({ value: row.id, label: row.full_name ?? row.employee_number ?? row.id, description: [row.employee_number, row.organization?.name_en].filter(Boolean).join(' · ') }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('service-submissions.subjects.store', { type: 'complaint', submission: submissionId }), { preserveScroll: true, onSuccess: () => form.reset() });
    }

    function remove(subject: SubjectRow) {
        router.delete(route('service-submissions.subjects.destroy', { type: 'complaint', submission: submissionId, subject: subject.id }), { preserveScroll: true });
    }

    return (
        <section className={PANEL}>
            <h2 className="font-semibold">{t('serviceFeedback.subjectsTitle')}</h2>
            <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.subjectsHint')}</p>
            {subjects.length === 0
                ? <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.noSubjects')}</p>
                : <ul className="divide-y divide-[color:var(--app-border)] text-sm">
                    {subjects.map((subject) => (
                        <li key={subject.id} className="flex items-start justify-between gap-3 py-2">
                            <div>
                                <div className="font-medium">{subject.employee.full_name ?? subject.employee.employee_number}</div>
                                <div className="text-xs text-[color:var(--app-muted-foreground)]">{[subject.employee.employee_number, subject.subject_role].filter(Boolean).join(' · ')} · {subject.added_by ?? t('common.system')} · <LocalizedDateDisplay value={subject.added_at} /></div>
                            </div>
                            {canManage && <button type="button" className={actionLinkClassName({ tone: 'danger' })} onClick={() => remove(subject)}>{t('common.delete')}</button>}
                        </li>
                    ))}
                </ul>}
            {canManage && (
                <form onSubmit={submit} className="grid gap-3 border-t border-[color:var(--app-border)] pt-4">
                    <FormField id="subject-employee" label={t('serviceFeedback.filterEmployee')} error={form.errors.employee_id} required>
                        {({ id }) => <SearchableSelect id={id} label={t('serviceFeedback.filterEmployee')} value={form.data.employee_id} loadOptions={searchEmployees} onChange={(value) => form.setData('employee_id', value)} placeholder={t('serviceFeedback.searchEmployee')} />}
                    </FormField>
                    <FormField id="subject-role" label={t('serviceFeedback.subjectRole')} error={form.errors.subject_role}>
                        {({ id, describedBy, invalid }) => <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} maxLength={120} value={form.data.subject_role} onChange={(e) => form.setData('subject_role', e.target.value)} />}
                    </FormField>
                    <div className="flex justify-end"><Button type="submit" variant="outline" loading={form.processing} disabled={!form.data.employee_id}>{t('serviceFeedback.addSubject')}</Button></div>
                </form>
            )}
        </section>
    );
}

/** Controlled discipline referral requests (brief §59–60): a request record, never a case. */
export function ReferralsPanel({ submissionId, referrals, canRefer, canDecide }: { submissionId: string; referrals: ReferralRow[]; canRefer: boolean; canDecide: boolean }) {
    const { t } = useLocale();
    const form = useForm({ notes: '' });
    const open = referrals.find((referral) => referral.status === 'requested') ?? null;

    function request(event: FormEvent) {
        event.preventDefault();
        form.post(route('service-submissions.referrals.store', { type: 'complaint', submission: submissionId }), { preserveScroll: true, onSuccess: () => form.reset() });
    }

    function decide(referral: ReferralRow, status: 'accepted' | 'declined' | 'withdrawn') {
        router.patch(route('service-submissions.referrals.update', { type: 'complaint', submission: submissionId, referral: referral.id }), { status }, { preserveScroll: true });
    }

    return (
        <section className={PANEL}>
            <h2 className="font-semibold">{t('serviceFeedback.referralTitle')}</h2>
            <Alert tone="info">{t('serviceFeedback.referralBoundaryNote')}</Alert>
            {referrals.length === 0
                ? <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.noReferrals')}</p>
                : <ul className="space-y-3 text-sm">
                    {referrals.map((referral) => (
                        <li key={referral.id} className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <StatusBadge status={REFERRAL_TONE[referral.status]} label={t(`serviceFeedback.referral_${referral.status}`)} />
                                <span className="text-xs text-[color:var(--app-muted-foreground)]">{referral.requested_by ?? t('common.system')} · <LocalizedDateDisplay value={referral.requested_at} withTime /></span>
                            </div>
                            {referral.notes && <p className="mt-2 whitespace-pre-wrap">{referral.notes}</p>}
                            {referral.decided_at && <p className="mt-2 text-xs text-[color:var(--app-muted-foreground)]">{t(`serviceFeedback.referral_${referral.status}`)} · {referral.decided_by ?? t('common.system')} · <LocalizedDateDisplay value={referral.decided_at} withTime />{referral.decision_notes && <> · {referral.decision_notes}</>}</p>}
                            {referral.disciplinary_case_id && <p className="mt-2 text-xs text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.referralLinkedCase')}</p>}
                            {referral.status === 'requested' && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {canDecide && !referral.mine && <>
                                        <Button type="button" size="sm" variant="primary" onClick={() => decide(referral, 'accepted')}>{t('serviceFeedback.acceptReferral')}</Button>
                                        <Button type="button" size="sm" variant="outline" onClick={() => decide(referral, 'declined')}>{t('serviceFeedback.declineReferral')}</Button>
                                    </>}
                                    {referral.mine && <Button type="button" size="sm" variant="ghost" onClick={() => decide(referral, 'withdrawn')}>{t('serviceFeedback.withdrawReferral')}</Button>}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>}
            {canRefer && open === null && (
                <form onSubmit={request} className="space-y-3 border-t border-[color:var(--app-border)] pt-4">
                    <FormField id="referral-notes" label={t('serviceFeedback.referralNotes')} description={t('serviceFeedback.referralNotesHint')} error={form.errors.notes} required>
                        {({ id, describedBy, invalid }) => <Textarea id={id} aria-describedby={describedBy} aria-invalid={invalid} rows={3} maxLength={2000} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />}
                    </FormField>
                    <Button type="submit" variant="outline" loading={form.processing}>{t('serviceFeedback.requestReferral')}</Button>
                </form>
            )}
        </section>
    );
}
