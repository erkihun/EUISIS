import { useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import { ChevronDown } from '@/Components/Icons';
import { Button, Card, FormField, Input, Textarea, cx } from '@/Components/ui';
import { usePublicText } from '@/court/i18n/usePublicText';
import { SOCIAL_LINKS } from '@/court/public/CourtPublicFooter';
import { TITLED_SECTIONS } from '@/court/types/public';
import type { LandingFooter, LandingIndexProps, LandingUserManual, MetricKey, MetricText, SectionKey, SectionSetting } from '@/court/types/public';
import { PanelHeading, SwitchButton } from './LandingParts';

const visit = { preserveScroll: true } as const;

function SaveRow({ label, processing }: { label: string; processing: boolean }) {
    return (
        <div className="flex justify-end">
            <Button type="submit" disabled={processing}>{label}</Button>
        </div>
    );
}

/* ── Statistics cards ─────────────────────────────────────────────────── */

export const METRIC_KEYS: MetricKey[] = ['total_cases', 'resolved_cases', 'pending_cases', 'active_caseload', 'hearings_this_week', 'avg_resolution_time'];

/** Labels and descriptions of the home page's statistics cards; the figures stay live. */
export function MetricsPanel({ stats, metrics, metricDefaults }: Pick<LandingIndexProps, 'stats' | 'metrics' | 'metricDefaults'>) {
    const { t, locale } = usePublicText();
    const form = useForm<Record<MetricKey, MetricText>>(Object.fromEntries(METRIC_KEYS.map((key) => [key, {
        label: metrics[key]?.label ?? '', description: metrics[key]?.description ?? '',
        label_am: metrics[key]?.label_am ?? '', description_am: metrics[key]?.description_am ?? '',
    }])) as Record<MetricKey, MetricText>);
    const number = new Intl.NumberFormat(locale === 'am' ? 'am-ET' : 'en-US');
    const error = (key: MetricKey, field: keyof MetricText) => (form.errors as Record<string, string | undefined>)[`${key}.${field}`];

    const set = (key: MetricKey, field: keyof MetricText, value: string) =>
        form.setData((data) => ({ ...data, [key]: { ...data[key], [field]: value } }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('court.admin.landing.metrics.update'), visit);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <PanelHeading
                title={t('cm.admin_landing.metrics.title')}
                description={t('cm.admin_landing.metrics.description')}
                action={(
                    <span className="inline-flex w-fit items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300">
                        <span className="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true" />
                        {t('cm.admin_landing.metrics.automatic')}
                    </span>
                )}
            />

            <div className="grid gap-4 lg:grid-cols-2">
                {METRIC_KEYS.map((key) => (
                    <Card key={key} className="space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold text-(--app-foreground)">{t(`cm.home.metrics.cards.${key}.label`)}</h3>
                            <span className="rounded-lg bg-(--app-surface-muted) px-2.5 py-1 text-sm font-bold tabular-nums text-(--app-foreground)">{number.format(stats[key] ?? 0)}</span>
                        </div>
                        <FormField label={t('cm.admin_landing.metrics.display_label')} error={error(key, 'label')}>
                            {({ id }) => <Input id={id} maxLength={120} value={form.data[key].label} placeholder={metricDefaults[key].label} onChange={(event) => set(key, 'label', event.target.value)} />}
                        </FormField>
                        <FormField label={t('cm.admin_landing.metrics.description_label')} error={error(key, 'description')}>
                            {({ id }) => <Textarea id={id} rows={2} maxLength={255} value={form.data[key].description} placeholder={metricDefaults[key].description} onChange={(event) => set(key, 'description', event.target.value)} />}
                        </FormField>
                        <div className="grid gap-3 border-t border-(--app-border) pt-3 sm:grid-cols-2">
                            <FormField label={t('cm.admin_landing.metrics.am_label')} error={error(key, 'label_am')}>
                                {({ id }) => <Input id={id} lang="am" maxLength={120} value={form.data[key].label_am ?? ''} onChange={(event) => set(key, 'label_am', event.target.value)} />}
                            </FormField>
                            <FormField label={t('cm.admin_landing.metrics.am_description')} error={error(key, 'description_am')}>
                                {({ id }) => <Input id={id} lang="am" maxLength={255} value={form.data[key].description_am ?? ''} onChange={(event) => set(key, 'description_am', event.target.value)} />}
                            </FormField>
                        </div>
                    </Card>
                ))}
            </div>

            <SaveRow label={t('cm.admin_landing.metrics.save')} processing={form.processing} />
        </form>
    );
}

/* ── User manual ──────────────────────────────────────────────────────── */

/** The manual opened from the home navigation, in English and Amharic. */
export function ManualPanel({ userManual }: { userManual: LandingUserManual }) {
    const { t } = usePublicText();
    const form = useForm<LandingUserManual>(userManual);
    const m = (key: string) => t(`cm.admin_landing.manual.${key}`);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('court.admin.landing.user-manual.update'), visit);
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-card border border-primary/30 bg-primary/5 p-5">
            <PanelHeading title={m('title')} description={m('description')} />

            <div className="grid gap-4 sm:grid-cols-2">
                <FormField label={m('title_en')} required error={form.errors.title_en}>
                    {({ id }) => <Input id={id} required maxLength={255} value={form.data.title_en} onChange={(event) => form.setData('title_en', event.target.value)} />}
                </FormField>
                <FormField label={m('title_am')} required error={form.errors.title_am}>
                    {({ id }) => <Input id={id} lang="am" required maxLength={255} value={form.data.title_am} onChange={(event) => form.setData('title_am', event.target.value)} />}
                </FormField>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <RichTextEditor
                    label={m('content_en')}
                    value={form.data.content_en}
                    onChange={(html) => form.setData('content_en', html)}
                    error={form.errors.content_en}
                    preset="document"
                    locale="en"
                    minHeight={420}
                    required
                />
                <RichTextEditor
                    label={m('content_am')}
                    value={form.data.content_am}
                    onChange={(html) => form.setData('content_am', html)}
                    error={form.errors.content_am}
                    preset="document"
                    locale="am"
                    minHeight={420}
                    required
                />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-4">
                <label className="inline-flex items-center gap-2 text-sm font-medium text-(--app-foreground)">
                    <input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} className="rounded border-(--app-border-strong) text-primary" />
                    {m('show_in_nav')}
                </label>
                <Button type="submit" disabled={form.processing}>{m('save')}</Button>
            </div>
        </form>
    );
}

