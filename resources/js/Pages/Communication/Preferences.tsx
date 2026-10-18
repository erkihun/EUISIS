import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { toast } from '@/lib/toast';
import { communicationApi } from '@/lib/communication/api';
import Avatar from '@/Components/Communication/Avatar';
import type { PageProps } from '@/types';

type Prefs = { allow_direct_messages: boolean; show_online_status: boolean; show_last_seen: boolean; email_announcements: boolean; sms_emergency_announcements: boolean };
type Props = PageProps<{
    preferences: Prefs;
    system: { presence: boolean; lastSeen: boolean; emailAnnouncements: boolean; smsAnnouncements: boolean };
    blocked: { id: number; name: string }[];
}>;

export default function Preferences({ preferences, system, blocked }: Props): JSX.Element {
    const { t } = useLocale();
    const form = useForm<Prefs>(preferences);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(route('communication.preferences.update'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const unblock = (id: number) => communicationApi.unblock(id)
        .then(() => router.reload({ only: ['blocked'] }))
        .catch(() => toast.error(t('communication.errors.generic')));

    const toggle = (key: keyof Prefs, label: string, help?: string, systemOff = false) => (
        <label className={`flex items-start gap-3 py-2 ${systemOff ? 'opacity-60' : 'cursor-pointer'}`}>
            <input type="checkbox" checked={form.data[key]} disabled={systemOff || form.processing} onChange={(e) => form.setData(key, e.target.checked)}
                className="mt-0.5 h-5 w-5 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)]" />
            <span>
                <span className="text-sm font-medium text-[color:var(--app-foreground)]">{label}</span>
                {(help || systemOff) && <span className="block text-xs text-[color:var(--app-muted-foreground)]">{systemOff ? t('communication.preferences.offBySystem') : help}</span>}
            </span>
        </label>
    );

    const panel = 'rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5';

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('communication.preferences.title')} description={t('communication.preferences.description')} backHref={route('communication.index')} />}>
            <Head title={t('communication.preferences.title')} />
            <div className="space-y-6">
                <form onSubmit={submit} className="space-y-6">
                    <section className={panel}>
                        <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.preferences.sections.messages')}</h2>
                        {toggle('allow_direct_messages', t('communication.preferences.allowDirect'), t('communication.preferences.allowDirectHelp'))}
                    </section>
                    <section className={panel}>
                        <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.preferences.sections.privacy')}</h2>
                        {toggle('show_online_status', t('communication.preferences.showOnline'), undefined, !system.presence)}
                        {toggle('show_last_seen', t('communication.preferences.showLastSeen'), undefined, !system.lastSeen)}
                    </section>
                    <section className={panel}>
                        <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.preferences.sections.announcements')}</h2>
                        {toggle('email_announcements', t('communication.preferences.emailAnnouncements'), undefined, !system.emailAnnouncements)}
                        {toggle('sms_emergency_announcements', t('communication.preferences.smsAnnouncements'), undefined, !system.smsAnnouncements)}
                    </section>
                    <div className="flex items-center justify-end gap-3">
                        <span role="status" className="text-sm text-emerald-700 dark:text-emerald-400">{form.recentlySuccessful ? t('communication.settings.saved') : ''}</span>
                        <button type="submit" disabled={form.processing || !form.isDirty} className={buttonClassName({ variant: 'primary' })}>{t('communication.preferences.save')}</button>
                    </div>
                </form>

                <section className={panel}>
                    <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.preferences.sections.blocked')}</h2>
                    {blocked.length === 0 ? <p className="mt-2 text-sm text-[color:var(--app-muted-foreground)]">{t('communication.preferences.noBlocked')}</p> : (
                        <ul className="mt-2 divide-y divide-[color:var(--app-border)]">
                            {blocked.map((person) => (
                                <li key={person.id} className="flex items-center gap-3 py-2">
                                    <Avatar name={person.name} size="sm" />
                                    <span className="min-w-0 flex-1 truncate text-sm text-[color:var(--app-foreground)]">{person.name}</span>
                                    <button type="button" onClick={() => void unblock(person.id)} className={buttonClassName({ variant: 'secondary', size: 'sm' })}>{t('communication.preferences.unblock')}</button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
