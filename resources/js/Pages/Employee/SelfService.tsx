import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import StatusBadge from '@/Components/StatusBadge';
import InfoList from '@/Components/employees/portal/InfoList';
import ProfileSection from '@/Components/employees/portal/ProfileSection';
import AccountSection, { type AccountInfo } from '@/Components/employees/portal/AccountSection';
import { usePortalLabels, type BilingualLabels } from '@/Components/employees/portal/labels';
import { Block, FieldErrors, inputCls, labelCls, linkBtn, panelCls, primaryBtn } from '@/Components/employees/portal/ui';
import type { FieldPolicy, SelfServiceCard, SelfServiceProfile } from '@/Components/employees/portal/types';
import { Link, router, useForm } from '@inertiajs/react';
import type { JSX, ReactNode } from 'react';

type Props = {
    section: string;
    profile: SelfServiceProfile;
    labels: BilingualLabels;
    field_policy: FieldPolicy;
    card: SelfServiceCard | null;
    documents: { id: string; type: string; created_at: string }[];
    corrections: { id: string; field: string; field_key?: string; status: string; created_at: string }[];
    notifications: { id: string; title: string; message: string; read: boolean; created_at: string }[];
    correction_fields?: string[];
    account?: AccountInfo | null;
};

const DEFAULT_CORRECTION_FIELDS = ['full_name', 'date_of_birth', 'national_id', 'nationality', 'employee_number', 'employment_type', 'gender'];

/**
 * My Portal self-service sections (/my-portal/{section}).
 *
 * Every section works on the signed-in employee only: no URL here carries
 * an employee id, and the server resolves the record from the account.
 */
export default function SelfService({ section, profile, labels, field_policy, card, documents, corrections, notifications, correction_fields, account }: Props): JSX.Element {
    const { l, pick } = usePortalLabels(labels);
    const title = l(section);
    const printed = (field: string) => Boolean(field_policy[field]?.card_visible);

    let content: ReactNode;
    switch (section) {
        case 'profile':
            // One profile: employee details, then how this person signs in.
            content = (
                <>
                    <ProfileSection key={JSON.stringify(profile)} profile={profile} policy={field_policy} l={l} pick={pick} />
                    {account && <AccountSection account={account} l={l} />}
                </>
            );
            break;
        case 'employment':
        case 'organization':
        case 'position':
            content = <OfficialSection section={section} profile={profile} printed={printed} l={l} pick={pick} />;
            break;
        case 'id-card':
            content = <IdCardSection card={card} l={l} />;
            break;
        case 'documents':
            content = <DocumentsSection documents={documents} l={l} />;
            break;
        case 'requests':
            content = <RequestsSection corrections={corrections} fields={correction_fields ?? DEFAULT_CORRECTION_FIELDS} l={l} />;
            break;
        case 'notifications':
            content = <NotificationsSection notifications={notifications} l={l} />;
            break;
        case 'service-tasks':
            content = (
                <p className={`${panelCls} p-4 text-sm text-gray-700 dark:text-slate-300`}>
                    {l('tasks_note')} <Link className={linkBtn} href="/my-portal/daily-activity">{l('daily-activity')} →</Link>
                </p>
            );
            break;
        default:
            content = null;
    }

    return (
        <PortalPage title={title} description={l(`section_intro.${section}`)}>
            {content}
        </PortalPage>
    );
}

type Translate = (path: string, params?: Record<string, string>) => string;

function OfficialSection({ section, profile, printed, l, pick }: {
    section: 'employment' | 'organization' | 'position';
    profile: SelfServiceProfile;
    printed: (field: string) => boolean;
    l: Translate;
    pick: (en: string | null | undefined, am: string | null | undefined) => string;
}) {
    const all = {
        employee_number: profile.employee_number ?? '',
        employment_type: pick(profile.employment_type, profile.employment_type_am),
        organization: pick(profile.organization, profile.organization_am),
        organization_unit: pick(profile.organization_unit, profile.organization_unit_am),
        position: pick(profile.position, profile.position_am),
        job_grade: profile.job_grade ?? '',
        effective_from: profile.effective_from ? <LocalizedDateDisplay value={profile.effective_from} /> : '',
    };
    const keys: (keyof typeof all)[] = section === 'organization'
        ? ['organization', 'organization_unit']
        : section === 'position'
            ? ['position', 'job_grade', 'effective_from']
            : ['employee_number', 'employment_type', 'organization', 'organization_unit', 'position', 'job_grade', 'effective_from'];

    return (
        <Block title={l('official')} badge={l('read_only')} actions={<Link href="/my-portal/requests" className={linkBtn}>{l('correction')} →</Link>}>
            <InfoList printedLabel={l('printed_on_card')} rows={keys.map((key) => ({ key, label: l(`fields.${key}`), value: all[key], printed: printed(key) }))} />
        </Block>
    );
}

