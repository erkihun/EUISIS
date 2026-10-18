import { useEffect, useState, type FormEvent, type JSX } from 'react';
import Modal from '@/Components/Modal';
import { useLocale } from '@/hooks/useLocale';
import { buttonClassName } from '@/Components/ui';
import { toast } from '@/lib/toast';
import { communicationApi, firstError } from '@/lib/communication/api';
import type { ReportCategory } from '@/types/communication';

const CATEGORIES: ReportCategory[] = ['HARASSMENT', 'INAPPROPRIATE_CONTENT', 'SPAM', 'SECURITY_CONCERN', 'OTHER'];

export type ReportTarget = { kind: 'message'; id: string } | { kind: 'conversation'; id: string };

/** Report a message (or a group or channel) to the moderators. */
export default function ReportDialog({ target, onClose }: { target: ReportTarget | null; onClose: () => void }): JSX.Element {
    const { t } = useLocale();
    const [category, setCategory] = useState<ReportCategory | null>(null);
    const [details, setDetails] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => { setCategory(null); setDetails(''); setError(null); }, [target]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!target || !category) return;
        setSaving(true);
        setError(null);
        try {
            await (target.kind === 'message'
                ? communicationApi.reportMessage(target.id, category, details.trim() || null)
                : communicationApi.reportConversation(target.id, category, details.trim() || null));
            toast.success(t('communication.report.done'));
            onClose();
        } catch (reason) {
            setError(firstError(reason) ?? t('communication.errors.generic'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal show={target !== null} onClose={onClose} maxWidth="md">
            <form onSubmit={submit}>
                <div className="space-y-4 px-5 py-4">
                    <div>
                        <h2 className="text-base font-semibold text-[color:var(--app-foreground)]">
                            {target?.kind === 'conversation' ? t('communication.report.conversationTitle') : t('communication.report.title')}
                        </h2>
                        <p className="mt-1 text-xs leading-relaxed text-[color:var(--app-muted-foreground)]">{t('communication.report.help')}</p>
                    </div>
                    <fieldset className="space-y-1.5">
                        <legend className="mb-1 text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.report.category')}</legend>
                        {CATEGORIES.map((value) => (
                            <label key={value} className={`flex cursor-pointer items-center gap-2.5 rounded-control border px-3 py-2 text-sm ${category === value ? 'border-[color:var(--color-primary)] bg-[color:color-mix(in_srgb,var(--color-primary)_7%,var(--app-surface))]' : 'border-[color:var(--app-border)]'} text-[color:var(--app-foreground)]`}>
                                <input type="radio" name="category" checked={category === value} onChange={() => setCategory(value)} className="h-4 w-4 text-[color:var(--color-primary)]" />
                                {t(`communication.report.categories.${value}`)}
                            </label>
                        ))}
                    </fieldset>
                    <label className="block space-y-1">
                        <span className="text-xs font-medium text-[color:var(--app-foreground)]">{t('communication.report.details')}</span>
                        <textarea value={details} onChange={(e) => setDetails(e.target.value)} maxLength={2000} rows={3}
                            className="w-full resize-none rounded-control border border-[color:var(--app-border)] bg-[color:var(--app-background)] px-2.5 py-2 text-sm text-[color:var(--app-foreground)] focus:border-[color:var(--color-primary)] focus:outline-none" />
                    </label>
                    {error && <p role="alert" className="text-xs text-[color:var(--color-danger,#b42318)]">{error}</p>}
                </div>
                <div className="flex justify-end gap-2 border-t border-[color:var(--app-border)] px-5 py-3">
                    <button type="button" onClick={onClose} className="h-9 rounded-control px-3 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]">{t('common.cancel')}</button>
                    <button type="submit" disabled={saving || !category} className={buttonClassName({ variant: 'primary' })}>
                        {t('communication.report.submit')}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
