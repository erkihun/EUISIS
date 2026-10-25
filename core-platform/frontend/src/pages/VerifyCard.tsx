import { useState, type FormEvent } from 'react';
import { api, ApiError } from '../api';
import { buttonClass, Field, inputClass, Notice } from '../components/ui';
import { useI18n } from '../i18n';

type Verdict = { valid: boolean; status: string; employee_number?: string; employee_name?: string };

export function VerifyCard() {
    const { t, status } = useI18n();
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
            else setError(caught instanceof ApiError ? (Object.values(caught.errors)[0]?.[0] ?? caught.message) : t('common.error'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="max-w-xl space-y-4">
            <h1 className="text-xl font-semibold text-ink">{t('nav.verify')}</h1>
            <form onSubmit={submit} className="space-y-4 rounded-lg border border-line bg-surface p-5">
                <Field label={t('verify.uuid')} help={t('verify.help')} error={error}>
                    <input required value={reference} onChange={(event) => setReference(event.target.value)} placeholder="00000000-0000-0000-0000-000000000000" className={`${inputClass} font-mono`} />
                </Field>
                <button type="submit" disabled={busy} className={buttonClass.primary}>
                    {t('verify.check')}
                </button>
            </form>

            {verdict === 'not_found' && <Notice kind="error">{t('verify.notFound')}</Notice>}
            {verdict && verdict !== 'not_found' && (
                <div className={`rounded-lg border p-5 ${verdict.valid ? 'border-ok/40 bg-ok-soft' : 'border-danger/40 bg-danger-soft'}`} role="status">
                    <p className={`text-lg font-semibold ${verdict.valid ? 'text-ok' : 'text-danger'}`}>{verdict.valid ? t('verify.valid') : t('verify.invalid')}</p>
                    <p className="mt-2 text-sm text-ink">
                        {verdict.employee_name} · <span className="font-mono text-xs">{verdict.employee_number}</span>
                    </p>
                    <p className="text-sm text-muted">
                        {t('common.status')}: {status(verdict.status)}
                    </p>
                </div>
            )}
        </div>
    );
}
