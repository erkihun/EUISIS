import { useState, type FormEvent } from 'react';
import { firstError } from '../api';
import { useAuth } from '../auth';
import { buttonClass, Field, inputClass, Notice } from '../components/ui';
import { useI18n } from '../i18n';

export function Login() {
    const { login } = useAuth();
    const { t, toggle } = useI18n();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await login(email, password);
        } catch (caught) {
            setError(firstError(caught) ?? t('common.error'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-canvas px-4">
            <div className="w-full max-w-sm space-y-6">
                <div className="text-center">
                    <h1 className="text-2xl font-semibold text-brand">{t('app.name')}</h1>
                    <p className="mt-1 text-sm text-muted">{t('app.tagline')}</p>
                </div>
                <form onSubmit={submit} className="space-y-4 rounded-lg border border-line bg-surface p-6">
                    {error && <Notice kind="error">{error}</Notice>}
                    <Field label={t('auth.email')}>
                        <input type="email" autoComplete="username" required value={email} onChange={(event) => setEmail(event.target.value)} className={inputClass} />
                    </Field>
                    <Field label={t('auth.password')}>
                        <input type="password" autoComplete="current-password" required value={password} onChange={(event) => setPassword(event.target.value)} className={inputClass} />
                    </Field>
                    <button type="submit" disabled={busy} className={`${buttonClass.primary} w-full`}>
                        {busy ? t('auth.signingIn') : t('auth.signIn')}
                    </button>
                </form>
                <div className="text-center">
                    <button type="button" onClick={toggle} className="text-xs text-muted underline">
                        {t('lang.switch')}
                    </button>
                </div>
            </div>
        </div>
    );
}
