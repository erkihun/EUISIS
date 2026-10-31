import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type ComponentProps, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import DashboardTabs, { type DashboardTab } from '@/Components/dashboard/DashboardTabs';
import { EyeIcon, LogOut, MonitorIcon } from '@/Components/Icons';
import { Alert, Button, Card, FormField, Input, Select, badgeClassName, buttonClassName } from '@/Components/ui';
import { useRespondentText } from '@/court/i18n/useRespondentText';
import { formatRelative } from '@/lib/relativeTime';
import type { RespondentProfileProps, RespondentProfileTab, RespondentSession } from '@/court/types/respondent';

type ProfileData = {
    first_name: string; middle_name: string; last_name: string; gender: string; phone: string; national_id_number: string;
    email: string; address: string; position: string; organization_name: string; lawyer_document: File | null;
};

/** "1234 5678 9012 3456" while typing, as the Blade field formatted it; the server keeps the digits. */
function formatNationalId(value: string): string {
    const cleaned = value.replace(/[^A-Za-z0-9]/g, '').slice(0, 16).toUpperCase();
    return (cleaned.match(/.{1,4}/g) ?? []).join(' ');
}

/**
 * The respondent's profile in three tabs: Profile (the account details, kept in step with the respondent
 * record), Security (the password) and Sessions (the devices signed in, each of which can be signed out).
 * The sessions live on their own route, so that tab and the other two switch by visiting it.
 */
