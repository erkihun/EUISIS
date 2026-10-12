import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { Alert, Button, FormField, Textarea, buttonClassName } from '@euisis/ui';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';
import { useState } from 'react';
import type { FormEvent, JSX } from 'react';

type Qr = {
    id: string;
    status: 'active' | 'disabled' | 'revoked';
    url: string;
    qr_svg: string | null;
    scan_count: number;
    last_scanned_at: string | null;
    enabled_at: string | null;
    disabled_at: string | null;
};

type Props = {
    organization: { id: string; code: string; name_en: string; name_am: string | null; status: string; logo_url: string | null };
    qr: Qr | null;
    history: Array<{ id: string; status: string; enabled_at: string | null; revoked_at: string | null; revocation_reason: string | null }>;
    stats: { feedback: number; suggestion: number; complaint: number };
    abilities: { view: boolean; create: boolean; export: boolean; disable: boolean; rotate: boolean };
};

/**
 * An institution's public feedback QR (brief §69). The code is stable: the
 * page never mints one on load, and renaming or restructuring the
 * institution leaves it alone. Rotate and revoke are deliberate, need a
 * written reason and the stronger permission, and are audited.
 */
export default function OrganizationFeedbackQr({ organization, qr, history, stats, abilities }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [copied, setCopied] = useState(false);
    const [pending, setPending] = useState<'rotate' | 'revoke' | null>(null);
    const reasonForm = useForm({ reason: '' });

    const name = (locale === 'am' ? organization.name_am : null) ?? organization.name_en;
    const statusLabel = (status: string): string =>
        t(status === 'active' ? 'serviceFeedback.qrActive' : status === 'disabled' ? 'serviceFeedback.qrStatusDisabled' : 'serviceFeedback.qrRevoked');

    async function copyLink() {
        if (qr === null) return;

        try {
            await navigator.clipboard.writeText(qr.url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // The URL is printed beneath the code for manual copying.
        }
    }

    function post(name: string) {
        router.post(route(name, organization.id), {}, { preserveScroll: true });
    }

    async function disable() {
        const result = await confirm({ title: t('serviceFeedback.disableQr'), variant: 'warning' });
        if (!result.confirmed) return;

        post('organizations.feedback-qr.disable');
    }

    function submitReason(event: FormEvent) {
        event.preventDefault();
        if (pending === null) return;

        reasonForm.post(route(`organizations.feedback-qr.${pending}`, organization.id), {
            preserveScroll: true,
            onSuccess: () => {
                setPending(null);
                reasonForm.reset();
            },
        });
    }

    const card = 'rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]';

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.organizationFeedbackQr')} />

            <div className="space-y-5">
                <PageHeader
                    title={t('serviceFeedback.organizationFeedbackQr')}
                    description={`${name} · ${organization.code}`}
                    backHref={route('organizations.show', organization.id)}
                />

                <div className="grid gap-5 lg:grid-cols-3">
                    <div className={`${card} p-5 text-center`}>
                        {qr === null ? (
                            <>
                                <p className="py-6 text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.orgQrNone')}</p>
                                {abilities.create && (
                                    <button
                                        type="button"
                                        onClick={() => post('organizations.feedback-qr.generate')}
                                        className={buttonClassName({ variant: 'primary', className: 'w-full' })}
                                    >
                                        {t('serviceFeedback.enableQr')}
                                    </button>
                                )}
                            </>
                        ) : (
                            <>
                                <div className="flex justify-center">
                                    <StatusBadge status={qr.status} label={statusLabel(qr.status)} />
                                </div>

                                {qr.status === 'disabled' && (
                                    <p className="mt-3 text-xs text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.orgQrDisabledNotice')}</p>
                                )}

                                {qr.qr_svg && (
                                    <img
                                        src={qr.qr_svg}
                                        alt={t('serviceFeedback.organizationFeedbackQr')}
                                        className="mx-auto mt-4 h-52 w-52 rounded-lg bg-[color:var(--app-surface)] p-2"
                                    />
                                )}

                                <p className="mt-3 break-all text-xs text-[color:var(--app-muted-foreground)]">{qr.url}</p>
                                <p className="mt-3 text-xs text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.orgQrDescription')}</p>

                                <div className="mt-4 grid gap-2">
                                    {qr.status === 'active' && (
                                        <>
                                            <div className="grid grid-cols-2 gap-2">
                                                <button type="button" onClick={copyLink} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                    {copied ? t('serviceFeedback.linkCopied') : t('serviceFeedback.copyLink')}
                                                </button>
                                                {abilities.export && (
                                                    <a href={route('organizations.feedback-qr.pdf', organization.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                        {t('serviceFeedback.printQr')}
                                                    </a>
                                                )}
                                            </div>
                                            {abilities.export && (
                                                <a href={route('organizations.feedback-qr.png', organization.id)} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                    {t('serviceFeedback.exportQrPng')}
                                                </a>
                                            )}
                                            {abilities.disable && (
                                                <button type="button" onClick={disable} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                    {t('serviceFeedback.disableQr')}
                                                </button>
                                            )}
                                        </>
                                    )}
                                    {qr.status === 'disabled' && abilities.disable && (
                                        <button
                                            type="button"
                                            onClick={() => post('organizations.feedback-qr.enable')}
                                            className={buttonClassName({ variant: 'primary', size: 'sm' })}
                                        >
                                            {t('serviceFeedback.reenableQr')}
                                        </button>
                                    )}
                                    {abilities.rotate && pending === null && (
                                        <div className="grid grid-cols-2 gap-2">
                                            <button type="button" onClick={() => setPending('rotate')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                {t('serviceFeedback.rotateQr')}
                                            </button>
                                            <button type="button" onClick={() => setPending('revoke')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                {t('serviceFeedback.revokeQr')}
                                            </button>
                                        </div>
                                    )}
                                </div>

                                {pending !== null && (
                                    <form onSubmit={submitReason} className="mt-4 space-y-3 border-t border-[color:var(--app-border)] pt-4 text-left">
                                        <Alert tone={pending === 'rotate' ? 'warning' : 'danger'}>
                                            {t(pending === 'rotate' ? 'serviceFeedback.rotateQrWarning' : 'serviceFeedback.revokeQrWarning')}
                                        </Alert>
                                        <FormField
                                            id="qr-reason"
                                            label={t('serviceFeedback.qrReason')}
                                            description={t('serviceFeedback.qrReasonHint')}
                                            error={reasonForm.errors.reason}
                                            required
                                        >
                                            {({ id, describedBy, invalid }) => (
                                                <Textarea
                                                    id={id}
                                                    aria-describedby={describedBy}
                                                    aria-invalid={invalid}
                                                    rows={3}
                                                    maxLength={500}
                                                    value={reasonForm.data.reason}
                                                    onChange={(event) => reasonForm.setData('reason', event.target.value)}
                                                />
                                            )}
                                        </FormField>
                                        <div className="flex justify-end gap-2">
                                            <Button type="button" variant="outline" size="sm" onClick={() => { setPending(null); reasonForm.reset(); }}>
                                                {t('common.cancel')}
                                            </Button>
                                            <Button type="submit" variant={pending === 'rotate' ? 'primary' : 'destructive'} size="sm" loading={reasonForm.processing}>
                                                {t(pending === 'rotate' ? 'serviceFeedback.rotateQr' : 'serviceFeedback.revokeQr')}
                                            </Button>
                                        </div>
                                    </form>
                                )}

                                <dl className="mt-4 space-y-1.5 border-t border-[color:var(--app-border)] pt-3 text-left text-xs">
                                    <div className="flex justify-between">
                                        <dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.qrEnabledAt')}</dt>
                                        <dd className="text-[color:var(--app-foreground)]"><LocalizedDateDisplay value={qr.enabled_at} withTime /></dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.lastScanned')}</dt>
                                        <dd className="text-[color:var(--app-foreground)]"><LocalizedDateDisplay value={qr.last_scanned_at} withTime /></dd>
                                    </div>
                                </dl>
                            </>
                        )}
                    </div>

                    <div className="space-y-5 lg:col-span-2">
                        <div className={`${card} p-5`}>
                            <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.submissionsAboutInstitution')}</h2>
                            <dl className="mt-4 grid gap-4 sm:grid-cols-3">
                                {([['feedback', 'typeFeedback'], ['suggestion', 'typeSuggestion'], ['complaint', 'typeComplaint']] as const).map(([key, label]) => (
                                    <div key={key} className="rounded-card border border-[color:var(--app-border)] p-4">
                                        <dt className="text-xs font-semibold text-[color:var(--app-muted-foreground)]">{t(`serviceFeedback.${label}`)}</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-[color:var(--app-foreground)]">{stats[key]}</dd>
                                    </div>
                                ))}
                            </dl>
                            <p className="mt-4 text-xs text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.qrPrintSheetHint')}</p>
                        </div>

                        <div className={card}>
                            <h2 className="border-b border-[color:var(--app-border)] px-5 py-3 text-sm font-semibold text-[color:var(--app-foreground)]">
                                {t('serviceFeedback.qrHistory')}
                            </h2>
                            {history.length === 0 ? (
                                <p className="px-5 py-6 text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.qrNoHistory')}</p>
                            ) : (
                                <ul className="divide-y divide-[color:var(--app-border)]">
                                    {history.map((item) => (
                                        <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                                            <StatusBadge status={item.status} label={statusLabel(item.status)} />
                                            <span className="text-xs text-[color:var(--app-muted-foreground)]">
                                                {t('serviceFeedback.qrEnabledAt')}: <LocalizedDateDisplay value={item.enabled_at} withTime />
                                                {item.revoked_at && <> · {t('serviceFeedback.qrRevokedAt')}: <LocalizedDateDisplay value={item.revoked_at} withTime /></>}
                                            </span>
                                            {item.revocation_reason && <span className="w-full text-xs text-[color:var(--app-foreground)]">{item.revocation_reason}</span>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