/* ── Footer ───────────────────────────────────────────────────────────── */

function SettingsCard({ title, children }: { title: string; children: ReactNode }) {
    return (
        <Card className="space-y-4">
            <h3 className="text-sm font-semibold text-(--app-foreground)">{title}</h3>
            {children}
        </Card>
    );
}

/** The site footer: about text, contact details and social-media links. */
export function FooterPanel({ footer }: { footer: Record<keyof LandingFooter, string> }) {
    const { t, text } = usePublicText();
    const form = useForm<Record<keyof LandingFooter, string>>({ ...footer });
    const f = (key: string) => t(`cm.admin_landing.footer.${key}`);
    const c = (key: string) => t(`cm.admin_landing.common.${key}`);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('court.admin.landing.footer.update'), visit);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <PanelHeading title={f('title')} description={f('description')} />

            <SettingsCard title={f('about')}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField label={`${f('authority')} · ${c('english')}`} description={f('authority_help')} error={form.errors.authority_name}>
                        {({ id, describedBy }) => <Input id={id} aria-describedby={describedBy} maxLength={160} value={form.data.authority_name ?? ''} onChange={(event) => form.setData('authority_name', event.target.value)} />}
                    </FormField>
                    <FormField label={`${f('authority')} · ${c('amharic')}`} error={form.errors.authority_name_am}>
                        {({ id }) => <Input id={id} lang="am" maxLength={160} value={form.data.authority_name_am ?? ''} onChange={(event) => form.setData('authority_name_am', event.target.value)} />}
                    </FormField>
                    <FormField label={`${c('short_description')} · ${c('english')}`} error={form.errors.description}>
                        {({ id }) => <Textarea id={id} rows={3} maxLength={500} placeholder={text('footer_description_placeholder')} value={form.data.description ?? ''} onChange={(event) => form.setData('description', event.target.value)} />}
                    </FormField>
                    <FormField label={`${c('short_description')} · ${c('amharic')}`} error={form.errors.description_am}>
                        {({ id }) => <Textarea id={id} lang="am" rows={3} maxLength={500} value={form.data.description_am ?? ''} onChange={(event) => form.setData('description_am', event.target.value)} />}
                    </FormField>
                </div>
            </SettingsCard>

            <SettingsCard title={f('contact')}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField label={c('phone')} error={form.errors.contact_phone}>
                        {({ id }) => <Input id={id} maxLength={60} placeholder="+251 11 123 4567" value={form.data.contact_phone} onChange={(event) => form.setData('contact_phone', event.target.value)} />}
                    </FormField>
                    <FormField label={c('email')} error={form.errors.contact_email}>
                        {({ id }) => <Input id={id} type="email" maxLength={120} placeholder="info@court.gov.et" value={form.data.contact_email} onChange={(event) => form.setData('contact_email', event.target.value)} />}
                    </FormField>
                    <FormField label={`${c('physical_address')} · ${c('english')}`} error={form.errors.contact_address}>
                        {({ id }) => <Input id={id} maxLength={255} placeholder={text('address_placeholder')} value={form.data.contact_address ?? ''} onChange={(event) => form.setData('contact_address', event.target.value)} />}
                    </FormField>
                    <FormField label={`${c('physical_address')} · ${c('amharic')}`} error={form.errors.contact_address_am}>
                        {({ id }) => <Input id={id} lang="am" maxLength={255} value={form.data.contact_address_am ?? ''} onChange={(event) => form.setData('contact_address_am', event.target.value)} />}
                    </FormField>
                </div>
            </SettingsCard>

            <SettingsCard title={f('office')}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField label={`${f('office_hours')} · ${c('english')}`} error={form.errors.office_hours}>
                        {({ id }) => <Input id={id} maxLength={255} placeholder="Mon–Fri, 8:30–17:30" value={form.data.office_hours ?? ''} onChange={(event) => form.setData('office_hours', event.target.value)} />}
                    </FormField>
                    <FormField label={`${f('office_hours')} · ${c('amharic')}`} error={form.errors.office_hours_am}>
                        {({ id }) => <Input id={id} lang="am" maxLength={255} value={form.data.office_hours_am ?? ''} onChange={(event) => form.setData('office_hours_am', event.target.value)} />}
                    </FormField>
                    <FormField label={f('map_url')} error={form.errors.map_url} className="sm:col-span-2">
                        {({ id }) => <Input id={id} type="url" maxLength={500} placeholder="https://maps.google.com/..." value={form.data.map_url ?? ''} onChange={(event) => form.setData('map_url', event.target.value)} />}
                    </FormField>
                </div>
            </SettingsCard>

            <SettingsCard title={f('social')}>
                <p className="text-xs text-(--app-muted-foreground)">{f('social_help')}</p>
                <div className="grid gap-4 sm:grid-cols-2">
                    {SOCIAL_LINKS.map((social) => (
                        <div key={social.field} className="flex items-end gap-2">
                            <div className="mb-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-(--app-surface-muted) text-(--app-muted-foreground)" aria-hidden="true">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d={social.icon} /></svg>
                            </div>
                            <FormField label={social.label} error={form.errors[social.field]} className="min-w-0 flex-1">
                                {({ id }) => <Input id={id} type="url" maxLength={300} placeholder="https://..." value={form.data[social.field]} onChange={(event) => form.setData(social.field, event.target.value)} />}
                            </FormField>
                        </div>
                    ))}
                </div>
            </SettingsCard>

            <SaveRow label={f('save')} processing={form.processing} />
        </form>
    );
}

