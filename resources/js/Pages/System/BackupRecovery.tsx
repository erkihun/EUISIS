import { HistoryPanel, IssuesPanel, PolicyPanel, RepositoriesPanel } from '@/Components/backup/BackupDetails';
import { ComponentGrid, HealthHero } from '@/Components/backup/BackupHealthOverview';
import { OPEN_STATUSES, RequestsPanel } from '@/Components/backup/RestoreRequests';
import { toneClasses, useBackupText, type BackupHealth, type HistoryItem, type Policy, type Recovery, type RequestPermissions } from '@/Components/backup/backupUi';
import { RefreshIcon } from '@/Components/Icons';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Tabs, cx, type Tone } from '@euisis/ui';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

function TabLabel({ label, count, tone = 'neutral' }: { label: string; count?: number; tone?: Tone }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            {label}
            {count ? <span className={cx('min-w-[1.25rem] rounded-full px-1.5 text-center text-[11px] font-semibold tabular-nums', toneClasses[tone].soft, toneClasses[tone].text)}>{count}</span> : null}
        </span>
    );
}

export default function BackupRecovery({ status, history, requests, can, policy }: {
    status: BackupHealth; history: HistoryItem[]; requests: Recovery[]; can: RequestPermissions; policy: Policy;
}) {
    const { t } = useBackupText();
    const [refreshing, setRefreshing] = useState(false);
    // preserveState keeps the selected tab and form input across the re-probe.
    const refresh = () => router.post(route('backups.refresh'), {}, {
        preserveScroll: true, preserveState: true, onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false),
    });
    const critical = status.issues.filter((issue) => issue.severity === 'CRITICAL').length;
    const openRequests = requests.filter((request) => OPEN_STATUSES.includes(request.status)).length;

    return (
        <AuthenticatedLayout>
            <Head title={t('backup.title')} />
            <div className="space-y-6">
                <PageHeader title={t('backup.title')} description={t('backup.description')} actions={
                    <Button variant="outline" size="sm" loading={refreshing} icon={<RefreshIcon className="h-4 w-4" />} onClick={refresh}>{t('backup.refresh')}</Button>
                } />
                <HealthHero status={status} />
                <ComponentGrid status={status} />
                <Card>
                    <Tabs items={[
                        { id: 'issues', label: <TabLabel label={t('backup.tabs.issues')} count={status.issues.length} tone={critical ? 'danger' : 'warning'} />, content: <IssuesPanel issues={status.issues} /> },
                        { id: 'repositories', label: <TabLabel label={t('backup.tabs.repositories')} />, content: <RepositoriesPanel status={status} policy={policy} /> },
                        { id: 'requests', label: <TabLabel label={t('backup.tabs.requests')} count={openRequests} tone="info" />, content: <RequestsPanel requests={requests} can={can} /> },
                        { id: 'history', label: <TabLabel label={t('backup.tabs.history')} />, content: <HistoryPanel history={history} /> },
                        { id: 'policy', label: <TabLabel label={t('backup.tabs.policy')} />, content: <PolicyPanel status={status} policy={policy} /> },
                    ]} />
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
