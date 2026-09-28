import ColorSettingField from '@/Components/settings/ColorSettingField';
import FieldRow, { ChoiceChips, Switch } from '@/Components/settings/FieldRow';
import ImageSettingField from '@/Components/settings/ImageSettingField';
import IdCardTemplateSettingField from '@/Components/settings/IdCardTemplateSettingField';
import SecretSettingField from '@/Components/settings/SecretSettingField';
import TimezoneSettingField from '@/Components/settings/TimezoneSettingField';
import { optionLabel } from '@/Components/settings/optionLabels';
import { useLocale } from '@/hooks/useLocale';
import type { SettingsField } from '@/lib/settings';
import { Input, Select, Textarea } from '@euisis/ui';
import { useId, useState } from 'react';

type ScalarValue = string | number | boolean | string[] | File | null;

type Props = {
    field: SettingsField;
    locale: 'en' | 'am';
    value: ScalarValue;
    error?: string;
    disabled?: boolean;
    /** Tighter spacing, for grids of switches. */
    compact?: boolean;
    onChange: (value: ScalarValue) => void;
};

/** Settings stored as lists of strings even where the registry types them as text. */
const LIST_KEYS = ['supported_locales', 'allowed_file_types', 'allowed_upload_mime_types'];

function ruleBound(field: SettingsField, rule: 'min' | 'max'): number | undefined {
    const match = field.validation_rules?.map((entry) => new RegExp(`^${rule}:(\\d+)$`).exec(entry)).find(Boolean);
    return match ? Number(match[1]) : undefined;
}

export default function SettingField({ field, locale, value, error, disabled = false, compact = false, onChange }: Props) {
    const controlId = useId();
    const labelId = `${controlId}-label`;
    const errorId = error ? `${controlId}-error` : undefined;
    const { t } = useLocale();
    const label = (locale === 'am' ? field.label_am : field.label_en) ?? field.label_en ?? field.key;
    const description = (locale === 'am' ? field.description_am : field.description_en) ?? field.description_en ?? null;
    const shared = { label, description, required: field.is_required, error, errorId };
    const lang = field.key.endsWith('_am') ? 'am' : undefined;

    if (field.key === 'template' && field.group === 'id_cards') {
        return <IdCardTemplateSettingField label={label} description={description} value={(value as string) ?? 'classic'} error={error} disabled={disabled} onChange={onChange} />;
    }

    if (field.is_encrypted || field.type === 'password') {
        return <SecretSettingField label={label} description={description} configured={field.configured} value={(value as string) ?? ''} error={error} disabled={disabled} onChange={onChange} />;
    }

    if (field.type === 'color') {
        return <ColorSettingField label={label} description={description} value={(value as string) ?? ''} error={error} disabled={disabled} onChange={onChange} />;
    }

    if (field.type === 'image' || field.type === 'file') {
        return (
            <ImageSettingField label={label} description={description} previewUrl={field.asset_url} configured={field.configured} error={error} disabled={disabled}
                accept={field.key === 'seal' ? '.png,image/png' : undefined} onChange={(file) => onChange(file)} />
        );
    }

    if (field.type === 'timezone' || field.key === 'timezone') {
        return <TimezoneSettingField label={label} description={description} value={(value as string) ?? ''} error={error} disabled={disabled} onChange={onChange} />;
    }

    if (field.type === 'boolean') {
        return (
            <FieldRow inline compact={compact} labelId={labelId} {...shared}>
                <Switch id={controlId} checked={Boolean(value)} disabled={disabled} labelledBy={labelId} describedBy={errorId} onChange={onChange} />
            </FieldRow>
        );
    }

    const list = field.type === 'json' || field.type === 'multiselect' || LIST_KEYS.includes(field.key);
    if (list) {
        const items = Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
        if (Array.isArray(field.options) && field.options.length > 0) {
            return (
                <FieldRow labelId={labelId} {...shared}>
                    <ChoiceChips labelledBy={labelId} disabled={disabled} value={items} onChange={onChange}
                        options={field.options.map((option) => ({ value: option, label: optionLabel(field.key, option, t, locale) }))} />
                </FieldRow>
            );
        }
        return (
            <FieldRow htmlFor={controlId} {...shared}>
                <ListInput id={controlId} value={items} disabled={disabled} describedBy={errorId} invalid={Boolean(error)} hint={t('settings.listHint')} onChange={onChange} />
            </FieldRow>
        );
    }

    const common = {
        id: controlId,
        disabled,
        lang,
        'aria-describedby': errorId,
        'aria-invalid': Boolean(error),
    };

    let control;
    if (field.type === 'select' && Array.isArray(field.options)) {
        control = (
            <Select {...common} value={(value as string) ?? ''} onChange={(event) => onChange(event.target.value)}>
                {field.options.map((option) => <option key={option} value={option}>{optionLabel(field.key, option, t, locale)}</option>)}
            </Select>
        );
    } else if (field.type === 'integer') {
        control = (
            <Input {...common} type="number" inputMode="numeric" min={ruleBound(field, 'min')} max={ruleBound(field, 'max')} className="max-w-[12rem] tabular-nums"
                value={value === null || value === undefined ? '' : String(value)}
                onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))} />
        );
    } else if (field.type === 'text') {
        control = <Textarea {...common} rows={3} value={(value as string) ?? ''} onChange={(event) => onChange(event.target.value)} />;
    } else {
        const type = field.type === 'email' ? 'email' : field.type === 'url' ? 'url' : field.type === 'phone' ? 'tel' : 'text';
        control = (
            <Input {...common} type={type} inputMode={type === 'tel' ? 'tel' : undefined} placeholder={type === 'url' ? 'https://' : undefined}
                value={(value as string | number | null) ?? ''} onChange={(event) => onChange(event.target.value)} />
        );
    }

    return <FieldRow htmlFor={controlId} {...shared}>{control}</FieldRow>;
}

/**
 * A free-form list typed as comma- or line-separated text. The typed text is kept
 * as-is so a trailing comma is not swallowed mid-entry; the parsed list goes up.
 */
function ListInput({ id, value, disabled, describedBy, invalid, hint, onChange }: {
    id: string;
    value: string[];
    disabled: boolean;
    describedBy?: string;
    invalid: boolean;
    hint: string;
    onChange: (value: string[]) => void;
}) {
    const [text, setText] = useState(() => value.join(', '));
    const hintId = `${id}-hint`;
    return (
        <>
            <Textarea id={id} rows={3} disabled={disabled} aria-invalid={invalid} aria-describedby={[hintId, describedBy].filter(Boolean).join(' ')}
                className="font-mono text-xs" value={text}
                onChange={(event) => {
                    setText(event.target.value);
                    onChange(event.target.value.split(/[,\n]/).map((entry) => entry.trim()).filter(Boolean));
                }} />
            <p id={hintId} className="text-xs text-[color:var(--app-muted-foreground)]">{hint}</p>
        </>
    );
}