/* ── Section settings ─────────────────────────────────────────────────── */

type TitledSection = (typeof TITLED_SECTIONS)[number];

type SectionValues = Record<TitledSection, Record<string, string | boolean>>;

const SECTION_FIELDS = ['title', 'subtitle', 'title_am', 'subtitle_am'] as const;

function toFormValues(sections: Record<SectionKey, SectionSetting>): SectionValues {
    return TITLED_SECTIONS.reduce((values, key) => {
        const stored = (sections[key] ?? { visible: true }) as unknown as Record<string, unknown>;
        const fields: Record<string, string | boolean> = { visible: stored.visible !== false };
        SECTION_FIELDS.forEach((name) => { fields[name] = String(stored[name] ?? ''); });
        values[key] = fields;
        return values;
    }, {} as SectionValues);
}

/** Which home-page sections show, and the titles that replace the translated defaults. */
export function SectionsPanel({ sections }: { sections: Record<SectionKey, SectionSetting> }) {
    const { t, text } = usePublicText();
    const form = useForm<SectionValues>(toFormValues(sections));
    const [open, setOpen] = useState<TitledSection | null>(null);
    const s = (key: string) => t(`cm.admin_landing.sections.${key}`);
    const c = (key: string) => t(`cm.admin_landing.common.${key}`);
    const errors = form.errors as Record<string, string | undefined>;

    const set = (section: TitledSection, field: string, value: string | boolean) =>
        form.setData((data) => ({ ...data, [section]: { ...data[section], [field]: value } }));

    const textField = (section: TitledSection, field: string, label: string, options: { max: number; placeholder?: string; multiline?: boolean; className?: string; lang?: string }) => (
        <FormField label={label} error={errors[`${section}.${field}`]} className={options.className}>
            {({ id }) => options.multiline
                ? <Textarea id={id} lang={options.lang} rows={2} maxLength={options.max} placeholder={options.placeholder} value={String(form.data[section][field] ?? '')} onChange={(event) => set(section, field, event.target.value)} />
                : <Input id={id} lang={options.lang} maxLength={options.max} placeholder={options.placeholder} value={String(form.data[section][field] ?? '')} onChange={(event) => set(section, field, event.target.value)} />}
        </FormField>
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('court.admin.landing.sections.update'), visit);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <PanelHeading title={s('title')} description={s('description')} />

            <div className="space-y-3">
                {TITLED_SECTIONS.map((key) => {
                    const expanded = open === key;
                    const panelId = `landing-section-${key}`;
                    return (
                        <Card key={key} className="overflow-hidden p-0">
                            <div className="flex items-center gap-3 px-5 py-4">
                                <SwitchButton
                                    checked={Boolean(form.data[key].visible)}
                                    label={`${text('section_visible')}: ${s(key)}`}
                                    onChange={(checked) => set(key, 'visible', checked)}
                                />
                                <button
                                    type="button"
                                    onClick={() => setOpen(expanded ? null : key)}
                                    aria-expanded={expanded}
                                    aria-controls={panelId}
                                    className="flex flex-1 items-center justify-between gap-3 text-left text-sm font-semibold text-(--app-foreground)"
                                >
                                    {s(key)}
                                    <ChevronDown className={cx('h-4 w-4 text-(--app-muted-foreground) transition-transform', expanded && 'rotate-180')} aria-hidden="true" />
                                </button>
                            </div>

                            {expanded && (
                                <div id={panelId} className="grid gap-4 border-t border-(--app-border) px-5 pb-5 pt-4 sm:grid-cols-2">
                                    {textField(key, 'title', `${s('section_title')} · ${c('english')}`, { max: 255, placeholder: s('default_hint') })}
                                    {textField(key, 'title_am', `${s('section_title')} · ${c('amharic')}`, { max: 255, placeholder: s('default_hint'), lang: 'am' })}
                                    {textField(key, 'subtitle', `${s('subtitle')} · ${c('english')}`, { max: 500, placeholder: s('default_hint'), multiline: true })}
                                    {textField(key, 'subtitle_am', `${s('subtitle')} · ${c('amharic')}`, { max: 500, placeholder: s('default_hint'), multiline: true, lang: 'am' })}
                                </div>
                            )}
                        </Card>
                    );
                })}
            </div>

            <SaveRow label={s('save')} processing={form.processing} />
        </form>
    );
}
