import { useLocale } from '@/hooks/useLocale';
import { InputHTMLAttributes, PropsWithChildren, ReactNode, useState } from 'react';

/** Fields shared by the provider portal's sign-in and password-reset pages. */

export const ICONS = {
    user: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
    lock: 'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z',
    key: 'M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z',
    at: 'M16.5 12a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Zm0 0c0 1.657 1.007 3 2.25 3S21 13.657 21 12a9 9 0 1 0-2.636 6.364M16.5 12V8.25',
    store: 'M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z',
};

function Icon({ d, className = 'h-4 w-4' }: { d: string; className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d={d} />
        </svg>
    );
}

export function FieldError({ id, message }: { id: string; message?: string }) {
    if (!message) return null;

    return (
        <p id={id} className="mt-1.5 flex items-center gap-1 text-xs text-red-500">
            <svg className="h-3.5 w-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
            </svg>
            {message}
        </p>
    );
}

export function Notice({ tone, children }: PropsWithChildren<{ tone: 'success' | 'warning' | 'info' }>) {
    const styles = {
        success: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800/50 dark:bg-emerald-900/20 dark:text-emerald-400',
        warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-300',
        info: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800/50 dark:bg-sky-900/20 dark:text-sky-300',
    }[tone];

    return (
        <div role="status" className={`mb-5 flex items-start gap-2.5 rounded-card border px-4 py-3 text-sm ${styles}`}>
            <Icon className="mt-0.5 h-4 w-4 shrink-0" d={tone === 'success' ? 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z' : 'm11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z'} />
            <span>{children}</span>
        </div>
    );
}

type FieldProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'className'> & {
    id: string;
    label: string;
    icon: string;
    error?: string;
    /** Rendered right of the label (e.g. a "Forgot password?" link). */
    labelAside?: ReactNode;
    /** Rendered inside the input's right edge (e.g. the show-password button). */
    trailing?: ReactNode;
};

export function AuthField({ id, label, icon, error, labelAside, trailing, ...input }: FieldProps) {
    return (
        <div>
            <div className="mb-1.5 flex items-center justify-between gap-3">
                <label htmlFor={id} className="text-sm font-medium text-gray-700 dark:text-slate-300">{label}</label>
                {labelAside}
            </div>
            <div className="relative">
                <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400 dark:text-slate-500">
                    <Icon d={icon} />
                </div>
                <input
                    id={id}
                    name={id}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? `${id}-error` : undefined}
                    className={`w-full rounded-card border py-2.5 pl-10 text-sm text-gray-900 placeholder:text-gray-400 transition-colors focus:outline-none focus:ring-2 focus:ring-orange-500/40 dark:text-slate-100 dark:placeholder:text-slate-500 ${trailing ? 'pr-11' : 'pr-4'} ${
                        error
                            ? 'border-red-400 bg-red-50 dark:border-red-700 dark:bg-red-900/10'
                            : 'border-gray-300 bg-white focus:border-orange-500 dark:border-slate-700 dark:bg-slate-800'
                    }`}
                    {...input}
                />
                {trailing}
            </div>
            <FieldError id={`${id}-error`} message={error} />
        </div>
    );
}

export function PasswordField(props: Omit<FieldProps, 'icon' | 'type' | 'trailing'>) {
    const { t } = useLocale();
    const [visible, setVisible] = useState(false);

    return (
        <AuthField
            {...props}
            icon={ICONS.lock}
            type={visible ? 'text' : 'password'}
            trailing={
                <button
                    type="button"
                    onClick={() => setVisible((v) => !v)}
                    aria-label={visible ? t('auth.hidePassword') : t('auth.showPassword')}
                    aria-pressed={visible}
                    aria-controls={props.id}
                    className="absolute right-3 top-1/2 -translate-y-1/2 rounded p-0.5 text-gray-400 transition-colors hover:text-gray-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500/40 dark:text-slate-500 dark:hover:text-slate-300"
                >
                    {visible ? (
                        <Icon d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                    ) : (
                        <Icon d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                    )}
                </button>
            }
        />
    );
}

export function SubmitButton({ processing, label, processingLabel }: { processing: boolean; label: string; processingLabel: string }) {
    return (
        <button
            type="submit"
            disabled={processing}
            className="flex w-full items-center justify-center gap-2 rounded-card bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-orange-600/25 transition-all hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 disabled:opacity-60 dark:focus:ring-offset-slate-900"
        >
            {processing ? (
                <>
                    <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    {processingLabel}
                </>
            ) : (
                <>
                    {label}
                    <Icon className="h-4 w-4" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </>
            )}
        </button>
    );
}

/** The orange badge + heading + subtitle at the top of each card. */
export function CardHeading({ icon, title, subtitle }: { icon: string; title: string; subtitle: string }) {
    return (
        <div className="mb-7">
            <div className="mb-4 flex h-11 w-11 items-center justify-center rounded-panel bg-gradient-to-br from-orange-400 to-orange-600 text-white shadow-md shadow-orange-600/30">
                <Icon className="h-5 w-5" d={icon} />
            </div>
            <h2 className="text-2xl font-bold text-gray-900 dark:text-white">{title}</h2>
            <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">{subtitle}</p>
        </div>
    );
}
