import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { FormField, Input, Select, Textarea, cx } from '@/Components/ui';
import { usePublicText } from '@/court/i18n/usePublicText';
import type {
    LandingFaq, LandingResource, LandingService, LandingSlide, LandingStep,
    ResourceType, ServiceAccent, ServiceIcon, SlideStyle, StepColor,
} from '@/court/types/public';
import {
    AddButton, AmharicFields, COLOR_OPTIONS, Chip, EditorCard, EmptyBox, HiddenChip, OptionalHint, PanelHeading, RowActions, SwitchButton, blankAmharic, useInlineEditor,
} from './LandingParts';

/** The form errors, read by any key (court-ms answers Amharic fields as `am.<field>`). */
const anyErrors = (errors: object) => errors as Record<string, string | undefined>;

const fileInputClass = 'cursor-pointer py-1.5 file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1 file:text-xs file:font-semibold file:text-primary';
const visit = { preserveScroll: true } as const;

/* ── Hero slides ──────────────────────────────────────────────────────── */

type SlideForm = {
    badge: string;
    title: string;
    description: string;
    primary_label: string;
    primary_href: string;
    secondary_label: string;
    secondary_href: string;
    bg_style: SlideStyle;
    bg_image: File | null;
    remove_bg_image: boolean;
    am: Record<string, string>;
};

const SLIDE_AM = ['badge', 'title', 'description', 'primary_label', 'secondary_label'] as const;

const EMPTY_SLIDE: SlideForm = {
    badge: '', title: '', description: '', primary_label: '', primary_href: '', secondary_label: '', secondary_href: '',
    bg_style: 'blue', bg_image: null, remove_bg_image: false, am: blankAmharic(SLIDE_AM),
};

const SLIDE_SWATCH: Record<SlideStyle, string> = {
    blue: 'bg-linear-to-br from-blue-700 to-blue-500',
    orange: 'bg-linear-to-br from-orange-600 to-amber-400',
    emerald: 'bg-linear-to-br from-emerald-700 to-teal-400',
};

