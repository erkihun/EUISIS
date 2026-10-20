import StatusBadge from '@/Components/StatusBadge';
import { inputCls, labelCls, panelCls, secondaryBtn } from '@/Components/dailyActivity/helpers';
import { useLocale } from '@/hooks/useLocale';
import { formatDateDisplay, formatDateTimeDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import type { JSX, ReactNode } from 'react';

export type EmployeeRef = { id: string; name: string | null; name_am: string | null; employee_number: string | null } | null;

export type Deadline = {
    id: string;
    rule_key: string;
    label: string;
    legal_reference: string;
    basis: string;
    amount: number;
    started_at: string | null;
    due_at: string | null;
    completed_at: string | null;
    superseded: boolean;
    overdue: boolean;
};

export type CaseRow = {
    id: string;
    case_number: string | null;
    status: string;
    track: string;
    severity: string;
    /** Begun under the earlier regulation, which continues to govern it (transitional provision). */
    legacy_regulation: boolean;
    accused: EmployeeRef;
    organization: { id: string; name: string } | null;
    charged_at: string | null;
    next_deadline: Deadline | null;
    created_at: string | null;
};

export type Charge = {
    id: string; sequence: number; title: string; particulars: string; occurred_on: string | null; occurred_place: string;
    violated_provision: string; evidence_list: string; plea: string | null; plea_statement: string | null;
};

export type ResponseRow = {
    id: string; version: number; objection_ground: string | null; objection_text: string | null; objection_decision: string | null;
    defense_evidence_list: string | null; acknowledged_name: string; received_at: string | null;
    /** Art. 20: the pleas this response gave (empty for responses recorded before they were kept). */
    pleas: { charge_id: string; sequence: number; plea: 'admitted' | 'denied'; statement: string | null }[];
};

export type EvidenceRow = {
    id: string; side: string; title: string; description: string | null; held_by: string | null; has_file: boolean;
    original_name: string | null; size_bytes: number | null; created_at: string | null;
};

export type WitnessRow = {
    id: string; called_by: string; name: string; age: number | null; occupation: string | null; relationship_disclosure: string | null;
    statement: string | null; cross_examination: string | null; written_testimony: boolean; heard_at: string | null;
};

export type DecisionRow = {
    id: string; action: string; finding: string | null; penalty: string | null; penalty_amount: number | null; penalty_unit: string | null;
    reason: string; decided_at: string | null; delivered_at: string | null; delivery_method: string | null; effective_on: string | null;
    /** Art. 29: the penalty is not executed while an appeal is pending. */
    execution_stayed?: boolean;
    appeal?: AppealRow | null;
};

/** The employee's appeal to the Administrative Court against a delivered decision (Regulation 201/2018 Part Five). */
export type AppealRow = {
    notice_received_at: string | null; record_copy_requested: boolean; record_copy_delivered_at: string | null;
    filed_on: string | null; court_case_number: string | null;
    outcome: 'upheld' | 'modified' | 'reversed' | 'remanded' | 'withdrawn' | null;
    outcome_decided_on: string | null; outcome_received_at: string | null; outcome_summary: string | null;
    implemented_at: string | null; implementation_note: string | null;
};

/** A suspension pending the case (Regulation 201/2018 Art. 12). */
export type SuspensionRow = {
    id: string; reason: string; without_pay: boolean; starts_on: string | null; ends_on: string | null;
    served_at: string | null; service_method: string; posting_ends_on: string | null; in_force: boolean;
    lifted_on: string | null; lift_reason: string | null;
    back_pay_status: 'due' | 'not_due' | 'paid' | null; back_pay_paid_on: string | null;
};

export type CaseDetail = CaseRow & {
    summary: string | null;
    unit: string | null;
    position: string | null;
    summons: { served_at: string | null; method: string | null; hearing_at: string | null; hearing_location: string | null };
    in_absentia: boolean;
    additional_evidence_required: boolean;
    final_statement: { text: string | null; declined: boolean; at: string | null };
    charges: Charge[];
    responses: ResponseRow[];
    evidence: EvidenceRow[];
    witnesses: WitnessRow[];
    decisions: DecisionRow[];
    suspensions: SuspensionRow[];
    deadlines: Deadline[];
};

export type Abilities = Record<
    'addCharge' | 'assignPanel' | 'fileCharge' | 'serveSummons' | 'recordResponse' | 'decideObjection' | 'proceedInAbsentia'
    | 'addEvidence' | 'recordWitness' | 'recuse' | 'finalStatement' | 'extend' | 'recommend' | 'decide' | 'deliver'
    | 'discontinue' | 'terminate' | 'close'
    | 'appealNotice' | 'appealRecordCopy' | 'appealFiling' | 'appealOutcome' | 'appealImplemented'
    | 'suspend' | 'liftSuspension' | 'suspensionBackPay',
    boolean
>;

/** What happened to the appeal so far, for the staff page and the accused's own page. */
export function AppealSummary({ appeal, stayed }: { appeal: AppealRow; stayed: boolean }): JSX.Element {
    const { t } = useLocale();
    const { date } = useDates();

    return (
        <div className="mt-2 rounded-control border border-indigo-100 bg-indigo-50/60 p-3 text-xs text-gray-700 dark:border-indigo-900 dark:bg-indigo-950/30 dark:text-slate-300">
            <p className="font-semibold text-gray-900 dark:text-slate-100">{t('discipline.appeal.title')}</p>
            {stayed && <p className="mt-1 font-medium text-indigo-800 dark:text-indigo-300">{t('discipline.appeal.stayed')}</p>}
            <dl className="mt-1 grid gap-x-4 gap-y-1 sm:grid-cols-2">
                <div><dt className="inline text-gray-500">{t('discipline.appeal.noticeReceivedAt')}: </dt><dd className="inline">{date(appeal.notice_received_at)}</dd></div>
                {appeal.record_copy_requested && (
                    <div><dt className="inline text-gray-500">{t('discipline.appeal.recordCopy')}: </dt><dd className="inline">{appeal.record_copy_delivered_at ? date(appeal.record_copy_delivered_at) : t('discipline.appeal.recordCopyPending')}</dd></div>
                )}
                {appeal.filed_on && <div><dt className="inline text-gray-500">{t('discipline.appeal.filedOn')}: </dt><dd className="inline">{date(appeal.filed_on)}{appeal.court_case_number ? ` · ${appeal.court_case_number}` : ''}</dd></div>}
                {appeal.outcome && <div><dt className="inline text-gray-500">{t('discipline.appeal.outcome')}: </dt><dd className="inline">{t(`discipline.appeal.outcomes.${appeal.outcome}`)} · {date(appeal.outcome_decided_on)}</dd></div>}
                {appeal.implemented_at && <div><dt className="inline text-gray-500">{t('discipline.appeal.implementedAt')}: </dt><dd className="inline">{date(appeal.implemented_at)}</dd></div>}
            </dl>
            {appeal.outcome_summary && <p className="mt-1 whitespace-pre-line">{appeal.outcome_summary}</p>}
        </div>
    );
}

/** Each suspension with its written reason, period and back-pay position (Art. 12). */
export function SuspensionList({ suspensions }: { suspensions: SuspensionRow[] }): JSX.Element {
    const { t } = useLocale();
    const { date } = useDates();

    return (
        <ul className="space-y-3 text-sm">
            {suspensions.map((s) => (
                <li key={s.id} className={`rounded-control border p-3 ${s.in_force ? 'border-amber-200 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/30' : 'border-gray-100 dark:border-slate-800'}`}>
                    <p className="font-medium">
                        {t(s.without_pay ? 'discipline.suspension.withoutPay' : 'discipline.suspension.withPay')}
                        {s.in_force && <span className="ms-2 text-xs font-semibold text-amber-800 dark:text-amber-300">{t('discipline.suspension.inForce')}</span>}
                    </p>
                    <p className="text-xs text-gray-600 dark:text-slate-300">{date(s.starts_on)} – {s.lifted_on ? date(s.lifted_on) : s.ends_on ? date(s.ends_on) : t('discipline.suspension.untilLifted')}</p>
                    <p className="mt-1 whitespace-pre-line">{s.reason}</p>
                    <p className="mt-1 text-xs text-gray-500">{t('discipline.suspension.servedAt')}: {date(s.served_at)} · {t(`discipline.deliveryMethods.${s.service_method}`)}{s.posting_ends_on ? ` · ${t('discipline.suspension.postedUntil')}: ${date(s.posting_ends_on)}` : ''}</p>
                    {s.lift_reason && <p className="mt-1 text-xs text-gray-500">{t('discipline.suspension.liftReason')}: {s.lift_reason}</p>}
                    {s.back_pay_status && <p className="mt-1 text-xs font-medium">{t(`discipline.suspension.backPay.${s.back_pay_status}`)}{s.back_pay_paid_on ? ` · ${date(s.back_pay_paid_on)}` : ''}</p>}
                </li>
            ))}
        </ul>
    );
}

export function useDates() {
    const { locale } = useLocale();
    const system = useCalendarSystem();

    return {
        date: (iso: string | null | undefined) => (iso ? formatDateDisplay(iso, system, locale) : '—'),
        dateTime: (iso: string | null | undefined) => (iso ? formatDateTimeDisplay(iso, system, locale) : '—'),
    };
}

export function employeeLabel(employee: EmployeeRef, locale: string): string {
    if (!employee) return '—';
    const name = locale === 'am' ? employee.name_am ?? employee.name : employee.name ?? employee.name_am;
    return [name, employee.employee_number].filter(Boolean).join(' · ');
}

export function Section({ title, children, actions }: { title: string; children: ReactNode; actions?: ReactNode }): JSX.Element {
    return (
        <section className={`${panelCls} p-4`}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h2>
                {actions}
            </div>
            {children}
        </section>
    );
}

export function Field({ id, label, error, children }: { id: string; label: string; error?: string; children: ReactNode }): JSX.Element {
    return (
        <div>
            <label htmlFor={id} className={labelCls}>{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{error}</p>}
        </div>
    );
}

export function TextArea({ id, value, onChange, rows = 3 }: { id: string; value: string; onChange: (v: string) => void; rows?: number }): JSX.Element {
    return <textarea id={id} rows={rows} className={inputCls} value={value} onChange={(e) => onChange(e.target.value)} />;
}

export function Detail({ label, children }: { label: string; children: ReactNode }): JSX.Element {
    return (
        <div>
            <dt className="text-xs text-gray-500 dark:text-slate-400">{label}</dt>
            <dd className="text-sm text-gray-900 dark:text-slate-100">{children}</dd>
        </div>
    );
}

export function CaseStatusBadge({ status }: { status: string }): JSX.Element {
    const { t } = useLocale();
    const tone = ['closed', 'decision_delivered'].includes(status) ? 'completed'
        : ['discontinued', 'terminated'].includes(status) ? 'cancelled'
            : status === 'draft' ? 'draft' : 'pending';

    return <StatusBadge status={tone} label={t(`discipline.statuses.${status}`)} />;
}

export function DeadlineList({ deadlines }: { deadlines: Deadline[] }): JSX.Element {
    const { t } = useLocale();
    const { dateTime } = useDates();
    if (deadlines.length === 0) return <p className="text-sm text-gray-500 dark:text-slate-400">—</p>;

    return (
        <ul className="divide-y divide-gray-100 dark:divide-slate-800">
            {deadlines.map((d) => (
                <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                    <div className="min-w-0">
                        <p className="text-sm text-gray-900 dark:text-slate-100">{d.label}</p>
                        <p className="text-xs text-gray-500 dark:text-slate-400">{t('discipline.deadline.due')}: {dateTime(d.due_at)}</p>
                    </div>
                    <StatusBadge
                        status={d.superseded ? 'inactive' : d.overdue ? 'overdue' : d.completed_at ? 'completed' : 'pending'}
                        label={t(`discipline.deadline.${d.superseded ? 'superseded' : d.overdue ? 'overdue' : d.completed_at ? 'completed' : 'open'}`)}
                    />
                </li>
            ))}
        </ul>
    );
}

export function ChargeList({ charges }: { charges: Charge[] }): JSX.Element {
    const { t } = useLocale();
    const { date } = useDates();

    return (
        <ol className="space-y-3">
            {charges.map((c) => (
                <li key={c.id} className="rounded-control border border-gray-100 p-3 dark:border-slate-800">
                    <p className="text-sm font-medium text-gray-900 dark:text-slate-100">{c.sequence}. {c.title}</p>
                    <p className="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-slate-300">{c.particulars}</p>
                    <dl className="mt-2 grid gap-2 sm:grid-cols-3">
                        <Detail label={t('discipline.charge.occurredOn')}>{date(c.occurred_on)}</Detail>
                        <Detail label={t('discipline.charge.occurredPlace')}>{c.occurred_place}</Detail>
                        <Detail label={t('discipline.charge.violatedProvision')}>{c.violated_provision}</Detail>
                    </dl>
                    <p className="mt-2 whitespace-pre-line text-xs text-gray-500 dark:text-slate-400">{t('discipline.charge.evidenceList')}: {c.evidence_list}</p>
                    {c.plea && (
                        <p className="mt-2 text-xs text-gray-700 dark:text-slate-300">
                            {t('discipline.charge.plea')}: <strong>{t(`discipline.pleas.${c.plea}`)}</strong>{c.plea_statement ? ` — ${c.plea_statement}` : ''}
                        </p>
                    )}
                </li>
            ))}
        </ol>
    );
}

export function penaltyLabel(t: (k: string) => string, penalty: string | null, amount: number | null, unit: string | null): string {
    if (!penalty) return '—';
    return amount && unit ? `${t(`discipline.penalties.${penalty}`)} — ${amount} ${t(`discipline.units.${unit}`)}` : t(`discipline.penalties.${penalty}`);
}

export function EvidenceList({ caseId, evidence, routeName }: { caseId: string; evidence: EvidenceRow[]; routeName: string }): JSX.Element {
    const { t } = useLocale();
    const { dateTime } = useDates();
    if (evidence.length === 0) return <p className="text-sm text-gray-500 dark:text-slate-400">—</p>;

    return (
        <ul className="divide-y divide-gray-100 dark:divide-slate-800">
            {evidence.map((e) => (
                <li key={e.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <div className="min-w-0">
                        <p className="font-medium">{e.title} <span className="text-xs font-normal text-gray-500">· {t(`discipline.sides.${e.side}`)} · {dateTime(e.created_at)}</span></p>
                        {e.description && <p className="text-xs text-gray-600 dark:text-slate-400">{e.description}</p>}
                        {e.held_by && <p className="text-xs text-gray-500">{t('discipline.evidence.heldBy')}: {e.held_by}</p>}
                    </div>
                    {e.has_file ? <a href={route(routeName, [caseId, e.id])} className={secondaryBtn}>{t('discipline.actions.download')}</a> : <span className="text-xs text-gray-400">{t('discipline.evidence.noFile')}</span>}
                </li>
            ))}
        </ul>
    );
}
