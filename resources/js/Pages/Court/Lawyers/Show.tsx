import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, Card, CardTitle, badgeClassName, cx } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';

interface LawyerDetail {
    id: number;
    name: string;
    first_name: string;
    middle_name: string;
    last_name: string;
    gender: string | null;
    national_id_number: string;
    email: string;
    phone: string;
    address: string;
    license_number: string | null;
    license_url: string | null;
    license_missing: boolean;
    applied_at: string | null;
    created_at: string | null;
    verified: boolean;
    verified_at: string | null;
    verified_by: string | null;
    refused: boolean;
    refused_at: string | null;
    refusal_reason: string | null;
    active: boolean;
    email_confirmed: boolean;
}

interface RepresentedCase {
    id: number;
    case_number: string;
    case_status: string;
    status: string;
    client: string;
}

interface LawyersShowProps {
    lawyer: LawyerDetail;
    cases: RepresentedCase[];
    canManage: boolean;
}

const REPRESENTATION_TONES: Record<string, 'success' | 'warning' | 'danger' | 'neutral'> = { accepted: 'success', pending: 'warning', refused: 'danger', revoked: 'neutral' };

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-[0.16em] text-(--app-muted-foreground)">{label}</dt>
            <dd className="mt-1 break-words font-medium text-(--app-foreground)">{children}</dd>
        </div>
    );
}

/**
 * One lawyer in full (Admin\LawyerController@show): the registry checks who
 * they are and reads the licence beside the licence number before it verifies
 * the account or refuses the application. The decisions taken so far and the
 * cases the lawyer represents are shown with it.
 */
