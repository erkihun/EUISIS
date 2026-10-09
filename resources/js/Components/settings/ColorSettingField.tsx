import FieldRow from '@/Components/settings/FieldRow';
import { Input } from '@euisis/ui';
import { useId } from 'react';

type Props = {
    label: string;
    description?: string | null;
    value: string;
    error?: string;
    disabled?: boolean;
    onChange: (value: string) => void;
};

const HEX = /^#[0-9A-F]{6}$/i;

/** A colour as a picker swatch plus its editable hex code. */
export default function ColorSettingField({ label, description, value, error, disabled = false, onChange }: Props) {
    const id = useId();
    const errorId = error ? `${id}-error` : undefined;
    const valid = HEX.test(value);

    return (
        <FieldRow htmlFor={id} label={label} description={description} error={error} errorId={errorId}>
            <div className="flex items-center gap-2">
                <label className="relative h-[var(--control-h-md)] w-12 shrink-0 cursor-pointer overflow-hidden rounded-[var(--radius-control)] border border-[color:var(--app-border-strong)] focus-within:ring-2 focus-within:ring-[color:var(--color-primary)]">
                    <span className="sr-only">{label}</span>
                    <span aria-hidden="true" className="absolute inset-1 rounded-[calc(var(--radius-control)-3px)]" style={{ backgroundColor: valid ? value : 'transparent' }} />
                    <input type="color" value={valid ? value : '#000000'} disabled={disabled} onChange={(event) => onChange(event.target.value.toUpperCase())}
                        className="absolute inset-0 h-full w-full cursor-pointer opacity-0 disabled:cursor-not-allowed" />
                </label>
                <Input id={id} value={value} disabled={disabled} maxLength={7} spellCheck={false} aria-describedby={errorId} aria-invalid={Boolean(error) || (value !== '' && !valid)}
                    onChange={(event) => onChange(event.target.value.toUpperCase())} className="max-w-[9rem] font-mono uppercase" />
            </div>
        </FieldRow>
    );
}
