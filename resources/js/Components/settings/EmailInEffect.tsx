import SettingsCard from '@/Components/settings/SettingsCard';
import { useLocale } from '@/hooks/useLocale';
import { Alert, StatusBadge } from '@euisis/ui';

type Entry = { value: string; source: 'settings' | 'server' };

export type EmailInEffectData = {
    mailer: Entry;
    host: Entry;
    port: Entry;
    connection: Entry;
    username: Entry;
    password: Entry;
    from: Entry;
    timeout: Entry;
};

/**
 * What the mailer really uses, saved values and server fallbacks combined.
 *
 * A blank field on this page falls back to the server's .env, so the form
 * alone cannot show where mail goes. Values reflect the last save; the test
 * button then shows whether the mail server accepts them.
 */
export default function EmailInEffect({ data }: { data: EmailInEffectData }) {
    const { t } = useLocale();
    const rows: { key: keyof EmailInEffectData; display: string }[] = [
        { key: 'mailer', display: data.mailer.value },
        { key: 'host', display: data.host.value || '—' },
        { key: 'port', display: data.port.value || '—' },
        { key: 'connection', display: t(`settings.emailInEffect.connections.${data.connection.value}`) },
        { key: 'username', display: t(`settings.emailInEffect.${data.username.value}`) },
        { key: 'password', display: t(`settings.emailInEffect.${data.password.value}`) },
        { key: 'from', display: data.from.value || '—' },
        { key: 'timeout', display: data.timeout.value ? `${data.timeout.value} s` : '—' },
    ];

    return (
        <SettingsCard title={t('settings.emailInEffect.title')} description={t('settings.emailInEffect.help')}>
            {data.mailer.value !== 'smtp' && (
                <div className="px-5 pt-4">
                    <Alert tone="warning">{t('settings.emailInEffect.notSmtp').replace(':mailer', data.mailer.value)}</Alert>
                </div>
            )}
            <dl className="grid gap-x-6 gap-y-3 px-5 py-4 sm:grid-cols-2">
                {rows.map(({ key, display }) => (
                    <div key={key} className="flex min-w-0 items-start justify-between gap-3">
                        <dt className="shrink-0 text-sm text-[color:var(--app-muted-foreground)]">{t(`settings.emailInEffect.fields.${key}`)}</dt>
                        <dd className="flex min-w-0 flex-wrap items-center justify-end gap-2 text-end text-sm">
                            <span className="min-w-0 break-all font-medium text-[color:var(--app-foreground)]">{display}</span>
                            {key !== 'timeout' && (
                                <StatusBadge tone={data[key].source === 'settings' ? 'info' : 'neutral'}>
                                    {t(`settings.emailInEffect.sources.${data[key].source}`)}
                                </StatusBadge>
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
        </SettingsCard>
    );
}
