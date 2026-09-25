import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FieldErrors, PrintedChip, inputCls, labelCls, linkBtn, primaryBtn, secondaryBtn } from './ui';

type Step = 'view' | 'enter' | 'code';

/**
 * Phone or personal email change, as three explicit steps:
 *
 *   view  -> the current value and a Change button
 *   enter -> the new value (plus a reprint warning if this field is printed)
 *   code  -> the 6-digit code sent to the NEW contact
 *
 * The record is only changed by the server after the code is confirmed; until
 * then the current value stays in force, which the copy says plainly.
 */
export default function ContactChange({ field, value, printed, l }: {
    field: 'email' | 'phone';
    value: string;
    printed: boolean;
    l: (path: string, params?: Record<string, string>) => string;
}) {
    const [step, setStep] = useState<Step>('view');
    const request = useForm({ field, value: '' });
    const confirm = useForm({ field, otp: '' });

    function sendCode(event?: React.FormEvent) {
        event?.preventDefault();
        request.post('/my-portal/profile/contact', { preserveScroll: true, onSuccess: () => setStep('code') });
    }

    function confirmCode(event: React.FormEvent) {
        event.preventDefault();
        confirm.post('/my-portal/profile/contact/confirm', {
            preserveScroll: true,
            onSuccess: () => {
                confirm.reset('otp');
                request.reset('value');
                setStep('view');
            },
        });
    }

    function cancel() {
        request.reset('value');
        request.clearErrors();
        confirm.reset('otp');
        confirm.clearErrors();
        setStep('view');
    }

    const id = `contact-${field}`;

    return (
        <div className="py-3 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-slate-400">
                        {l(`fields.${field}`)}
                        {printed && <PrintedChip label={l('printed_on_card')} />}
                    </p>
                    <p className="break-all text-sm font-medium text-gray-900 dark:text-slate-100">{value || l('not_set')}</p>
                </div>
                {step === 'view' && (
                    <button type="button" onClick={() => setStep('enter')} className={`${secondaryBtn} min-h-9 px-3 py-1.5`}>{l('change')}</button>
                )}
            </div>

            {step === 'enter' && (
                <form onSubmit={sendCode} className="mt-3 space-y-2 rounded-lg bg-gray-50 p-3 dark:bg-slate-800/50">
                    {printed && <p role="note" className="text-sm text-amber-800 dark:text-amber-300">{l('card_warning')}</p>}
                    <label htmlFor={id} className={labelCls}>{l('new_value')}</label>
                    <input
                        id={id}
                        className={inputCls}
                        type={field === 'email' ? 'email' : 'tel'}
                        inputMode={field === 'email' ? 'email' : 'tel'}
                        autoComplete={field === 'email' ? 'email' : 'tel'}
                        required
                        maxLength={255}
                        value={request.data.value}
                        onChange={(e) => request.setData('value', e.target.value)}
                    />
                    <FieldErrors errors={request.errors} />
                    <div className="flex flex-wrap gap-2 pt-1">
                        <button className={primaryBtn} disabled={request.processing || !request.data.value.trim()}>{l('send_code')}</button>
                        <button type="button" onClick={cancel} className={secondaryBtn}>{l('cancel')}</button>
                    </div>
                </form>
            )}

            {step === 'code' && (
                <form onSubmit={confirmCode} className="mt-3 space-y-2 rounded-lg bg-gray-50 p-3 dark:bg-slate-800/50">
                    <p className="text-sm text-gray-700 dark:text-slate-300">{l('code_sent_to', { value: request.data.value })}</p>
                    <label htmlFor={`${id}-code`} className={labelCls}>{l('code')}</label>
                    <input
                        id={`${id}-code`}
                        className={`${inputCls} max-w-40 text-center font-mono tracking-[0.3em]`}
                        required
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        pattern="[0-9]{6}"
                        maxLength={6}
                        value={confirm.data.otp}
                        onChange={(e) => confirm.setData('otp', e.target.value.replace(/\D/g, ''))}
                    />
                    <FieldErrors errors={confirm.errors} />
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        <button className={primaryBtn} disabled={confirm.processing || confirm.data.otp.length !== 6}>{l('confirm_code')}</button>
                        <button type="button" onClick={cancel} className={secondaryBtn}>{l('cancel')}</button>
                        <button type="button" onClick={() => sendCode()} disabled={request.processing} className={linkBtn}>{l('resend')}</button>
                    </div>
                </form>
            )}
        </div>
    );
}