function IdCardSection({ card, l }: { card: SelfServiceCard | null; l: Translate }) {
    if (!card) {
        return <p className={`${panelCls} p-4 text-sm text-gray-600 dark:text-slate-300`}>{l('no_card')}</p>;
    }

    // Field keys are named in the reader's language; never raw column names.
    const changed = (card.changed_field_keys ?? []).map((key) => l(`fields.${key}`));
    const changedText = changed.length ? changed.join(', ') : card.changed_fields.join(', ');

    return (
        <div className="space-y-4">
            {card.reprint_required && (
                <section role="status" className="rounded-panel border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                    <h2 className="text-base font-semibold">{l('reprint_required')}</h2>
                    <p className="mt-1">{l('employee_notice')}</p>
                    {changedText && <p className="mt-2"><span className="font-medium">{l('changed_fields')}:</span> {changedText}</p>}
                    <p className="mt-2 text-amber-800 dark:text-amber-300">{l('reprint_next_steps')}</p>
                </section>
            )}

            <Block title={l('id-card')}>
                <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div><dt className="text-xs text-gray-500 dark:text-slate-400">{l('card_number')}</dt><dd className="mt-0.5 font-mono text-sm text-gray-900 dark:text-slate-100">{card.card_number}</dd></div>
                    <div><dt className="text-xs text-gray-500 dark:text-slate-400">{l('status')}</dt><dd className="mt-0.5"><CardStatusBadge status={card.status} /></dd></div>
                    <div><dt className="text-xs text-gray-500 dark:text-slate-400">{l('issued_at')}</dt><dd className="mt-0.5 text-sm"><LocalizedDateDisplay value={card.issued_at} /></dd></div>
                    <div><dt className="text-xs text-gray-500 dark:text-slate-400">{l('expires_at')}</dt><dd className="mt-0.5 text-sm"><LocalizedDateDisplay value={card.expires_at} /></dd></div>
                </dl>
                {!card.snapshot_available && <p className="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-slate-800 dark:text-slate-400">{l('snapshot_unavailable')}</p>}
            </Block>
        </div>
    );
}

function DocumentsSection({ documents, l }: { documents: Props['documents']; l: Translate }) {
    if (!documents.length) {
        return <p className={`${panelCls} p-4 text-sm text-gray-500 dark:text-slate-400`}>{l('empty')}</p>;
    }

    return (
        <ul className={`${panelCls} divide-y divide-gray-100 dark:divide-slate-800`}>
            {documents.map((doc) => (
                <li key={doc.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                    <span className="min-w-0">
                        <span className="block truncate font-medium text-gray-900 dark:text-slate-100">{doc.type}</span>
                        <span className="text-xs text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={doc.created_at} /></span>
                    </span>
                    {/* Resolved against the signed-in employee's own documents on the server. */}
                    <a className={linkBtn} href={`/my-portal/documents/${doc.id}/download`}>{l('download')}</a>
                </li>
            ))}
        </ul>
    );
}

function RequestsSection({ corrections, fields, l }: { corrections: Props['corrections']; fields: string[]; l: Translate }) {
    const form = useForm({ field: fields[0] ?? 'full_name', requested_value: '' });

    return (
        <div className="space-y-4">
            <Block title={l('correction')} description={l('correction_help')}>
                <form onSubmit={(e) => { e.preventDefault(); form.post('/my-portal/requests', { preserveScroll: true, onSuccess: () => form.reset('requested_value') }); }} className="space-y-3">
                    <div>
                        <label htmlFor="cr-field" className={labelCls}>{l('field_to_correct')}</label>
                        <select id="cr-field" className={inputCls} value={form.data.field} onChange={(e) => form.setData('field', e.target.value)}>
                            {fields.map((key) => <option key={key} value={key}>{l(`fields.${key}`)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="cr-value" className={labelCls}>{l('requested_value')}</label>
                        <textarea id="cr-value" rows={3} className={inputCls} required maxLength={500} value={form.data.requested_value} onChange={(e) => form.setData('requested_value', e.target.value)} />
                    </div>
                    <FieldErrors errors={form.errors as Record<string, string | undefined>} />
                    <div className="flex justify-end">
                        <button className={`${primaryBtn} w-full sm:w-auto`} disabled={form.processing || !form.data.requested_value.trim()}>{l('correction')}</button>
                    </div>
                </form>
            </Block>

            <Block title={l('request_history')}>
                {corrections.length === 0 ? (
                    <p className="text-sm text-gray-500 dark:text-slate-400">{l('empty')}</p>
                ) : (
                    <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                        {corrections.map((item) => (
                            <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                                <span className="min-w-0">
                                    <span className="font-medium text-gray-900 dark:text-slate-100">{item.field_key ? l(`fields.${item.field_key}`) : item.field}</span>
                                    <span className="ms-2 text-xs text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={item.created_at} /></span>
                                </span>
                                <StatusBadge status={item.status} label={l(item.status)} />
                            </li>
                        ))}
                    </ul>
                )}
            </Block>
        </div>
    );
}

function NotificationsSection({ notifications, l }: { notifications: Props['notifications']; l: Translate }) {
    if (!notifications.length) {
        return <p className={`${panelCls} p-4 text-sm text-gray-500 dark:text-slate-400`}>{l('empty')}</p>;
    }

    return (
        <ul className={`${panelCls} divide-y divide-gray-100 dark:divide-slate-800`}>
            {notifications.map((item) => (
                <li key={item.id} className={`px-4 py-3 ${item.read ? '' : 'bg-[color:var(--color-primary-50)]/40 dark:bg-slate-800/40'}`}>
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {!item.read && <span className="h-2 w-2 shrink-0 rounded-full bg-[color:var(--color-primary)]" aria-label={l('unread')} />}
                            {item.title}
                        </h2>
                        <span className="text-xs text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={item.created_at} /></span>
                    </div>
                    <p className="mt-1 text-sm text-gray-700 dark:text-slate-300">{item.message}</p>
                    {!item.read && (
                        <button type="button" className={`${linkBtn} mt-1 text-xs`} onClick={() => router.post(`/my-portal/notifications/${item.id}/read`, {}, { preserveScroll: true })}>
                            {l('mark_read')}
                        </button>
                    )}
                </li>
            ))}
        </ul>
    );
}