/** The hero carousel: add, edit (with a background image), show/hide and delete slides. */
export function SlidesPanel({ slides }: { slides: LandingSlide[] }) {
    const { t } = usePublicText();
    const editor = useInlineEditor();
    const form = useForm<SlideForm>(EMPTY_SLIDE);
    const current = editor.editing !== null ? slides.find((slide) => slide.id === editor.editing) ?? null : null;
    const c = (key: string) => t(`cm.admin_landing.common.${key}`);

    const create = () => {
        form.reset();
        form.clearErrors();
        editor.toggleCreate();
    };

    const edit = (slide: LandingSlide) => {
        form.setData({
            badge: slide.badge ?? '', title: slide.title, description: slide.description ?? '',
            primary_label: slide.primary_label, primary_href: slide.primary_href,
            secondary_label: slide.secondary_label ?? '', secondary_href: slide.secondary_href ?? '',
            bg_style: slide.bg_style, bg_image: null, remove_bg_image: false, am: { ...blankAmharic(SLIDE_AM), ...slide.am },
        });
        form.clearErrors();
        editor.startEdit(slide.id);
    };

    const cancel = () => {
        editor.close();
        form.reset();
        form.clearErrors();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { ...visit, forceFormData: true, onSuccess: cancel };
        if (editor.editing !== null) {
            // Multipart cannot travel as PUT; Laravel reads the method from `_method`.
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(route('court.admin.landing.slides.update', editor.editing), options);
        } else {
            form.transform((data) => data);
            form.post(route('court.admin.landing.slides.store'), options);
        }
    };

    return (
        <div className="space-y-4">
            <PanelHeading
                title={t('cm.admin_landing.slides.title')}
                description={t('cm.admin_landing.slides.description')}
                action={<AddButton label={t('cm.admin_landing.slides.add')} onClick={create} expanded={editor.open && editor.editing === null} />}
            />

            {editor.open && (
                <EditorCard
                    key={editor.editing ?? 'new'}
                    editorRef={editor.ref}
                    title={editor.editing !== null ? c('edit_slide') : c('new_slide')}
                    onSubmit={submit}
                    saveLabel={c('save_slide')}
                    cancelLabel={c('cancel')}
                    processing={form.processing}
                    onCancel={cancel}
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label={<>{c('badge_text')} <OptionalHint /></>} error={form.errors.badge}>
                            {({ id }) => <Input id={id} value={form.data.badge} maxLength={120} onChange={(event) => form.setData('badge', event.target.value)} />}
                        </FormField>
                        <FormField label={c('background_style')} required error={form.errors.bg_style}>
                            {({ id }) => (
                                <Select id={id} value={form.data.bg_style} onChange={(event) => form.setData('bg_style', event.target.value as SlideStyle)}>
                                    <option value="blue">{c('blue')}</option>
                                    <option value="orange">{c('orange')}</option>
                                    <option value="emerald">{c('emerald')}</option>
                                </Select>
                            )}
                        </FormField>
                    </div>

                    <FormField label={c('title')} required error={form.errors.title}>
                        {({ id }) => <Input id={id} value={form.data.title} required maxLength={255} onChange={(event) => form.setData('title', event.target.value)} />}
                    </FormField>

                    <FormField label={c('description')} error={form.errors.description}>
                        {({ id }) => <Textarea id={id} rows={3} maxLength={1000} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />}
                    </FormField>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label={c('primary_button_label')} required error={form.errors.primary_label}>
                            {({ id }) => <Input id={id} value={form.data.primary_label} required maxLength={120} onChange={(event) => form.setData('primary_label', event.target.value)} />}
                        </FormField>
                        <FormField label={c('primary_button_url')} required error={form.errors.primary_href}>
                            {({ id }) => <Input id={id} value={form.data.primary_href} required maxLength={500} placeholder="https://… /my-portal/court-cases" onChange={(event) => form.setData('primary_href', event.target.value)} />}
                        </FormField>
                        <FormField label={c('secondary_button_label')} error={form.errors.secondary_label}>
                            {({ id }) => <Input id={id} value={form.data.secondary_label} maxLength={120} onChange={(event) => form.setData('secondary_label', event.target.value)} />}
                        </FormField>
                        <FormField label={c('secondary_button_url')} error={form.errors.secondary_href}>
                            {({ id }) => <Input id={id} value={form.data.secondary_href} maxLength={500} placeholder="#hearings" onChange={(event) => form.setData('secondary_href', event.target.value)} />}
                        </FormField>
                    </div>

                    <AmharicFields
                        values={form.data.am}
                        errors={anyErrors(form.errors)}
                        onChange={(key, value) => form.setData('am', { ...form.data.am, [key]: value })}
                        fields={[
                            { key: 'badge', label: c('badge_text'), maxLength: 120 },
                            { key: 'title', label: c('title'), maxLength: 255 },
                            { key: 'description', label: c('description'), multiline: true, maxLength: 1000 },
                            { key: 'primary_label', label: c('primary_button_label'), maxLength: 120 },
                            { key: 'secondary_label', label: c('secondary_button_label'), maxLength: 120 },
                        ]}
                    />

                    <FormField label={<>{c('background_image')} <OptionalHint extra="3 MB" /></>} description={c('image_help')} error={form.errors.bg_image}>
                        {({ id, describedBy }) => (
                            <div className="space-y-2">
                                <Input id={id} type="file" accept="image/*" aria-describedby={describedBy} className={fileInputClass} onChange={(event) => form.setData('bg_image', event.target.files?.[0] ?? null)} />
                                {current?.bg_image_url && (
                                    <div className="flex items-center gap-3">
                                        <img src={current.bg_image_url} className="h-10 rounded-lg object-cover" alt="" />
                                        <label className="flex cursor-pointer items-center gap-1.5 text-xs text-red-600">
                                            <input type="checkbox" checked={form.data.remove_bg_image} onChange={(event) => form.setData('remove_bg_image', event.target.checked)} className="rounded border-(--app-border-strong) text-red-600" />
                                            {c('remove_current_image')}
                                        </label>
                                    </div>
                                )}
                            </div>
                        )}
                    </FormField>
                </EditorCard>
            )}

            {slides.length === 0 ? (
                <EmptyBox>{c('no_slides')}</EmptyBox>
            ) : (
                <ul className="space-y-3">
                    {slides.map((slide) => (
                        <li key={slide.id} className="flex items-start gap-4 rounded-card border border-(--app-border) bg-(--app-surface) p-4">
                            <div className="mt-1 shrink-0">
                                {slide.bg_image_url
                                    ? <img src={slide.bg_image_url} className="h-12 w-16 rounded-xl object-cover" alt="" />
                                    : <div className={cx('h-10 w-10 rounded-xl', SLIDE_SWATCH[slide.bg_style] ?? SLIDE_SWATCH.blue)} aria-hidden="true" />}
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="mb-1 flex flex-wrap items-center gap-2">
                                    {slide.badge && <Chip tone="primary" className="uppercase tracking-wider">{slide.badge}</Chip>}
                                    {!slide.is_active && <HiddenChip />}
                                </div>
                                <p className="truncate text-sm font-semibold text-(--app-foreground)">{slide.title}</p>
                                {slide.description && <p className="mt-0.5 line-clamp-1 text-xs text-(--app-muted-foreground)">{slide.description}</p>}
                                <p className="mt-1 text-[10px] text-(--app-muted-foreground)">{slide.primary_label} → {slide.primary_href}</p>
                            </div>
                            <RowActions
                                active={slide.is_active}
                                toggleUrl={route('court.admin.landing.slides.toggle', slide.id)}
                                destroyUrl={route('court.admin.landing.slides.destroy', slide.id)}
                                onEdit={() => edit(slide)}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/* ── Timeline steps ───────────────────────────────────────────────────── */

type StepForm = { title: string; meta: string; duration: string; description: string; color: StepColor; am: Record<string, string> };
const STEP_AM = ['title', 'description', 'meta', 'duration'] as const;
const EMPTY_STEP: StepForm = { title: '', meta: '', duration: '', description: '', color: 'blue', am: blankAmharic(STEP_AM) };

const STEP_DOT: Record<StepColor, string> = {
    blue: 'bg-blue-500', orange: 'bg-orange-500', emerald: 'bg-emerald-500', violet: 'bg-violet-500', amber: 'bg-amber-500',
};

/** The case-processing journey on the home page: numbered steps with a colour each. */
export function TimelinePanel({ steps }: { steps: LandingStep[] }) {
    const { t } = usePublicText();
    const editor = useInlineEditor();
    const form = useForm<StepForm>(EMPTY_STEP);
    const c = (key: string) => t(`cm.admin_landing.common.${key}`);

    const create = () => { form.reset(); form.clearErrors(); editor.toggleCreate(); };
    const cancel = () => { editor.close(); form.reset(); form.clearErrors(); };
    const edit = (step: LandingStep) => {
        form.setData({ title: step.title, meta: step.meta ?? '', duration: step.duration ?? '', description: step.description ?? '', color: step.color, am: { ...blankAmharic(STEP_AM), ...step.am } });
        form.clearErrors();
        editor.startEdit(step.id);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editor.editing !== null) form.put(route('court.admin.landing.steps.update', editor.editing), { ...visit, onSuccess: cancel });
        else form.post(route('court.admin.landing.steps.store'), { ...visit, onSuccess: cancel });
    };

    return (
        <div className="space-y-4">
            <PanelHeading
                title={t('cm.admin_landing.timeline.title')}
                description={t('cm.admin_landing.timeline.description')}
                action={<AddButton label={t('cm.admin_landing.timeline.add')} onClick={create} expanded={editor.open && editor.editing === null} />}
            />

            {editor.open && (
                <EditorCard
                    key={editor.editing ?? 'new'}
                    editorRef={editor.ref}
                    title={editor.editing !== null ? c('edit_step') : c('new_step')}
                    onSubmit={submit}
                    saveLabel={c('save_step')}
                    cancelLabel={c('cancel')}
                    processing={form.processing}
                    onCancel={cancel}
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label={c('title')} required error={form.errors.title} className="sm:col-span-2">
                            {({ id }) => <Input id={id} value={form.data.title} required maxLength={255} onChange={(event) => form.setData('title', event.target.value)} />}
                        </FormField>
                        <FormField label={c('meta_label')} error={form.errors.meta}>
                            {({ id }) => <Input id={id} value={form.data.meta} maxLength={120} onChange={(event) => form.setData('meta', event.target.value)} />}
                        </FormField>
                        <FormField label={c('duration')} error={form.errors.duration}>
                            {({ id }) => <Input id={id} value={form.data.duration} maxLength={60} onChange={(event) => form.setData('duration', event.target.value)} />}
                        </FormField>
                    </div>
                    <FormField label={c('description')} error={form.errors.description}>
                        {({ id }) => <Textarea id={id} rows={3} maxLength={1000} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />}
                    </FormField>
                    <AmharicFields
                        values={form.data.am}
                        errors={anyErrors(form.errors)}
                        onChange={(key, value) => form.setData('am', { ...form.data.am, [key]: value })}
                        fields={[
                            { key: 'title', label: c('title'), maxLength: 255 },
                            { key: 'meta', label: c('meta_label'), maxLength: 120 },
                            { key: 'duration', label: c('duration'), maxLength: 60 },
                            { key: 'description', label: c('description'), multiline: true, maxLength: 1000 },
                        ]}
                    />
                    <FormField label={c('colour_accent')} error={form.errors.color} className="sm:w-48">
                        {({ id }) => (
                            <Select id={id} value={form.data.color} onChange={(event) => form.setData('color', event.target.value as StepColor)}>
                                {COLOR_OPTIONS.map((color) => <option key={color} value={color}>{c(color)}</option>)}
                            </Select>
                        )}
                    </FormField>
                </EditorCard>
            )}

            {steps.length === 0 ? (
                <EmptyBox>{c('no_steps')}</EmptyBox>
            ) : (
                <ol className="space-y-2">
                    {steps.map((step, index) => (
                        <li key={step.id} className="flex items-center gap-4 rounded-card border border-(--app-border) bg-(--app-surface) px-4 py-3">
                            <div className={cx('flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white', STEP_DOT[step.color] ?? STEP_DOT.blue)}>
                                {index + 1}
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2 text-[10px] text-(--app-muted-foreground)">
                                    {!step.is_active && <HiddenChip />}
                                    {step.meta && <span className="font-medium">{step.meta}</span>}
                                    {step.duration && <span>· {step.duration}</span>}
                                </div>
                                <p className="truncate text-sm font-semibold text-(--app-foreground)">{step.title}</p>
                                {step.description && <p className="mt-0.5 line-clamp-1 text-xs text-(--app-muted-foreground)">{step.description}</p>}
                            </div>
                            <RowActions
                                active={step.is_active}
                                toggleUrl={route('court.admin.landing.steps.toggle', step.id)}
                                destroyUrl={route('court.admin.landing.steps.destroy', step.id)}
                                onEdit={() => edit(step)}
                            />
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

/* ── Service cards ────────────────────────────────────────────────────── */

type ServiceForm = { title: string; meta: string; description: string; features: string; icon_type: ServiceIcon; accent: ServiceAccent; href: string; am: Record<string, string> };
const SERVICE_AM = ['title', 'meta', 'description', 'features'] as const;
const EMPTY_SERVICE: ServiceForm = { title: '', meta: '', description: '', features: '', icon_type: 'document', accent: 'blue', href: '', am: blankAmharic(SERVICE_AM) };

const ICON_OPTIONS: Array<{ value: ServiceIcon; key: string }> = [
    { value: 'document', key: 'document_upload' },
    { value: 'calendar', key: 'calendar_hearing' },
    { value: 'lock', key: 'lock_evidence' },
    { value: 'chart', key: 'chart_tracking' },
    { value: 'database', key: 'database' },
    { value: 'chat', key: 'chat_dispute' },
];

/** The core-services cards: features one per line, an icon and an accent colour. */
export function ServicesPanel({ services }: { services: LandingService[] }) {
    const { t, text } = usePublicText();
    const editor = useInlineEditor();
    const form = useForm<ServiceForm>(EMPTY_SERVICE);
    const c = (key: string) => t(`cm.admin_landing.common.${key}`);

    const create = () => { form.reset(); form.clearErrors(); editor.toggleCreate(); };
    const cancel = () => { editor.close(); form.reset(); form.clearErrors(); };
    const edit = (service: LandingService) => {
        form.setData({
            title: service.title, meta: service.meta ?? '', description: service.description ?? '',
            features: service.features.join('\n'), icon_type: service.icon_type, accent: service.accent, href: service.href ?? '',
            am: { title: service.am.title, meta: service.am.meta, description: service.am.description, features: (service.am.features ?? []).join('\n') },
        });
        form.clearErrors();
        editor.startEdit(service.id);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editor.editing !== null) form.put(route('court.admin.landing.services.update', editor.editing), { ...visit, onSuccess: cancel });
        else form.post(route('court.admin.landing.services.store'), { ...visit, onSuccess: cancel });
    };

    return (
        <div className="space-y-4">
            <PanelHeading
                title={t('cm.admin_landing.services.title')}
                description={t('cm.admin_landing.services.description')}
                action={<AddButton label={t('cm.admin_landing.services.add')} onClick={create} expanded={editor.open && editor.editing === null} />}
            />

            {editor.open && (
                <EditorCard
                    key={editor.editing ?? 'new'}
                    editorRef={editor.ref}
                    title={editor.editing !== null ? c('edit_service') : c('new_service')}
                    onSubmit={submit}
                    saveLabel={c('save_service')}
                    cancelLabel={c('cancel')}
                    processing={form.processing}
                    onCancel={cancel}
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label={c('title')} required error={form.errors.title}>
                            {({ id }) => <Input id={id} value={form.data.title} required maxLength={255} onChange={(event) => form.setData('title', event.target.value)} />}
                        </FormField>
                        <FormField label={c('meta_label')} error={form.errors.meta}>
                            {({ id }) => <Input id={id} value={form.data.meta} maxLength={120} onChange={(event) => form.setData('meta', event.target.value)} />}
                        </FormField>
                    </div>
                    <FormField label={c('description')} error={form.errors.description}>
                        {({ id }) => <Textarea id={id} rows={2} maxLength={1000} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />}
                    </FormField>
                    <FormField label={<>{c('features')} <span className="font-normal text-(--app-muted-foreground)">({c('one_per_line')})</span></>} error={form.errors.features}>
                        {({ id }) => <Textarea id={id} rows={4} className="font-mono" placeholder={text('features_placeholder')} value={form.data.features} onChange={(event) => form.setData('features', event.target.value)} />}
                    </FormField>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label={c('icon')} error={form.errors.icon_type}>
                            {({ id }) => (
                                <Select id={id} value={form.data.icon_type} onChange={(event) => form.setData('icon_type', event.target.value as ServiceIcon)}>
                                    {ICON_OPTIONS.map((option) => <option key={option.value} value={option.value}>{c(option.key)}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <FormField label={c('accent_colour')} error={form.errors.accent}>
                            {({ id }) => (
                                <Select id={id} value={form.data.accent} onChange={(event) => form.setData('accent', event.target.value as ServiceAccent)}>
                                    {[...COLOR_OPTIONS, 'pink' as const].map((color) => <option key={color} value={color}>{c(color)}</option>)}
                                </Select>
                            )}
                        </FormField>
                    </div>
                    <FormField label={<>{c('card_link')} <OptionalHint /></>} description={c('card_link_help')} error={form.errors.href}>
                        {({ id, describedBy }) => <Input id={id} aria-describedby={describedBy} value={form.data.href} maxLength={500} placeholder="#hearings" onChange={(event) => form.setData('href', event.target.value)} />}
                    </FormField>
                    <AmharicFields
                        values={form.data.am}
                        errors={anyErrors(form.errors)}
                        onChange={(key, value) => form.setData('am', { ...form.data.am, [key]: value })}
                        fields={[
                            { key: 'title', label: c('title'), maxLength: 255 },
                            { key: 'meta', label: c('meta_label'), maxLength: 120 },
                            { key: 'description', label: c('description'), multiline: true, rows: 2, maxLength: 1000 },
                            { key: 'features', label: `${c('features')} (${c('one_per_line')})`, multiline: true, rows: 3, mono: true },
                        ]}
                    />
                </EditorCard>
            )}

            {services.length === 0 ? (
                <EmptyBox>{text('services_default_cards')}</EmptyBox>
            ) : (
                <ul className="space-y-2">
                    {services.map((service) => (
                        <ServiceRow key={service.id} service={service} onEdit={() => edit(service)} />
                    ))}
                </ul>
            )}
        </div>
    );
}

function ServiceRow({ service, onEdit }: { service: LandingService; onEdit: () => void }) {
    const { t } = usePublicText();
    const toggle = useForm({});

    return (
        <li className="flex items-center justify-between gap-4 rounded-card border border-(--app-border) bg-(--app-surface) px-5 py-4">
            <div className="flex min-w-0 flex-1 items-center gap-4">
                <SwitchButton
                    checked={service.is_active}
                    disabled={toggle.processing}
                    label={service.is_active ? t('cm.admin_landing.common.deactivate') : t('cm.admin_landing.common.activate')}
                    onChange={() => toggle.patch(route('court.admin.landing.services.toggle', service.id), visit)}
                />
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-(--app-foreground)">{service.title}</p>
                    <p className="truncate text-xs text-(--app-muted-foreground)">{[service.meta, service.icon_type, service.accent].filter(Boolean).join(' · ')}</p>
                </div>
            </div>
            <RowActions
                active={service.is_active}
                showToggle={false}
                toggleUrl={route('court.admin.landing.services.toggle', service.id)}
                destroyUrl={route('court.admin.landing.services.destroy', service.id)}
                onEdit={onEdit}
            />
        </li>
    );
}

/* ── Resources and posts ──────────────────────────────────────────────── */

type ResourceForm = {
    title: string;
    type: ResourceType;
    published_at: string;
    description: string;
    external_url: string;
    file: File | null;
    remove_file: boolean;
    cover_image: File | null;
    is_featured: boolean;
    am: Record<string, string>;
};

const RESOURCE_AM = ['title', 'description'] as const;

const EMPTY_RESOURCE: ResourceForm = {
    title: '', type: 'post', published_at: '', description: '', external_url: '', file: null, remove_file: false, cover_image: null, is_featured: false,
    am: blankAmharic(RESOURCE_AM),
};

const RESOURCE_TYPES: Array<{ value: ResourceType; key: string }> = [
    { value: 'post', key: 'post_update' },
    { value: 'form', key: 'downloadable_form' },
    { value: 'document', key: 'document' },
    { value: 'link', key: 'external_link' },
];

const RESOURCE_ICONS: Record<'post' | 'link' | 'file', string> = {
    post: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    link: 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1',
    file: 'M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z',
};

/** Posts, downloadable forms, documents and links, with a file, a cover and a publish date. */
export function ResourcesPanel({ resources }: { resources: LandingResource[] }) {
    const { t, text } = usePublicText();
    const editor = useInlineEditor();
    const form = useForm<ResourceForm>(EMPTY_RESOURCE);
    const current = editor.editing !== null ? resources.find((resource) => resource.id === editor.editing) ?? null : null;
    const c = (key: string) => t(`cm.admin_landing.common.${key}`);

    const create = () => { form.reset(); form.clearErrors(); editor.toggleCreate(); };
    const cancel = () => { editor.close(); form.reset(); form.clearErrors(); };
    const edit = (resource: LandingResource) => {
        form.setData({
            title: resource.title, type: resource.type, published_at: resource.published_at_local ?? '',
            description: resource.description ?? '', external_url: resource.external_url ?? '',
            file: null, remove_file: false, cover_image: null, is_featured: resource.is_featured,
            am: { ...blankAmharic(RESOURCE_AM), ...resource.am },
        });
        form.clearErrors();
        editor.startEdit(resource.id);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { ...visit, forceFormData: true, onSuccess: cancel };
        if (editor.editing !== null) {
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(route('court.admin.landing.resources.update', editor.editing), options);
        } else {
            form.transform((data) => data);
            form.post(route('court.admin.landing.resources.store'), options);
        }
    };

    return (
        <div className="space-y-4">
            <PanelHeading
                title={t('cm.admin_landing.resources.title')}
                description={t('cm.admin_landing.resources.description')}
                action={<AddButton label={t('cm.admin_landing.resources.add')} onClick={create} expanded={editor.open && editor.editing === null} />}
            />

            {editor.open && (
                <EditorCard
                    key={editor.editing ?? 'new'}
                    editorRef={editor.ref}
                    title={editor.editing !== null ? c('edit_resource') : c('new_resource')}
                    onSubmit={submit}
                    saveLabel={c('save_resource')}
                    cancelLabel={c('cancel')}
                    processing={form.processing}
                    onCancel={cancel}
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label={c('title')} required error={form.errors.title} className="sm:col-span-2">
                            {({ id }) => <Input id={id} value={form.data.title} required maxLength={255} onChange={(event) => form.setData('title', event.target.value)} />}
                        </FormField>
                        <FormField label={c('type')} required error={form.errors.type}>
                            {({ id }) => (
                                <Select id={id} value={form.data.type} onChange={(event) => form.setData('type', event.target.value as ResourceType)}>
                                    {RESOURCE_TYPES.map((option) => <option key={option.value} value={option.value}>{c(option.key)}</option>)}
                                </Select>
                            )}
                        </FormField>
                        <FormField label={<>{c('publish_date')} <span className="font-normal text-(--app-muted-foreground)">({c('schedule_future')})</span></>} error={form.errors.published_at}>
                            {({ id }) => <Input id={id} type="datetime-local" value={form.data.published_at} onChange={(event) => form.setData('published_at', event.target.value)} />}
                        </FormField>
                    </div>

                    <FormField label={c('description_summary')} error={form.errors.description}>
                        {({ id }) => <Textarea id={id} rows={3} maxLength={2000} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />}
                    </FormField>

                    {form.data.type === 'link' && (
                        <FormField label={c('external_url')} error={form.errors.external_url}>
                            {({ id }) => <Input id={id} type="url" maxLength={500} placeholder="https://..." value={form.data.external_url} onChange={(event) => form.setData('external_url', event.target.value)} />}
                        </FormField>
                    )}

                    {(form.data.type === 'form' || form.data.type === 'document') && (
                        <FormField label={<>{c('file')} <span className="font-normal text-(--app-muted-foreground)">(PDF, DOCX, XLS, 20 MB)</span></>} error={form.errors.file}>
                            {({ id, describedBy }) => (
                                <div className="space-y-2">
                                    <Input id={id} type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip" aria-describedby={describedBy} className={fileInputClass} onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)} />
                                    {current?.file_path && (
                                        <div className="flex flex-wrap items-center gap-3 text-xs text-(--app-muted-foreground)">
                                            <span>{text('current_file')}: <span className="font-mono">{current.file_path}</span></span>
                                            <label className="flex cursor-pointer items-center gap-1 text-red-600">
                                                <input type="checkbox" checked={form.data.remove_file} onChange={(event) => form.setData('remove_file', event.target.checked)} className="rounded border-(--app-border-strong) text-red-600" />
                                                {c('remove_file')}
                                            </label>
                                        </div>
                                    )}
                                </div>
                            )}
                        </FormField>
                    )}

                    <FormField label={<>{c('cover_image')} <OptionalHint extra="2 MB" /></>} error={form.errors.cover_image}>
                        {({ id, describedBy }) => (
                            <div className="space-y-2">
                                <Input id={id} type="file" accept="image/*" aria-describedby={describedBy} className={fileInputClass} onChange={(event) => form.setData('cover_image', event.target.files?.[0] ?? null)} />
                                {current?.cover_image_url && <img src={current.cover_image_url} className="h-12 rounded-lg object-cover" alt={text('current_image')} />}
                            </div>
                        )}
                    </FormField>

                    <label className="flex items-center gap-2 text-xs font-medium text-(--app-foreground)">
                        <input type="checkbox" checked={form.data.is_featured} onChange={(event) => form.setData('is_featured', event.target.checked)} className="rounded border-(--app-border-strong) text-primary" />
                        {c('featured')}
                    </label>
                    <AmharicFields
                        values={form.data.am}
                        errors={anyErrors(form.errors)}
                        onChange={(key, value) => form.setData('am', { ...form.data.am, [key]: value })}
                        fields={[
                            { key: 'title', label: c('title'), maxLength: 255 },
                            { key: 'description', label: c('description_summary'), multiline: true, maxLength: 2000 },
                        ]}
                    />
                </EditorCard>
            )}

            {resources.length === 0 ? (
                <EmptyBox>{c('no_resources')}</EmptyBox>
            ) : (
                <ul className="space-y-2">
                    {resources.map((resource) => (
                        <li key={resource.id} className="flex items-start gap-3 rounded-card border border-(--app-border) bg-(--app-surface) px-4 py-3">
                            {resource.cover_image_url ? (
                                <img src={resource.cover_image_url} className="h-12 w-16 shrink-0 rounded-xl object-cover" alt="" />
                            ) : (
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-(--app-surface-muted) text-(--app-muted-foreground)" aria-hidden="true">
                                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={RESOURCE_ICONS[resource.type === 'post' || resource.type === 'link' ? resource.type : 'file']} />
                                    </svg>
                                </div>
                            )}
                            <div className="min-w-0 flex-1">
                                <div className="mb-0.5 flex flex-wrap items-center gap-1.5">
                                    <Chip className="uppercase">{resource.type}</Chip>
                                    {resource.is_featured && <Chip tone="amber">{c('featured')}</Chip>}
                                    {!resource.is_active && <HiddenChip />}
                                    {resource.is_scheduled && (
                                        <Chip tone="primary">{text('scheduled')} <LocalizedDateDisplay value={resource.published_at} withTime /></Chip>
                                    )}
                                </div>
                                <p className="truncate text-sm font-semibold text-(--app-foreground)">{resource.title}</p>
                                {resource.description && <p className="mt-0.5 line-clamp-1 text-xs text-(--app-muted-foreground)">{resource.description}</p>}
                            </div>
                            <RowActions
                                active={resource.is_active}
                                toggleUrl={route('court.admin.landing.resources.toggle', resource.id)}
                                destroyUrl={route('court.admin.landing.resources.destroy', resource.id)}
                                onEdit={() => edit(resource)}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/* ── FAQs ─────────────────────────────────────────────────────────────── */

type FaqForm = { question: string; answer: string; am: Record<string, string> };
const FAQ_AM = ['question', 'answer'] as const;
const EMPTY_FAQ: FaqForm = { question: '', answer: '', am: blankAmharic(FAQ_AM) };

/** The home page's questions and answers. */
export function FaqsPanel({ faqs }: { faqs: LandingFaq[] }) {
    const { t } = usePublicText();
    const editor = useInlineEditor();
    const form = useForm<FaqForm>(EMPTY_FAQ);
    const c = (key: string) => t(`cm.admin_landing.common.${key}`);

    const create = () => { form.reset(); form.clearErrors(); editor.toggleCreate(); };
    const cancel = () => { editor.close(); form.reset(); form.clearErrors(); };
    const edit = (faq: LandingFaq) => {
        form.setData({ question: faq.question, answer: faq.answer, am: { ...blankAmharic(FAQ_AM), ...faq.am } });
        form.clearErrors();
        editor.startEdit(faq.id);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editor.editing !== null) form.put(route('court.admin.landing.faqs.update', editor.editing), { ...visit, onSuccess: cancel });
        else form.post(route('court.admin.landing.faqs.store'), { ...visit, onSuccess: cancel });
    };

    return (
        <div className="space-y-4">
            <PanelHeading
                title={t('cm.admin_landing.faqs.title')}
                description={t('cm.admin_landing.faqs.description')}
                action={<AddButton label={t('cm.admin_landing.faqs.add')} onClick={create} expanded={editor.open && editor.editing === null} />}
            />

            {editor.open && (
                <EditorCard
                    key={editor.editing ?? 'new'}
                    editorRef={editor.ref}
                    title={editor.editing !== null ? c('edit_faq') : c('new_faq')}
                    onSubmit={submit}
                    saveLabel={c('save_faq')}
                    cancelLabel={c('cancel')}
                    processing={form.processing}
                    onCancel={cancel}
                >
                    <FormField label={c('question')} required error={form.errors.question}>
                        {({ id }) => <Input id={id} value={form.data.question} required maxLength={500} onChange={(event) => form.setData('question', event.target.value)} />}
                    </FormField>
                    <FormField label={c('answer')} required error={form.errors.answer}>
                        {({ id }) => <Textarea id={id} rows={4} required maxLength={2000} value={form.data.answer} onChange={(event) => form.setData('answer', event.target.value)} />}
                    </FormField>
                    <AmharicFields
                        values={form.data.am}
                        errors={anyErrors(form.errors)}
                        onChange={(key, value) => form.setData('am', { ...form.data.am, [key]: value })}
                        fields={[
                            { key: 'question', label: c('question'), maxLength: 500 },
                            { key: 'answer', label: c('answer'), multiline: true, rows: 4, maxLength: 2000 },
                        ]}
                    />
                </EditorCard>
            )}

            {faqs.length === 0 ? (
                <EmptyBox>{c('no_faqs')}</EmptyBox>
            ) : (
                <ul className="space-y-2">
                    {faqs.map((faq) => (
                        <li key={faq.id} className="flex items-start gap-3 rounded-card border border-(--app-border) bg-(--app-surface) p-4">
                            <div className="min-w-0 flex-1">
                                <div className="mb-1 flex flex-wrap items-center gap-2">
                                    {!faq.is_active && <HiddenChip />}
                                    <p className="text-sm font-semibold text-(--app-foreground)">{faq.question}</p>
                                </div>
                                <p className="line-clamp-2 text-xs text-(--app-muted-foreground)">{faq.answer}</p>
                            </div>
                            <RowActions
                                active={faq.is_active}
                                toggleUrl={route('court.admin.landing.faqs.toggle', faq.id)}
                                destroyUrl={route('court.admin.landing.faqs.destroy', faq.id)}
                                onEdit={() => edit(faq)}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
