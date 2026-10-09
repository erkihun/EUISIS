import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Field, Section, inputCls, pageCls, primaryBtn, smallBtn } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type SettingField = { key: string; type: string; value: unknown; label_en: string; label_am: string; description_en: string | null; description_am: string | null; options: string[] | Record<string, string> | null; validation_rules: string[] };
type Band = { id: string; min_score: string | null; max_score: string | null; level_value: number | null; label_en: string; label_am: string | null };
type Scale = { id: string; code: string; name_en: string; name_am: string | null; type: string; is_default: boolean; bands: Band[] };

type Props = { fields: SettingField[]; scales: Scale[]; can: { update: boolean } };

/** Performance policy (System Settings group "performance") and the rating scales. */
export default function PerformanceSettings({ fields, scales, can }: Props) {
    const { t, locale } = useLocale();
    type Value = string | number | boolean | null;
    const form = useForm<Record<string, Value>>(Object.fromEntries(fields.map((f) => [f.key, (f.value ?? null) as Value])));
    const errors = form.errors as Record<string, string | undefined>;
    const text = (f: SettingField, key: 'label' | 'description') => (locale === 'am' && f[`${key}_am`]) || f[`${key}_en`];

    function submit(e: FormEvent) {
        e.preventDefault();
        form.patch(route('performance.settings.update'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    }

    function control(f: SettingField) {
        const id = `ps-${f.key}`;
        const value = form.data[f.key];
        const disabled = !can.update || form.processing;
        const limit = (rule: string) => {
            const value = f.validation_rules?.find((entry) => entry.startsWith(`${rule}:`))?.split(':')[1];
            return value !== undefined && Number.isFinite(Number(value)) ? Number(value) : undefined;
        };
        if (f.type === 'boolean') {
            return (
                <label htmlFor={id} className="flex min-h-10 cursor-pointer items-start gap-3">
                    <input id={id} type="checkbox" disabled={disabled} aria-invalid={Boolean(errors[f.key])} aria-describedby={errors[f.key] ? `${id}-error` : undefined} className="mt-0.5 h-5 w-5 rounded border-gray-300" checked={Boolean(value)} onChange={(e) => form.setData(f.key, e.target.checked)} />
                    <span>
                        <span className="text-sm font-medium text-gray-900 dark:text-slate-100">{text(f, 'label')}</span>
                        {text(f, 'description') && <span className="block text-xs text-gray-500 dark:text-slate-400">{text(f, 'description')}</span>}
                    </span>
                </label>
            );
        }
        const options = Array.isArray(f.options) ? f.options.map((o) => [o, o]) : f.options ? Object.entries(f.options) : null;
        return (
            <Field label={text(f, 'label') ?? f.key} htmlFor={id} help={text(f, 'description') ?? undefined}>
                {options ? (
                    <select id={id} disabled={disabled} aria-invalid={Boolean(errors[f.key])} aria-describedby={errors[f.key] ? `${id}-error` : undefined} className={inputCls} value={String(value ?? '')} onChange={(e) => form.setData(f.key, e.target.value)}>
                        {options.map(([v, l]) => <option key={v} value={v}>{f.key === 'checkin_frequency' ? t(`performance.enums.frequency.${v.toUpperCase()}`) : l}</option>)}
                    </select>
                ) : (
                    <input id={id} disabled={disabled} aria-invalid={Boolean(errors[f.key])} aria-describedby={errors[f.key] ? `${id}-error` : undefined} type={f.type === 'integer' ? 'number' : 'text'} min={limit('min')} max={limit('max')} step={f.type === 'integer' ? 1 : undefined} required={f.validation_rules?.includes('required')} inputMode={f.type === 'integer' ? 'numeric' : undefined} className={inputCls} value={String(value ?? '')} onChange={(e) => form.setData(f.key, f.type === 'integer' ? (e.target.value === '' ? null : Number(e.target.value)) : e.target.value)} />
                )}
            </Field>
        );
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.settings.title')} description={t('performance.settings.description')} />}>
            <Head title={t('performance.settings.title')} />
            <div className={pageCls}>
                <form onSubmit={submit}>
                    <Section title={t('performance.settings.title')}>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {fields.map((f) => (
                                <div key={f.key}>
                                    {control(f)}
                                    {errors[f.key] && <p id={`ps-${f.key}-error`} role="alert" className="mt-1 text-xs text-red-700 dark:text-red-400">{errors[f.key]}</p>}
                                </div>
                            ))}
                        </div>
                        {can.update ? <div className="mt-4 flex items-center justify-end gap-3"><span role="status" className="text-sm text-emerald-700 dark:text-emerald-400">{form.recentlySuccessful ? t('performance.settings.saved') : ''}</span><button type="submit" className={primaryBtn} disabled={form.processing || !form.isDirty}>{t('performance.actions.save')}</button></div> : <p className="mt-4 text-sm text-gray-500">{t('performance.cycles.readOnly')}</p>}
                    </Section>
                </form>

                <Section title={t('performance.settings.scales')}>
                    <div className="space-y-6">
                        {scales.map((scale) => {
                            const levels = scale.bands.some((band) => band.level_value !== null);
                            return (
                                <div key={scale.id}>
                                    <h3 className="text-sm font-medium">
                                        {scale.code} — {(locale === 'am' && scale.name_am) || scale.name_en}{' '}
                                        <span className="text-xs text-gray-500">({t(`performance.settings.scaleTypes.${scale.type}`)}{scale.is_default ? ` · ${t('performance.settings.defaultScale')}` : ''})</span>
                                    </h3>
                                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{levels ? t('performance.settings.levelsHelp') : t('performance.settings.bandsHelp')}</p>
                                    <div className={`mt-3 hidden gap-2 text-xs font-medium text-gray-500 dark:text-slate-400 sm:grid ${levels ? 'sm:grid-cols-[6rem_1fr_1fr_auto]' : 'sm:grid-cols-[6rem_6rem_1fr_1fr_auto]'}`} aria-hidden="true">
                                        {levels ? <span>{t('performance.settings.level')}</span> : <><span>{t('performance.settings.bandMin')}</span><span>{t('performance.settings.bandMax')}</span></>}
                                        <span>{t('performance.fields.nameEn')}</span>
                                        <span>{t('performance.fields.nameAm')}</span>
                                        <span />
                                    </div>
                                    <ul className="mt-1 space-y-2">{scale.bands.map((band) => <BandRow key={band.id} band={band} canEdit={can.update} />)}</ul>
                                </div>
                            );
                        })}
                    </div>
                </Section>
            </div>
        </AuthenticatedLayout>
    );
}

function BandRow({ band, canEdit }: { band: Band; canEdit: boolean }) {
    const { t } = useLocale();
    const form = useForm({ min_score: band.min_score ?? '', max_score: band.max_score ?? '', label_en: band.label_en, label_am: band.label_am ?? '' });
    const cls = `${inputCls} py-1.5`;
    // Competency bands are levels (1..N) without a score range: only their labels change.
    const level = band.level_value !== null;
    return (
        <li>
            <form onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => level
                    ? { label_en: d.label_en, label_am: d.label_am }
                    : { ...d, min_score: d.min_score === '' ? null : d.min_score, max_score: d.max_score === '' ? null : d.max_score });
                form.put(route('performance.settings.bands.update', band.id), { preserveScroll: true, onSuccess: () => form.setDefaults() });
            }}
                className={`grid grid-cols-2 items-start gap-2 ${level ? 'sm:grid-cols-[6rem_1fr_1fr_auto]' : 'sm:grid-cols-[6rem_6rem_1fr_1fr_auto]'}`}>
                {level ? (
                    <span className="flex h-full items-center text-sm font-semibold tabular-nums text-gray-700 dark:text-slate-200">{band.level_value}</span>
                ) : (
                    <>
                        <input aria-label={t('performance.settings.bandMin')} className={cls} type="number" min="0" max="200" step="0.0001" inputMode="decimal" value={form.data.min_score} disabled={!canEdit || form.processing} onChange={(e) => form.setData('min_score', e.target.value)} placeholder="—" />
                        <input aria-label={t('performance.settings.bandMax')} className={cls} type="number" min={form.data.min_score || '0'} max="200" step="0.0001" inputMode="decimal" value={form.data.max_score} disabled={!canEdit || form.processing} onChange={(e) => form.setData('max_score', e.target.value)} placeholder="—" />
                    </>
                )}
                <input aria-label={t('performance.fields.nameEn')} lang="en" maxLength={100} className={cls} value={form.data.label_en} disabled={!canEdit || form.processing} onChange={(e) => form.setData('label_en', e.target.value)} required />
                <input aria-label={t('performance.fields.nameAm')} lang="am" maxLength={100} className={cls} value={form.data.label_am} disabled={!canEdit || form.processing} onChange={(e) => form.setData('label_am', e.target.value)} />
                {canEdit && <button type="submit" className={smallBtn} disabled={form.processing || !form.isDirty}>{t('performance.actions.save')}</button>}
                {Object.values(form.errors).length > 0 && <p role="alert" className="col-span-full text-xs text-red-700 dark:text-red-400">{Object.values(form.errors).join(' ')}</p>}
                {form.recentlySuccessful && <p role="status" className="col-span-full text-xs text-emerald-700 dark:text-emerald-400">{t('performance.settings.saved')}</p>}
            </form>
        </li>
    );
}
