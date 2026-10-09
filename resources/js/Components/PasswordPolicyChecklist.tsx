import { usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Policy = {
    min_length: number;
    max_length: number;
    history_count: number;
    blocks_personal_information: boolean;
    blocks_common_passwords: boolean;
    checks_breaches: boolean;
};

/** Same comparison copy as the server's PersonalInformation::normalize(). */
const normalize = (value: string) => value.normalize('NFKC').toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');

function personalTokens(values: (string | null | undefined)[]): string[] {
    const tokens = new Set<string>();
    values.filter((v): v is string => typeof v === 'string' && v.trim() !== '').forEach((value) => {
        const local = value.includes('@') ? value.split('@')[0] : value;
        [local, ...local.split(/[\s,.\-_+'’@]+/u)].forEach((part) => {
            const token = normalize(part);
            const minimum = /\p{Script=Ethiopic}/u.test(token) ? 2 : 4;
            if (token.length >= minimum) tokens.add(token);
        });
    });
    return [...tokens];
}

/**
 * Live, ADVISORY password checklist. The server (PasswordPolicy) decides;
 * nothing here is a security boundary. Previous-password and breach checks
 * happen only on the server, so they are listed as "checked when you save" —
 * no history or hashes ever reach the browser.
 */
export default function PasswordPolicyChecklist({
    password,
    confirmation,
    personal = [],
    className = '',
}: {
    password: string;
    /** Omit when the form has no confirmation field. */
    confirmation?: string;
    /** Name, username, email, employee number... of the account (local check only). */
    personal?: (string | null | undefined)[];
    className?: string;
}) {
    const { t } = useLocale();
    const policy = (usePage().props as { password_policy?: Policy }).password_policy;
    if (!policy) return null;

    const label = (key: string, params: Record<string, string | number> = {}) =>
        Object.entries(params).reduce((text, [name, value]) => text.replace(`{${name}}`, String(value)), t(`auth.passwordPolicy.${key}`));

    const length = [...password].length;
    const tokens = personalTokens(personal);
    const candidate = normalize(password);

    const items: { key: string; state: 'pass' | 'fail' | 'server'; text: string }[] = [
        { key: 'length', state: length >= policy.min_length && length <= policy.max_length ? 'pass' : 'fail', text: label('length', { min: policy.min_length, max: policy.max_length }) },
    ];
    if (policy.blocks_personal_information) {
        items.push({
            key: 'personal',
            state: tokens.length === 0 ? 'server' : password !== '' && !tokens.some((token) => candidate.includes(token)) ? 'pass' : 'fail',
            text: label('personal'),
        });
    }
    if (policy.blocks_common_passwords || policy.checks_breaches) {
        items.push({ key: 'common', state: 'server', text: label('common') });
    }
    if (policy.history_count > 0) {
        items.push({ key: 'history', state: 'server', text: label('history', { count: policy.history_count }) });
    }
    if (confirmation !== undefined) {
        items.push({ key: 'confirmation', state: password !== '' && password === confirmation ? 'pass' : 'fail', text: label('confirmation') });
    }

    const icon = { pass: '✓', fail: '○', server: '•' } as const;
    const tone = {
        pass: 'text-emerald-700 dark:text-emerald-400',
        fail: 'text-gray-500 dark:text-slate-400',
        server: 'text-gray-500 dark:text-slate-400',
    } as const;

    return (
        <div className={`rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs dark:border-slate-700 dark:bg-slate-800/50 ${className}`}>
            <p className="mb-2 text-gray-700 dark:text-slate-300">{label('guidance')}</p>
            <ul className="space-y-1" aria-live="polite">
                {items.map((item) => (
                    <li key={item.key} className={`flex items-start gap-2 ${tone[item.state]}`}>
                        <span aria-hidden="true" className="w-3 shrink-0 text-center font-bold">{icon[item.state]}</span>
                        <span>
                            {item.text}
                            {item.state === 'server' && <span className="text-gray-400 dark:text-slate-500"> — {label('checkedOnSave')}</span>}
                            <span className="sr-only"> ({label(`state_${item.state}`)})</span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
