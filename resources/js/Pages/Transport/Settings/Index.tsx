import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { Button, Card } from '@euisis/ui';
import type { FormEvent } from 'react';
import { useTransportLabels } from '@/Components/transport/labels';

type Settings = { require_pass_for_scan: boolean; allow_pay_as_you_go: boolean; scan_nonce_required: boolean };

const KEYS: (keyof Settings)[] = ['require_pass_for_scan', 'allow_pay_as_you_go', 'scan_nonce_required'];

export default function Index({ transportSettings }: { transportSettings: Settings }) {
    const { t, label } = useTransportLabels();
    const form = useForm<Settings>({ ...transportSettings });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(route('transport.settings.update'), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('transport.settings')} description={t('transport.settingsIntro')} />}>
            <Head title={t('transport.settings')} />
            <form onSubmit={submit}>
                <Card className="divide-y divide-[color:var(--app-border)] p-0">
                    {KEYS.map((key) => (
                        <label key={key} className="flex cursor-pointer items-start justify-between gap-4 p-4">
                            <span className="min-w-0">
                                <span className="block text-sm font-medium">{label('settingLabels', key)}</span>
                                <span className="mt-0.5 block text-sm text-[color:var(--app-muted-foreground)]">{label('settingHelp', key)}</span>
                                {form.errors[key] && <span className="mt-1 block text-sm text-red-600">{form.errors[key]}</span>}
                            </span>
                            <input type="checkbox" role="switch" className="mt-1 h-5 w-5 shrink-0" checked={form.data[key]} onChange={(e) => form.setData(key, e.target.checked)} />
                        </label>
                    ))}
                    <div className="flex justify-end p-4">
                        <Button type="submit" variant="primary" disabled={form.processing || !form.isDirty}>{t('transport.save')}</Button>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