export default function RespondentProfile({ user, tab, sessions, sessionsSupported, status, securitySuccess }: RespondentProfileProps) {
    const { t, locale } = useRespondentText();
    const onSessions = sessions !== null;
    const [active, setActive] = useState<RespondentProfileTab>(tab);
    const [busy, setBusy] = useState<string | null>(null);

    // A Blade link to …/profile#security still opens the security tab.
    useEffect(() => {
        if (!onSessions && window.location.hash === '#security') setActive('security');
    }, [onSessions]);

    const tabs: DashboardTab[] = [
        { id: 'profile', label: t('cm.auth.profile_section') },
        { id: 'security', label: t('cm.auth.security_section') },
        { id: 'devices', label: onSessions ? `${t('cm.auth.sessions_title')} (${sessions.length})` : t('cm.auth.sessions_title') },
    ];

    const changeTab = (id: string) => {
        const next = id as RespondentProfileTab;
        if (next === 'devices' && !onSessions) {
            router.get(route('court.respondent.profile.sessions.index'));
        } else if (next !== 'devices' && onSessions) {
            router.get(route('court.respondent.profile.edit'), next === 'security' ? { tab: 'security' } : {});
        } else {
            setActive(next);
        }
    };

    const profile = useForm<ProfileData>({
        first_name: user.first_name, middle_name: user.middle_name, last_name: user.last_name, gender: user.gender ?? '',
        phone: user.phone, national_id_number: formatNationalId(user.national_id_number), email: user.email, address: user.address,
        position: user.position, organization_name: user.organization_name, lawyer_document: null,
    });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });
    const profileErrors = Object.values(profile.errors).filter(Boolean);

    const saveProfile = (event: FormEvent) => {
        event.preventDefault();
        if (profile.data.lawyer_document) {
            // A file needs a multipart POST with _method.
            profile.transform((data) => ({ ...data, _method: 'patch' }));
            profile.post(route('court.respondent.profile.update'), { forceFormData: true, preserveScroll: true });
        } else {
            profile.transform(({ lawyer_document: _file, ...data }) => data);
            profile.patch(route('court.respondent.profile.update'), { preserveScroll: true });
        }
    };

    const savePassword = (event: FormEvent) => {
        event.preventDefault();
        password.patch(route('court.respondent.profile.password'), { preserveScroll: true, onSuccess: () => password.reset() });
    };

    const revoke = (session: RespondentSession) => {
        router.delete(route('court.respondent.profile.sessions.destroy', session.id), {
            preserveScroll: true, onStart: () => setBusy(session.id), onFinish: () => setBusy(null),
        });
    };
    const revokeOthers = () => {
        router.delete(route('court.respondent.profile.sessions.destroyOthers'), {
            preserveScroll: true, onStart: () => setBusy('others'), onFinish: () => setBusy(null),
        });
    };

    const text = (field: Exclude<keyof ProfileData, 'gender' | 'lawyer_document'>, label: string, autoComplete: string, extra: Partial<ComponentProps<typeof Input>> = {}) => (
        <FormField label={label} error={profile.errors[field]}>
            {({ id, describedBy, invalid }) => (
                <Input id={id} name={field} value={profile.data[field]} autoComplete={autoComplete} aria-describedby={describedBy} aria-invalid={invalid}
                    onChange={(event) => profile.setData(field, event.target.value)} {...extra} />
            )}
        </FormField>
    );

    const others = (sessions ?? []).filter((session) => !session.is_current);
    const title = t('cm.auth.my_profile');

    return (
        <AuthenticatedLayout contentWidth="form" header={(
            <PageHeader title={onSessions ? t('cm.auth.sessions_title') : title} description={onSessions ? t('cm.auth.sessions_hint') : t('cm.auth.fill_basic_info')} actions={(
                <Link href={route('court.respondent.dashboard')} className={buttonClassName({ variant: 'outline' })}>{t('cm.auth.back')}</Link>
            )} />
        )}>
            <Head title={onSessions ? t('cm.auth.sessions_title') : title} />

            <div className="space-y-6">
                <DashboardTabs tabs={tabs} activeId={active} onChange={changeTab} label={t('cm.auth.profile_section')} idPrefix="respondent-profile" />

                {active === 'profile' && !onSessions && (
                    <div role="tabpanel" id="respondent-profile-panel-profile" aria-labelledby="respondent-profile-tab-profile" className="space-y-4">
                        {profileErrors.length > 0 && (
                            <Alert tone="danger" title={t('cm.auth.please_fix_errors')}>
                                <ul className="ml-5 list-disc space-y-0.5">{profileErrors.map((message) => <li key={message}>{message}</li>)}</ul>
                            </Alert>
                        )}
                        <form id="profile" onSubmit={saveProfile}>
                            <Card className="space-y-6">
                                <section className="space-y-4">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="font-semibold text-(--app-foreground)">{t('cm.auth.basic_information')}</h2>
                                        <span className={badgeClassName('info')}>{t('cm.auth.profile_basic_info')}</span>
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-3">
                                        {text('first_name', t('cm.auth.first_name'), 'given-name')}
                                        {text('middle_name', t('cm.auth.middle_name'), 'additional-name')}
                                        {text('last_name', t('cm.auth.last_name'), 'family-name')}
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-3">
                                        <FormField label={t('cm.auth.gender')} error={profile.errors.gender}>
                                            {({ id, describedBy, invalid }) => (
                                                <Select id={id} name="gender" value={profile.data.gender} aria-describedby={describedBy} aria-invalid={invalid}
                                                    onChange={(event) => profile.setData('gender', event.target.value)}>
                                                    <option value="">— {t('cm.auth.select_option')} —</option>
                                                    <option value="male">{t('cm.auth.male')}</option>
                                                    <option value="female">{t('cm.auth.female')}</option>
                                                </Select>
                                            )}
                                        </FormField>
                                        {text('phone', t('cm.auth.phone'), 'tel', { inputMode: 'tel' })}
                                        <FormField label={t('cm.auth.national_id')} required error={profile.errors.national_id_number} description={t('cm.auth.national_id_hint')}>
                                            {({ id, describedBy, invalid }) => (
                                                <Input id={id} name="national_id_number" value={profile.data.national_id_number} required inputMode="numeric" autoComplete="off"
                                                    maxLength={19} pattern="\d{4}\s\d{4}\s\d{4}\s\d{4}" title={t('cm.auth.national_id_format')} placeholder={t('cm.auth.national_id_placeholder')}
                                                    aria-describedby={describedBy} aria-invalid={invalid}
                                                    onChange={(event) => profile.setData('national_id_number', formatNationalId(event.target.value))} />
                                            )}
                                        </FormField>
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        {text('email', t('cm.auth.email'), 'email', { type: 'email' })}
                                        {text('address', t('cm.auth.address'), 'street-address')}
                                        {text('position', t('cm.auth.position'), 'organization-title')}
                                        {text('organization_name', t('cm.auth.organization_name'), 'organization')}
                                    </div>
                                </section>

                                {user.is_lawyer && (
                                    <section className="space-y-3 border-t border-(--app-border) pt-6">
                                        <h2 className="font-semibold text-(--app-foreground)">{t('cm.auth.lawyer_document')}</h2>
                                        {user.lawyer_document_url && (
                                            <Alert tone="success">
                                                <div className="flex flex-wrap items-center justify-between gap-3">
                                                    <span className="font-medium">{t('cm.auth.lawyer_document_uploaded')}</span>
                                                    <a href={user.lawyer_document_url} target="_blank" rel="noopener noreferrer" className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                                        <EyeIcon className="h-4 w-4" />{t('cm.cases.show.view_lawyer_document')}
                                                    </a>
                                                </div>
                                            </Alert>
                                        )}
                                        <FormField label={user.lawyer_document_url ? t('cm.auth.lawyer_document_replace') : t('cm.auth.lawyer_document')} error={profile.errors.lawyer_document} description={t('cm.auth.lawyer_document_hint')}>
                                            {({ id, describedBy, invalid }) => (
                                                <Input id={id} type="file" accept=".pdf" aria-describedby={describedBy} aria-invalid={invalid}
                                                    className="cursor-pointer py-1.5 file:mr-3 file:border-0 file:bg-transparent file:text-sm file:font-medium"
                                                    onChange={(event) => profile.setData('lawyer_document', event.target.files?.[0] ?? null)} />
                                            )}
                                        </FormField>
                                    </section>
                                )}

                                <div className="flex flex-wrap items-center gap-2 border-t border-(--app-border) pt-4">
                                    <Button type="submit" loading={profile.processing}>{t('cm.auth.save_changes')}</Button>
                                    <Link href={route('court.respondent.dashboard')} className={buttonClassName({ variant: 'outline' })}>{t('cm.auth.back')}</Link>
                                </div>
                            </Card>
                        </form>
                    </div>
                )}

                {active === 'security' && !onSessions && (
                    <div role="tabpanel" id="respondent-profile-panel-security" aria-labelledby="respondent-profile-tab-security" className="space-y-4">
                        {securitySuccess && <Alert tone="success">{securitySuccess}</Alert>}
                        <Card>
                            <h2 className="text-lg font-semibold text-(--app-foreground)">{t('cm.auth.security_section')}</h2>
                            <p className="mt-1 text-sm text-(--app-muted-foreground)">{t('cm.auth.profile.password_security_hint')}</p>
                        </Card>
                        <form id="security" onSubmit={savePassword}>
                            <Card className="space-y-5">
                                <div>
                                    <h3 className="text-base font-semibold text-(--app-foreground)">{t('cm.auth.change_password')}</h3>
                                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.auth.password_optional_hint')}</p>
                                </div>
                                <div className="grid gap-4 md:grid-cols-3">
                                    <FormField label={t('cm.auth.current_password')} required error={password.errors.current_password}>
                                        {({ id }) => <Input id={id} type="password" autoComplete="current-password" required value={password.data.current_password} onChange={(event) => password.setData('current_password', event.target.value)} />}
                                    </FormField>
                                    <FormField label={t('cm.auth.new_password')} required error={password.errors.password}>
                                        {({ id }) => <Input id={id} type="password" autoComplete="new-password" required value={password.data.password} onChange={(event) => password.setData('password', event.target.value)} />}
                                    </FormField>
                                    <FormField label={t('cm.auth.confirm_new_password')} required error={password.errors.password_confirmation}>
                                        {({ id }) => <Input id={id} type="password" autoComplete="new-password" required value={password.data.password_confirmation} onChange={(event) => password.setData('password_confirmation', event.target.value)} />}
                                    </FormField>
                                </div>
                                <div className="flex justify-end">
                                    <Button type="submit" loading={password.processing}>{t('cm.auth.save_changes')}</Button>
                                </div>
                            </Card>
                        </form>
                    </div>
                )}

                {onSessions && (
                    <div role="tabpanel" id="respondent-profile-panel-devices" aria-labelledby="respondent-profile-tab-devices" className="space-y-4">
                        {status && <Alert tone="success">{status}</Alert>}
                        {!sessionsSupported && <Alert tone="warning">{t('cm.auth.sessions_file_driver_hint')}</Alert>}
                        <Card className="p-0">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-(--app-border) px-5 py-4">
                                <div>
                                    <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.auth.active_sessions')}</h2>
                                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.auth.sessions_hint')}</p>
                                </div>
                                {others.length > 0 && (
                                    <Button variant="destructiveOutline" size="sm" icon={<LogOut className="h-4 w-4" />} loading={busy === 'others'} onClick={revokeOthers}>
                                        {t('cm.auth.revoke_other_sessions')}
                                    </Button>
                                )}
                            </div>
                            {sessions.length === 0 ? (
                                <p className="px-5 py-10 text-center text-sm text-(--app-muted-foreground)">{t('cm.auth.no_active_sessions')}</p>
                            ) : (
                                <ul className="divide-y divide-(--app-border)">
                                    {sessions.map((session) => (
                                        <li key={session.id} className="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="flex min-w-0 items-start gap-3">
                                                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-(--app-surface-muted) text-(--app-foreground)">
                                                    <MonitorIcon className="h-5 w-5" aria-hidden="true" />
                                                </span>
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h3 className="truncate text-sm font-semibold text-(--app-foreground)">{session.device_name}</h3>
                                                        {session.is_current && <span className={badgeClassName('success')}>{t('cm.auth.current_session')}</span>}
                                                    </div>
                                                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{session.browser_name} · {session.ip_address || t('cm.auth.unknown_location')}</p>
                                                    <p className="mt-1 text-xs text-(--app-muted-foreground)">{t('cm.auth.last_active')}: {formatRelative(session.last_active_at, locale)}</p>
                                                </div>
                                            </div>
                                            {!session.is_current && (
                                                <Button variant="outline" size="sm" loading={busy === session.id} onClick={() => revoke(session)}>{t('cm.auth.revoke_session')}</Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
