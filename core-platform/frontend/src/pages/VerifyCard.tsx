import { useState, type FormEvent } from 'react';
import { ApiError, api } from '../api';
import { QrCodeIcon } from '../components/NavIcons';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useLocale } from '../i18n';
import AuthenticatedLayout from '../layouts/AuthenticatedLayout';
import { Alert, Button, Card, FormField, Input } from '../ui';

type Verdict = { valid: boolean; status: string; employee_number?: string; employee_name?: string };

/** Card Verification: validity, status, number and name only (the API's privacy-minimised answer). */
export default function VerifyCardPage() {
    const { t } = useLocale();
    const [reference, setReference] = useState('');
    const [verdict, setVerdict] = useState<Verdict | 'not_found' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        setVerdict(null);
        try {
            setVerdict(await api<Verdict>('/v1/cards/verify', { method: 'POST', body: { card_uuid: reference.trim() } }));
        } catch (caught) {
            if (caught instanceof ApiError && caught.status === 404) setVerdict('not_found');
            else setError(caught instanceof ApiError ? (Object.values(caught.errors)[0]?.[0] ?? caught.message) : t('platform.error'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('platform.verify.title')} description={t('platform.verify.description')} />}>
            <div className="max-w-2xl space-y-4">
                <Card>
                    <form onSubmit={submit} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <FormField label={t('platform.verify.reference')} required error={error} className="flex-1">
                            {({ id, invalid }) => (
                                <Input id={id} required value={reference} aria-invalid={invalid || undefined} onChange={(e) => setReference(e.target.value)} placeholder="00000000-0000-0000-0000-000000000000" className="font-mono" />
                            )}
                        </FormField>
                        <Button type="submit" variant="primary" loading={busy} icon={<QrCodeIcon className="h-4 w-4" />}>{t('platform.verify.check')}</Button>
                    </form>
                </Card>

                {verdict === 'not_found' && <Alert tone="danger">{t('platform.verify.notFound')}</Alert>}
                {verdict && verdict !== 'not_found' && (
                    <Alert tone={verdict.valid ? 'success' : 'danger'} title={verdict.valid ? t('platform.verify.valid') : t('platform.verify.invalid')}>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{verdict.employee_name}</span>
                            <span className="font-mono text-xs">{verdict.employee_number}</span>
                            <StatusBadge status={verdict.status} />
                        </div>
                    </Alert>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
