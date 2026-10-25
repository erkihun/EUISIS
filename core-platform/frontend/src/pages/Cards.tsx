import { useState, type FormEvent } from 'react';
import { api, firstError } from '../api';
import { useAuth } from '../auth';
import { ListPage } from '../components/ListPage';
import { buttonClass, Field, inputClass, Modal, Notice, StatusBadge } from '../components/ui';
import { useI18n } from '../i18n';
import type { IdCard } from '../types';

type Transition = 'suspend' | 'reinstate' | 'revoke';

/** Which change each status allows; the API enforces the same table. */
const allowed: Record<string, Transition[]> = {
    active: ['suspend', 'revoke'],
    issued: ['suspend', 'revoke'],
    suspended: ['reinstate', 'revoke'],
    printed: ['revoke'],
    pending_print: ['revoke'],
};

export function Cards() {
    const { t, status, date } = useI18n();
    const { user } = useAuth();
    const [pending, setPending] = useState<{ card: IdCard; transition: Transition } | null>(null);
    const [done, setDone] = useState(false);
    const [reload, setReload] = useState(0);

    return (
        <div className="space-y-4">
            {done && <Notice kind="ok">{t('card.done')}</Notice>}
            <ListPage<IdCard>
                title={t('nav.cards')}
                endpoint="/v1/id-cards"
                reloadKey={reload}
                filters={[{ name: 'status', label: t('common.status'), options: ['active', 'issued', 'suspended', 'revoked'].map((value) => ({ value, label: status(value) })) }]}
                columns={[
                    { label: t('card.number'), render: (row) => <span className="font-mono text-xs">{row.card_number}</span> },
                    {
                        label: t('card.holder'),
                        render: (row) => (
                            <a href={`#/employees/${row.employee_id}`} className="text-brand hover:underline">
                                {row.employee?.full_name ?? '—'}
                            </a>
                        ),
                    },
                    { label: t('card.issued'), render: (row) => date(row.issued_at) },
                    { label: t('card.expires'), render: (row) => date(row.expires_at) },
                    { label: t('common.status'), render: (row) => <StatusBadge value={row.status} /> },
                    ...(user?.can_change_master_data
                        ? [
                              {
                                  label: t('common.actions'),
                                  render: (row: IdCard) => (
                                      <div className="flex flex-wrap gap-1">
                                          {(allowed[row.status] ?? []).map((transition) => (
                                              <button key={transition} type="button" className={buttonClass.small} onClick={() => (setDone(false), setPending({ card: row, transition }))}>
                                                  {t(`card.${transition}`)}
                                              </button>
                                          ))}
                                      </div>
                                  ),
                              },
                          ]
                        : []),
                ]}
            />
            {pending && (
                <CardChange
                    card={pending.card}
                    transition={pending.transition}
                    onClose={() => setPending(null)}
                    onDone={() => {
                        setPending(null);
                        setDone(true);
                        setReload((value) => value + 1);
                    }}
                />
            )}
        </div>
    );
}

export function CardChange({ card, transition, onClose, onDone }: { card: IdCard; transition: Transition; onClose: () => void; onDone: () => void }) {
    const { t } = useI18n();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await api(`/v1/id-cards/${card.id}/${transition}`, { method: 'POST', body: { reason } });
            onDone();
        } catch (caught) {
            setError(firstError(caught) ?? t('common.error'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal title={t('card.confirm', { action: t(`card.${transition}`), number: card.card_number })} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label={t('common.reason')} help={t('card.reasonHelp')} error={error}>
                    <textarea required minLength={5} maxLength={1000} rows={3} value={reason} onChange={(event) => setReason(event.target.value)} className={inputClass} />
                </Field>
                <div className="flex justify-end gap-2">
                    <button type="button" className={buttonClass.secondary} onClick={onClose}>
                        {t('common.cancel')}
                    </button>
                    <button type="submit" disabled={busy} className={transition === 'reinstate' ? buttonClass.primary : buttonClass.danger}>
                        {busy ? t('common.saving') : t(`card.${transition}`)}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