export default function LawyersShow({ lawyer, cases, canManage }: LawyersShowProps) {
    const { t } = useLocale();
    const r = (key: string) => t(`cm.lawyer.register.${key}`);
    const known = (key: string, fallback: string) => { const value = t(key); return value === key ? fallback : value; };
    const { confirm } = useConfirm();
    const [busy, setBusy] = useState(false);
    const waiting = Boolean(lawyer.applied_at) && !lawyer.verified && !lawyer.refused;
    const dash = <span className="text-(--app-muted-foreground)">—</span>;

    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
    const setVerified = async (verified: boolean) => {
        let license = lawyer.license_number ?? '';
        if (verified && license === '') {
            const result = await confirm({ title: `${r('verify')}: ${lawyer.name}`, confirmLabel: r('verify'), cancelLabel: r('cancel'), requireReason: true, reasonLabel: r('license_prompt') });
            if (!result.confirmed) return;
            license = (result.reason ?? '').trim();
        } else {
            const result = await confirm({
                title: `${verified ? r('verify') : r('unverify')}: ${lawyer.name}`, variant: verified ? 'success' : 'warning',
                confirmLabel: verified ? r('verify') : r('unverify'), cancelLabel: r('cancel'), description: verified && waiting ? r('approve_help') : undefined,
            });
            if (!result.confirmed) return;
        }
        router.patch(route('court.lawyers.verify', lawyer.id), { verified, license_number: license }, options);
    };
    const refuse = async () => {
        const result = await confirm({ title: `${r('refuse')}: ${lawyer.name}`, variant: 'danger', confirmLabel: r('refuse'), cancelLabel: r('cancel'), requireReason: true, reasonLabel: r('refusal_reason') });
        if (!result.confirmed) return;
        router.patch(route('court.lawyers.refuse', lawyer.id), { reason: (result.reason ?? '').trim() }, options);
    };

    const status = lawyer.refused
        ? <span className={badgeClassName('danger')}>{r('refused')}</span>
        : <span className={badgeClassName(lawyer.verified ? 'success' : 'warning')}>{lawyer.verified ? r('verified') : waiting ? r('waiting') : r('unverified')}</span>;

    return (
        <AuthenticatedLayout header={<PageHeader title={lawyer.name} description={r('detail_description')} backHref={route('court.lawyers.index')} />}>
            <Head title={`${r('title')}: ${lawyer.name}`} />

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
                <div className="space-y-6 xl:col-span-2">
                    <Card>
                        <div className="flex flex-wrap items-center gap-2">
                            {status}
                            {!lawyer.active && !lawyer.refused && <span className={badgeClassName('neutral')}>{r('inactive')}</span>}
                        </div>
                        {canManage && (
                            <div className="mt-4 space-y-3 border-t border-(--app-border) pt-4">
                                {waiting && <p className="text-sm text-(--app-muted-foreground)">{r('check_before')}</p>}
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" variant={lawyer.verified ? 'destructiveOutline' : 'primary'} disabled={busy} onClick={() => { void setVerified(!lawyer.verified); }}>
                                        {lawyer.verified ? r('unverify') : r('verify')}
                                    </Button>
                                    {waiting && (
                                        <Button type="button" variant="destructiveOutline" disabled={busy} onClick={() => { void refuse(); }}>{r('refuse')}</Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </Card>

                    <Card>
                        <CardTitle className="mb-4 border-b border-(--app-border) pb-3 text-base">{r('personal')}</CardTitle>
                        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label={r('first_name')}>{lawyer.first_name || dash}</Field>
                            <Field label={r('middle_name')}>{lawyer.middle_name || dash}</Field>
                            <Field label={r('last_name')}>{lawyer.last_name || dash}</Field>
                            <Field label={r('gender')}>{lawyer.gender ? r(lawyer.gender) : dash}</Field>
                            <Field label={r('national_id')}><span className="font-mono text-sm">{lawyer.national_id_number || '—'}</span></Field>
                            <Field label={r('phone')}>{lawyer.phone ? <a href={`tel:${lawyer.phone}`} className="hover:underline">{lawyer.phone}</a> : dash}</Field>
                            <div className="sm:col-span-2"><Field label={r('email')}>{lawyer.email ? <a href={`mailto:${lawyer.email}`} className="hover:underline">{lawyer.email}</a> : dash}</Field></div>
                            <div className="sm:col-span-2"><Field label={r('address')}>{lawyer.address || dash}</Field></div>
                        </dl>
                    </Card>

                    <Card>
                        <CardTitle className="mb-4 border-b border-(--app-border) pb-3 text-base">{r('decisions')}</CardTitle>
                        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label={r('license_number')}><span className="font-mono text-sm">{lawyer.license_number ?? '—'}</span></Field>
                            <Field label={lawyer.applied_at ? r('applied') : r('opened_by_registry')}>
                                {lawyer.applied_at ? <LocalizedDateDisplay value={lawyer.applied_at} withTime /> : lawyer.created_at ? <LocalizedDateDisplay value={lawyer.created_at} withTime /> : dash}
                            </Field>
                            {lawyer.verified && (
                                <Field label={r('verified')}>
                                    {lawyer.verified_at ? <LocalizedDateDisplay value={lawyer.verified_at} withTime /> : dash}
                                    {lawyer.verified_by && <span className="block text-xs font-normal text-(--app-muted-foreground)">{lawyer.verified_by}</span>}
                                </Field>
                            )}
                            {lawyer.refused && (
                                <>
                                    <Field label={r('refused')}>{lawyer.refused_at ? <LocalizedDateDisplay value={lawyer.refused_at} withTime /> : dash}</Field>
                                    <div className="sm:col-span-2"><Field label={r('refusal_reason_label')}>{lawyer.refusal_reason || dash}</Field></div>
                                </>
                            )}
                            <Field label={r('email_confirmed')}>{lawyer.email_confirmed ? r('yes') : r('no')}</Field>
                        </dl>
                    </Card>
                </div>

                <div className="space-y-6 xl:col-span-3">
                    <Card>
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-2 border-b border-(--app-border) pb-3">
                            <CardTitle className="text-base">{r('license_section')}</CardTitle>
                            {lawyer.license_url && (
                                <a href={lawyer.license_url} target="_blank" rel="noopener noreferrer" className="text-sm font-medium text-(--app-primary) hover:underline">{r('open_pdf')}</a>
                            )}
                        </div>
                        {lawyer.license_url
                            ? <iframe src={lawyer.license_url} title={`${r('license_section')}: ${lawyer.name}`} className="h-[70vh] min-h-[420px] w-full rounded-card border border-(--app-border) bg-(--app-surface-muted)" />
                            : <p className="text-sm text-(--app-muted-foreground)">{lawyer.license_missing ? r('license_missing') : r('no_license')}</p>}
                    </Card>

                    <Card>
                        <CardTitle className="mb-4 border-b border-(--app-border) pb-3 text-base">{r('cases_title')}</CardTitle>
                        {cases.length === 0 ? (
                            <p className="text-sm text-(--app-muted-foreground)">{r('no_cases')}</p>
                        ) : (
                            <ul className="divide-y divide-(--app-border)">
                                {cases.map((row) => (
                                    <li key={`${row.id}-${row.client}`} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                                        <div className="min-w-0">
                                            <Link href={route('court.cases.show', row.id)} className="font-mono font-medium text-(--app-primary) hover:underline">{row.case_number}</Link>
                                            {row.client && <span className="ms-2 text-(--app-muted-foreground)">{row.client}</span>}
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            <span className={badgeClassName('neutral')}>{known(`cm.cases.status.${row.case_status}`, row.case_status)}</span>
                                            <span className={cx(badgeClassName(REPRESENTATION_TONES[row.status] ?? 'neutral'))}>{known(`cm.case_parties.panel.representation.${row.status}`, row.status)}</span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
