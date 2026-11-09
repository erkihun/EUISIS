import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Alert, Card, badgeClassName, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import AccountTabs from '@/court/components/applicant/AccountTabs';
import type { ApplicantSessionsProps } from '@/court/types/applicant';

/**
 * The applicant's signed-in sessions (the Blade applicant/profile/sessions):
 * each device and browser with its address and last activity; any session
 * but this one can be ended, or all the others at once.
 */
export default function ApplicantSessions({ sessions, supported, status }: ApplicantSessionsProps) {
    const { t } = useLocale();
    const [busy, setBusy] = useState<string | null>(null);
    const hasOthers = sessions.some((session) => !session.is_current);
    const visit = { preserveScroll: true, onFinish: () => setBusy(null) };

    const revoke = (id: string) => {
        setBusy(id);
        router.delete(route('court.applicant.profile.sessions.destroy', id), visit);
    };
    const revokeOthers = () => {
        setBusy('others');
        router.delete(route('court.applicant.profile.sessions.destroyOthers'), visit);
    };

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('cm.auth.sessions_title')} description={t('cm.auth.sessions_hint')} backHref={route('court.applicant.dashboard')} />}>
            <Head title={t('cm.auth.sessions_title')} />

            <Card>
                <AccountTabs active="sessions" />

                {status && <Alert tone="success" className="mb-5">{status}</Alert>}
                {!supported && <Alert tone="warning" className="mb-5">{t('cm.auth.sessions_file_driver_hint')}</Alert>}

                <section className="overflow-hidden rounded-card border border-(--app-border)">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-(--app-border) bg-(--app-surface-muted) px-5 py-4">
                        <div>
                            <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.auth.active_sessions')}</h2>
                            <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.auth.sessions_hint')}</p>
                        </div>
                        {hasOthers && (
                            <button type="button" onClick={revokeOthers} disabled={busy !== null} className={buttonClassName({ variant: 'destructiveOutline', size: 'sm' })}>{t('cm.auth.revoke_other_sessions')}</button>
                        )}
                    </div>
                    {sessions.length === 0 ? (
                        <p className="px-5 py-10 text-center text-sm text-(--app-muted-foreground)">{t('cm.auth.no_active_sessions')}</p>
                    ) : (
                        <ul className="divide-y divide-(--app-border)">
                            {sessions.map((session) => (
                                <li key={session.id} className="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="truncate text-sm font-semibold text-(--app-foreground)">{session.device_name}</h3>
                                            {session.is_current && <span className={badgeClassName('success')}>{t('cm.auth.current_session')}</span>}
                                        </div>
                                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{session.browser_name} · {session.ip_address ?? t('cm.auth.unknown_location')}</p>
                                        <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.auth.last_active')}: <time dateTime={session.last_active_at}>{session.last_active_relative}</time></p>
                                    </div>
                                    {!session.is_current && (
                                        <button type="button" onClick={() => revoke(session.id)} disabled={busy !== null} className={buttonClassName({ variant: 'outline', size: 'sm', className: 'shrink-0' })}>{t('cm.auth.revoke_session')}</button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Card>
        </AuthenticatedLayout>
    );
}
