import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Button, Card, badgeClassName, buttonClassName } from '@/Components/ui';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import type { SettingsMaintenanceProps } from '@/court/types/settings';

const SECURITY_CONTROLS: [keyof SettingsMaintenanceProps['securityMetrics'], string][] = [
    ['https_enforced', 'https_protection'], ['debug_disabled', 'debug_protection'], ['secure_cookies', 'cookie_protection'],
    ['strong_password', 'password_strength'], ['strict_lockout', 'login_protection'], ['mfa_configured', 'mfa_protection'],
];

/**
 * What the EUISIS settings page has no section for, from court-ms's former
 * settings page: the database and its backup, the security status of the
 * running system, the system information and cache clearing. Linked from the
 * settings navigation.
 */
export default function SettingsMaintenance({ databaseMetrics, securityMetrics, systemInfo }: SettingsMaintenanceProps) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const s = (key: string) => t(`cm.settings.${key}`);

    const clearCaches = async () => {
        const { confirmed } = await confirm({ title: s('clear_caches'), description: s('clear_caches_confirm'), confirmLabel: s('clear_caches'), cancelLabel: t('common.cancel'), variant: 'warning' });
        if (confirmed) router.post(route('court.system-settings.clear-cache'), {}, { preserveScroll: true });
    };
    const downloadBackup = async () => {
        const { confirmed } = await confirm({ title: s('download_backup'), description: s('download_backup_confirm'), confirmLabel: s('download_backup'), cancelLabel: t('common.cancel'), variant: 'warning' });
        if (!confirmed) return;
        // A file download, not an Inertia visit: a plain form post carries the session and CSRF token.
        const post = document.createElement('form');
        post.method = 'post';
        post.action = route('court.settings.system.databaseBackup');
        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        post.appendChild(token);
        document.body.appendChild(post);
        post.submit();
        post.remove();
    };

    const section = (title: string, children: ReactNode, hint?: string, action?: ReactNode) => (
        <Card className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-(--app-foreground)">{title}</h2>
                    {hint && <p className="mt-1 text-xs text-(--app-muted-foreground)">{hint}</p>}
                </div>
                {action}
            </div>
            {children}
        </Card>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={s('maintenance')} backHref={route('court.settings.system.edit')}
            actions={<Link href={route('court.settings.system.edit')} className={buttonClassName({ variant: 'outline', size: 'sm' })}>{s('title')}</Link>} />}>
            <Head title={s('maintenance')} />
            <div className="space-y-4">
                {section(s('security_posture'), (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center gap-4">
                            <div className="text-4xl font-bold tabular-nums text-(--app-foreground)">{securityMetrics.score}%</div>
                            <div>
                                <div className="text-sm text-(--app-muted-foreground)">{s('controls_active')}</div>
                                <span className={badgeClassName(securityMetrics.score === 100 ? 'success' : 'warning')}>{securityMetrics.score === 100 ? s('protected') : s('action_recommended')}</span>
                            </div>
                        </div>
                        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {SECURITY_CONTROLS.map(([key, label]) => (
                                <li key={key} className="flex items-center justify-between gap-2 rounded-lg border border-(--app-border) px-3 py-2 text-sm">
                                    <span>{s(label)}</span>
                                    <span className={badgeClassName(securityMetrics[key] ? 'success' : 'warning')}>{securityMetrics[key] ? s('active') : s('review_needed')}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ), s('security_posture_hint'))}

                {section(s('data_overview'), (
                    <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        {([['data_driver', databaseMetrics.driver], ['data_connection', databaseMetrics.connection], ['data_database', databaseMetrics.database], ['data_tables', databaseMetrics.table_count], ['data_size', databaseMetrics.size ?? '—'], ['data_migration_batch', databaseMetrics.migration_batch ?? '—']] as [string, ReactNode][]).map(([label, item]) => (
                            <div key={label} className="rounded-lg border border-(--app-border) p-3"><dt className="text-xs text-(--app-muted-foreground)">{s(label)}</dt><dd className="mt-1 font-semibold">{item}</dd></div>
                        ))}
                    </dl>
                ), s('data_overview_hint'))}

                {section(s('backup_database'), (
                    <div className="space-y-3">
                        <p className="text-sm text-(--app-muted-foreground)">{s('backup_database_hint')}</p>
                        <Button type="button" variant="outline" disabled={!databaseMetrics.backup_supported} onClick={() => void downloadBackup()}>{s('download_backup')}</Button>
                        {!databaseMetrics.backup_supported && <p className="text-xs text-(--app-muted-foreground)">{s('backup_unsupported')}</p>}
                    </div>
                ), undefined, <span className={badgeClassName(databaseMetrics.backup_supported ? 'success' : 'warning')}>{databaseMetrics.backup_supported ? s('backup_supported') : s('backup_not_supported')}</span>)}

                {section(s('system_information'), (
                    <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        {Object.entries(systemInfo).map(([key, item]) => (
                            <div key={key} className="flex justify-between gap-3 border-b border-(--app-border) py-1.5">
                                <dt className="text-(--app-muted-foreground)">{s(key)}</dt>
                                <dd className="font-mono text-xs">{key === 'info_server_time' ? <LocalizedDateDisplay value={String(item)} withTime /> : typeof item === 'boolean' ? (item ? s('info_enabled') : s('info_disabled')) : String(item)}</dd>
                            </div>
                        ))}
                    </dl>
                ))}

                {section(s('cache_management'), <Button type="button" variant="outline" onClick={() => void clearCaches()}>{s('clear_caches')}</Button>, s('cache_hint'))}
            </div>
        </AuthenticatedLayout>
    );
}
