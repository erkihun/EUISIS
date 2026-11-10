import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import ActionErrors from '@/Components/letters/ActionErrors';
import { errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, textareaCls, useLetters } from '@/Components/letters/helpers';
import {
    AlignCenterIcon, AlignLeftIcon, AlignRightIcon, BoldIcon, ChevronDown, ChevronUp, CopyIcon, EyeIcon, GalleryIcon, ImageAddIcon, PlusIcon, RowAddIcon,
    RulerIcon, TextLineIcon, TrashIcon, UnderlineIcon, X,
} from '@/Components/NavIcons';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { LayoutCell, LayoutEditorOptions, LayoutItem, LayoutPage, LayoutRow, LayoutSection, LetterLayoutData, OrganizationOption } from '@/types/letters';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type CSSProperties, type DragEvent, type FormEvent, type JSX, type KeyboardEvent, type MouseEvent, type MutableRefObject, type ReactNode } from 'react';

interface Props {
    organizations: OrganizationOption[];
    platform: boolean;
    organization: (OrganizationOption & { id: string }) | null;
    layout: LetterLayoutData;
    previews: Record<string, string>;
    options: LayoutEditorOptions;
}

type SectionKey = 'header' | 'footer';
type Tab = SectionKey | 'page' | 'images';
type NewImage = { key: string; name: string; file: File; url: string };
type Selection = { section: SectionKey; r: number; c: number; i: number } | null;
type ImageChoice = { key: string; name: string; url: string | null };
type SegmentOption = [string, string] | [string, string, ReactNode];

const PAPER_MM: Record<string, [number, number]> = { a4: [210, 297], a5: [148, 210], letter: [215.9, 279.4], legal: [215.9, 355.6] };
const FONT_CSS: Record<string, string> = { abyssinica: "'Abyssinica SIL', 'Nyala', serif", noto_sans: "'Noto Sans Ethiopic', 'Nyala', sans-serif" };
const SWATCHES = ['#111111', '#333333', '#555555', '#122170', '#1D4ED8', '#166534', '#B91C1C', '#92400E'];
const MM_PX = 3.7795;
const TOKEN = /\{\{\s*([a-z_.]+)\s*\}\}/g;

const newText = (): LayoutItem => ({ type: 'text', text: '', language: 'both', font_family: '', font_size: 10, bold: false, underline: false, color: '#111111' });
const newCell = (align: LayoutCell['align'] = 'center'): LayoutCell => ({ items: [], align, valign: 'middle', width: 0 });
const newRow = (): LayoutRow => ({ cells: [{ ...newCell(), items: [newText()] }], border_position: 'none', border_style: 'thin', border_color: '#122170', space_after: 3 });
const randomKey = (): string => `img_${Math.random().toString(36).slice(2, 10)}`;
const snapshot = (v: unknown): string => JSON.stringify(v);

function useWide(query = '(min-width: 1280px)'): boolean {
    const [wide, setWide] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches);
    useEffect(() => {
        const media = window.matchMedia(query);
        const update = () => setWide(media.matches);
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, [query]);
    return wide;
}

/**
 * The header-and-footer editor. Tabs keep one area in view at a time; the
 * header and footer are drawn as rows of column tiles whose lines are picked
 * (in the structure or straight on the preview) and edited in one inspector.
 */
export default function LetterheadForm({ organizations, platform, organization, layout, previews, options }: Props): JSX.Element {
    const { lt, pick } = useLetters();
    const editing = layout.id !== null;
    const wide = useWide();

    const [tab, setTab] = useState<Tab>('header');
    const [page, setPage] = useState<LayoutPage>(layout.page);
    const [sections, setSections] = useState<Record<SectionKey, LayoutSection>>({ header: layout.header, footer: layout.footer });
    const [selection, setSelection] = useState<Selection>(null);
    const [newImages, setNewImages] = useState<NewImage[]>([]);
    const [removed, setRemoved] = useState<string[]>([]);
    const [stampUrl, setStampUrl] = useState<string | null>(null);
    const [previewLanguage, setPreviewLanguage] = useState<'am' | 'en'>('am');
    const [baseline, setBaseline] = useState(() => snapshot({ name: layout.name, d: layout.is_default, a: layout.active, page: layout.page, h: layout.header, f: layout.footer }));
    const textRef = useRef<HTMLTextAreaElement | null>(null);

    const form = useForm({
        organization_id: layout.organization_id,
        name: layout.name,
        is_default: layout.is_default,
        active: layout.active,
        stamp: null as File | null,
        remove_stamp: false,
    });
    const errors = form.errors as Record<string, string>;

    const dirty = baseline !== snapshot({ name: form.data.name, d: form.data.is_default, a: form.data.active, page, h: sections.header, f: sections.footer })
        || newImages.length > 0 || removed.length > 0 || form.data.stamp !== null || form.data.remove_stamp;

    const imageChoices: ImageChoice[] = useMemo(() => [
        ...(organization ? [{ key: options.org_logo, name: lt('letterheads.orgLogo'), url: previews[options.org_logo] ?? null }] : []),
        ...layout.images.filter((i) => !removed.includes(i.key)).map((i) => ({ key: i.key, name: i.name, url: previews[i.key] ?? null })),
        ...newImages.map((i) => ({ key: i.key, name: i.name, url: i.url })),
    ], [organization, layout.images, removed, newImages, previews, options.org_logo]); // eslint-disable-line react-hooks/exhaustive-deps
    const imageUrl = (key?: string): string | null => imageChoices.find((i) => i.key === key)?.url ?? null;
    const uploadedCount = layout.images.length - removed.length + newImages.length;
    const usage = (key: string): number => (['header', 'footer'] as const).reduce((n, s) => n + sections[s].rows.reduce((m, row) => m + row.cells.reduce((k, cell) => k + cell.items.filter((it) => it.type === 'image' && it.image === key).length, 0), 0), 0);

    useEffect(() => () => newImages.forEach((i) => URL.revokeObjectURL(i.url)), []); // eslint-disable-line react-hooks/exhaustive-deps

    function updateRows(section: SectionKey, change: (rows: LayoutRow[]) => LayoutRow[]) {
        setSections((current) => ({ ...current, [section]: { rows: change(structuredClone(current[section].rows)) } }));
    }

    function select(next: Selection) {
        setSelection(next);
        if (next && next.section !== tab) setTab(next.section);
    }

    const selected = selection ? sections[selection.section].rows[selection.r]?.cells[selection.c]?.items[selection.i] ?? null : null;
    const selectedCell = selection ? sections[selection.section].rows[selection.r]?.cells[selection.c] ?? null : null;

    function updateItem(change: (item: LayoutItem) => void) {
        if (!selection) return;
        updateRows(selection.section, (rows) => { change(rows[selection.r].cells[selection.c].items[selection.i]); return rows; });
    }

    function updateCell(change: (cell: LayoutCell) => void) {
        if (!selection) return;
        updateRows(selection.section, (rows) => { change(rows[selection.r].cells[selection.c]); return rows; });
    }

    function insertPlaceholder(key: string) {
        const el = textRef.current;
        const token = `{{${key}}}`;
        updateItem((item) => {
            const text = item.text ?? '';
            const at = el && el.selectionStart !== null ? el.selectionStart : text.length;
            item.text = text.slice(0, at) + token + text.slice(at);
        });
        requestAnimationFrame(() => el?.focus());
    }

    function addImages(files: FileList | File[] | null) {
        if (!files) return;
        const room = options.max_images - uploadedCount;
        const accepted = Array.from(files).filter((f) => /^image\/(png|jpeg|svg\+xml)$/.test(f.type)).slice(0, Math.max(0, room));
        setNewImages((list) => [...list, ...accepted.map((file) => ({ key: randomKey(), name: file.name, file, url: URL.createObjectURL(file) }))]);
    }

    function removeImage(key: string) {
        const fresh = newImages.find((n) => n.key === key);
        if (fresh) {
            URL.revokeObjectURL(fresh.url);
            setNewImages((list) => list.filter((n) => n.key !== key));
        } else {
            setRemoved((list) => [...list, key]);
        }
        // Lines that showed the image go too, as they would on save.
        (['header', 'footer'] as const).forEach((s) => updateRows(s, (rows) => rows.map((row) => ({ ...row, cells: row.cells.map((cell) => ({ ...cell, items: cell.items.filter((it) => !(it.type === 'image' && it.image === key)) })) }))));
        setSelection(null);
    }

    function save(event?: FormEvent) {
        event?.preventDefault();
        form.transform((d) => ({
            ...d,
            ...(editing ? { _method: 'put' } : {}),
            organization_id: d.organization_id || null,
            layout: JSON.stringify({ page, header: sections.header, footer: sections.footer }),
            new_images: Object.fromEntries(newImages.map((i) => [i.key, i.file])),
            image_names: Object.fromEntries(newImages.map((i) => [i.key, i.name])),
            remove_images: removed,
        }));
        form.post(editing ? route('letters.letterheads.upload', layout.id as string) : route('letters.letterheads.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setNewImages([]);
                setRemoved([]);
                setStampUrl(null);
                setBaseline(snapshot({ name: form.data.name, d: form.data.is_default, a: form.data.active, page, h: sections.header, f: sections.footer }));
            },
        });
    }

    // Sample values for the preview and the chips.
    const orgName = (lang: 'am' | 'en') => organization ? ((lang === 'am' ? organization.name_am || organization.name_en : organization.name_en || organization.name_am) ?? '') : lt('letterheads.sampleInstitution');
    const vars: Record<string, string> = {
        'institution.name': orgName(previewLanguage),
        'institution.name_am': orgName('am'),
        'institution.name_en': orgName('en'),
        'institution.code': organization?.code ?? 'ORG',
        'unit.name': lt('letterheads.sample.unit'),
        'letter.number': lt('letterheads.sample.number'),
        'letter.date_ec': lt('letterheads.sample.date_ec'),
        'letter.date_gc': lt('letterheads.sample.date_gc'),
        'today.date_ec': lt('letterheads.sample.date_ec'),
        page: lt('letterheads.sample.page'),
    };
    const placeholderLabel = (key: string) => lt(`letterheads.placeholderNames.${key.replace(/\./g, '_')}`);

    const title = editing ? lt('letterheads.editTitle') : lt('letterheads.createTitle');
    const scope = layout.organization_id ? { organization_id: layout.organization_id } : {};
    const stampPreview = stampUrl ?? (layout.has_stamp && !form.data.remove_stamp ? previews.stamp ?? null : null);

    const inspector = selection && selected && selectedCell ? (
        <Inspector
            selection={selection} item={selected} cell={selectedCell} options={options} imageChoices={imageChoices} textRef={textRef}
            placeholderLabel={placeholderLabel} updateItem={updateItem} updateCell={updateCell} onInsert={insertPlaceholder}
            onClose={() => setSelection(null)}
            onMove={(delta) => {
                const target = selection.i + delta;
                updateRows(selection.section, (rows) => { const items = rows[selection.r].cells[selection.c].items; [items[target], items[selection.i]] = [items[selection.i], items[target]]; return rows; });
                setSelection({ ...selection, i: target });
            }}
            onDelete={() => { updateRows(selection.section, (rows) => { rows[selection.r].cells[selection.c].items.splice(selection.i, 1); return rows; }); setSelection(null); }}
            onUploadRequest={() => setTab('images')}
        />
    ) : (
        <div className={cx(panelCls, 'flex items-start gap-3 p-4')}>
            <TextLineIcon className="mt-0.5 size-5 shrink-0 text-(--app-muted-foreground)" aria-hidden="true" />
            <p className={mutedCls}>{lt('letterheads.selectHint')}</p>
        </div>
    );

    const tabs: { key: Tab; label: string; count?: number }[] = [
        { key: 'header', label: lt('letterheads.header'), count: sections.header.rows.length },
        { key: 'footer', label: lt('letterheads.footer'), count: sections.footer.rows.length },
        { key: 'page', label: lt('letterheads.page') },
        { key: 'images', label: lt('letterheads.imagesAndStamp'), count: uploadedCount },
    ];

    function onTabKey(event: KeyboardEvent<HTMLButtonElement>, index: number) {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        event.preventDefault();
        const next = (index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
        setTab(tabs[next].key);
        document.getElementById(`lf-tab-${tabs[next].key}`)?.focus();
    }

    const showInspector = tab === 'header' || tab === 'footer';

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={lt('letterheads.editorDescription')} backHref={route('letters.letterheads.index', scope)}
            actions={(
                <div className="flex flex-wrap items-center gap-2">
                    {editing && (
                        <a className={cx(secondaryBtn, 'inline-flex items-center gap-1.5')} href={route('letters.letterheads.preview', { letterhead: layout.id as string, language: previewLanguage })} target="_blank" rel="noopener">
                            <EyeIcon className="size-4" aria-hidden="true" />{lt('letterheads.previewPdf')}
                        </a>
                    )}
                    <button type="button" className={primaryBtn} disabled={form.processing} onClick={() => save()}>{form.processing ? lt('letterheads.saving') : lt('letterheads.save')}</button>
                </div>
            )} />}>
            <Head title={title} />
            <ActionErrors />
            <form onSubmit={save} aria-label={title} className="space-y-5 pb-4" noValidate>
                {/* Identity */}
                <section className={cx(panelCls, 'grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end')} aria-label={lt('letterheads.general')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className={labelCls} htmlFor="lf-name">{lt('letterheads.name')}</label>
                            <input id="lf-name" className={cx(inputCls, 'text-base font-medium')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={120} required aria-describedby="lf-name-help" />
                            <p id="lf-name-help" className="mt-1 text-xs text-(--app-muted-foreground)">{lt('letterheads.nameHelp')}</p>
                            {errors.name && <p className={errorCls}>{errors.name}</p>}
                        </div>
                        <div>
                            <label className={labelCls} htmlFor="lf-org">{lt('letterheads.institution')}</label>
                            {editing ? (
                                <p id="lf-org" className="flex min-h-10 items-center text-sm">{organization ? pick(organization) : lt('letterheads.platformLayouts')}</p>
                            ) : (
                                <select id="lf-org" className={inputCls} value={form.data.organization_id} onChange={(e) => form.setData('organization_id', e.target.value)}>
                                    {platform && <option value="">{lt('letterheads.platformLayouts')}</option>}
                                    {organizations.map((o) => <option key={o.id} value={o.id}>{pick(o)}</option>)}
                                </select>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-x-6 gap-y-1 lg:pb-5">
                        <Switch label={lt('letterheads.defaultLabel')} checked={form.data.is_default} onChange={(v) => form.setData('is_default', v)} />
                        <Switch label={lt('letterheads.activeLabel')} checked={form.data.active} onChange={(v) => form.setData('active', v)} />
                    </div>
                    {(layout.letters_count > 0 || (layout.updated_by && layout.updated_at)) && (
                        <p className={cx(mutedCls, 'text-xs lg:col-span-2')}>
                            {layout.letters_count > 0 && <>{lt('letterheads.usedBy', { count: layout.letters_count })} </>}
                            {layout.updated_by && layout.updated_at && <>{lt('letterheads.updatedBy', { name: layout.updated_by })} · <LocalizedDateDisplay value={layout.updated_at} /></>}
                        </p>
                    )}
                </section>

                <div className="grid gap-5 xl:grid-cols-12">
                    {/* Editor */}
                    <div className="min-w-0 space-y-5 xl:col-span-7">
                        <div className={panelCls}>
                            <div role="tablist" aria-label={title} className="flex gap-1 overflow-x-auto border-b border-(--app-border) px-2">
                                {tabs.map((t, index) => (
                                    <button key={t.key} id={`lf-tab-${t.key}`} type="button" role="tab" aria-selected={tab === t.key} aria-controls={`lf-panel-${t.key}`} tabIndex={tab === t.key ? 0 : -1}
                                        onClick={() => setTab(t.key)} onKeyDown={(e) => onTabKey(e, index)}
                                        className={cx('-mb-px inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-3 text-sm font-medium transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[#122170]',
                                            tab === t.key ? 'border-[#122170] text-[#122170] dark:border-blue-300 dark:text-blue-200' : 'border-transparent text-(--app-muted-foreground) hover:text-(--app-foreground)')}>
                                        {t.label}
                                        {t.count !== undefined && <span className="rounded-full bg-(--app-muted) px-1.5 text-xs tabular-nums">{t.count}</span>}
                                    </button>
                                ))}
                            </div>

                            <div id={`lf-panel-${tab}`} role="tabpanel" aria-labelledby={`lf-tab-${tab}`} className="p-4">
                                {(tab === 'header' || tab === 'footer') && (
                                    <SectionEditor section={tab} rows={sections[tab].rows} options={options} selection={selection} imageUrl={imageUrl} imageChoices={imageChoices}
                                        placeholderLabel={placeholderLabel} updateRows={(change) => updateRows(tab, change)} select={select} />
                                )}
                                {tab === 'page' && <PagePanel page={page} setPage={setPage} options={options} />}
                                {tab === 'images' && (
                                    <ImagesPanel options={options} imageChoices={imageChoices} uploadedCount={uploadedCount} usage={usage} addImages={addImages} removeImage={removeImage}
                                        stampPreview={stampPreview} hasStoredStamp={layout.has_stamp} removeStamp={form.data.remove_stamp}
                                        setStamp={(f) => { form.setData('stamp', f); setStampUrl(f ? URL.createObjectURL(f) : null); }}
                                        setRemoveStamp={(v) => form.setData('remove_stamp', v)} errors={errors} />
                                )}
                                {errors.layout && <p className={cx(errorCls, 'mt-3')} role="alert">{errors.layout}</p>}
                            </div>
                        </div>
                        {!wide && showInspector && inspector}
                    </div>

                    {/* Inspector and preview */}
                    <aside className="min-w-0 xl:col-span-5" aria-label={lt('letterheads.preview')}>
                        <div className="space-y-5 xl:sticky xl:top-4 xl:flex xl:max-h-[calc(100vh-2rem)] xl:flex-col xl:gap-4 xl:space-y-0">
                            {wide && showInspector && <div className="min-h-0 shrink overflow-y-auto overscroll-contain rounded-card">{inspector}</div>}
                            <section className={cx(panelCls, 'shrink-0 p-4')}>
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <h2 className="text-base font-semibold">{lt('letterheads.preview')}</h2>
                                    <Segmented label={lt('letterheads.previewLanguage')} value={previewLanguage} iconOnly={false} hideLabel onChange={(v) => setPreviewLanguage(v as 'am' | 'en')}
                                        options={[['am', lt('letterheads.previewLanguages.am')], ['en', lt('letterheads.previewLanguages.en')]]} />
                                </div>
                                <Sheet page={page} sections={sections} vars={vars} language={previewLanguage} imageUrl={imageUrl} stamp={stampPreview} selection={selection} onSelect={select}
                                    maxHeightRatio={wide && showInspector && selection ? 0.36 : wide ? 0.7 : undefined} />
                                <p className={cx(mutedCls, 'mt-3 text-xs')}>{lt('letterheads.previewClickHelp')}</p>
                            </section>
                        </div>
                    </aside>
                </div>

                {/* Save bar */}
                {dirty && (
                    <div className="sticky bottom-4 z-20 me-16 rounded-card border border-(--app-border) bg-(--app-card)/95 shadow-lg backdrop-blur sm:me-20">
                        <div className="flex flex-wrap items-center justify-end gap-3 px-4 py-3">
                            <span className="me-auto inline-flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300" role="status">
                                <span className="size-2 rounded-full bg-amber-500" aria-hidden="true" />{lt('letterheads.unsavedChanges')}
                            </span>
                            <Link className={secondaryBtn} href={editing ? route('letters.letterheads.edit', layout.id as string) : route('letters.letterheads.index', scope)}>{lt('letterheads.discard')}</Link>
                            <button type="submit" className={primaryBtn} disabled={form.processing}>{form.processing ? lt('letterheads.saving') : lt('letterheads.save')}</button>
                        </div>
                    </div>
                )}
            </form>
        </AuthenticatedLayout>
    );
}

/* ------------------------------------------------------------------ */
/* Header / footer structure                                           */
/* ------------------------------------------------------------------ */

function SectionEditor({ section, rows, options, selection, imageUrl, imageChoices, placeholderLabel, updateRows, select }: {
    section: SectionKey; rows: LayoutRow[]; options: LayoutEditorOptions; selection: Selection; imageUrl: (key?: string) => string | null; imageChoices: ImageChoice[];
    placeholderLabel: (key: string) => string; updateRows: (change: (rows: LayoutRow[]) => LayoutRow[]) => void; select: (s: Selection) => void;
}): JSX.Element {
    const { lt } = useLetters();

    function addLine(r: number, c: number, type: 'text' | 'image') {
        const index = rows[r].cells[c].items.length;
        updateRows((rs) => { rs[r].cells[c].items.push(type === 'text' ? newText() : { type: 'image', image: imageChoices[0]?.key ?? '', width_mm: 22, language: 'both' }); return rs; });
        select({ section, r, c, i: index });
    }

    return (
        <div className="space-y-4">
            <p className={mutedCls}>{lt(`letterheads.${section}Help`)}</p>
            {rows.length === 0 && (
                <div className="rounded-control border border-dashed border-(--app-border) p-6 text-center">
                    <p className={mutedCls}>{lt('letterheads.noRows')}</p>
                </div>
            )}
            {rows.map((row, r) => {
                const fixed = row.cells.reduce((s, c) => s + (c.width > 0 ? c.width : 0), 0);
                const autos = row.cells.filter((c) => c.width <= 0).length;
                const share = autos > 0 ? Math.max(0, 100 - fixed) / autos : 0;

                return (
                    <article key={r} className="rounded-control border border-(--app-border) bg-(--app-card)" aria-label={lt('letterheads.row', { n: r + 1 })}>
                        <header className="flex flex-wrap items-center gap-2 border-b border-(--app-border) px-3 py-1.5">
                            <span className="text-sm font-semibold">{lt('letterheads.row', { n: r + 1 })}</span>
                            <span className="text-xs text-(--app-muted-foreground)">{lt(`letterheads.borderPositions.${row.border_position}`)}</span>
                            <span className="ms-auto flex gap-0.5">
                                <IconButton label={lt('letterheads.moveUp')} disabled={r === 0} onClick={() => { updateRows((rs) => { [rs[r - 1], rs[r]] = [rs[r], rs[r - 1]]; return rs; }); select(null); }}><ChevronUp className="size-4" /></IconButton>
                                <IconButton label={lt('letterheads.moveDown')} disabled={r === rows.length - 1} onClick={() => { updateRows((rs) => { [rs[r + 1], rs[r]] = [rs[r], rs[r + 1]]; return rs; }); select(null); }}><ChevronDown className="size-4" /></IconButton>
                                {rows.length < options.max_rows && <IconButton label={lt('letterheads.duplicateRow')} onClick={() => updateRows((rs) => { rs.splice(r + 1, 0, structuredClone(rs[r])); return rs; })}><CopyIcon className="size-4" /></IconButton>}
                                <IconButton label={lt('letterheads.removeRow')} danger onClick={() => { updateRows((rs) => rs.filter((_, x) => x !== r)); select(null); }}><TrashIcon className="size-4" /></IconButton>
                            </span>
                        </header>

                        <div className="flex gap-2 overflow-x-auto p-3">
                            {row.cells.map((cell, c) => (
                                <div key={c} className="flex min-w-[8.5rem] flex-col gap-2 rounded-control bg-(--app-muted)/50 p-2" style={{ flex: `${Math.max(cell.width > 0 ? cell.width : share, 15)} 1 0` }}>
                                    <div className="flex min-h-6 items-center gap-1.5 text-xs text-(--app-muted-foreground)" title={lt('letterheads.column', { n: c + 1 })}>
                                        <span className="inline-flex size-5 items-center justify-center rounded-full bg-(--app-card) font-semibold tabular-nums" aria-label={lt('letterheads.column', { n: c + 1 })}>{c + 1}</span>
                                        <span className="tabular-nums">{Math.round(cell.width > 0 ? cell.width : share)}%</span>
                                        {row.cells.length > 1 && (
                                            <button type="button" className="ms-auto rounded p-1 hover:bg-(--app-card) hover:text-red-700 focus-visible:outline-2 focus-visible:outline-[#122170]" aria-label={lt('letterheads.removeColumn')} title={lt('letterheads.removeColumn')}
                                                onClick={() => { updateRows((rs) => { rs[r].cells.splice(c, 1); return rs; }); select(null); }}>
                                                <X className="size-3.5" aria-hidden="true" />
                                            </button>
                                        )}
                                    </div>
                                    {cell.items.length === 0 && <p className="text-xs text-(--app-muted-foreground)">{lt('letterheads.emptyColumn')}</p>}
                                    {cell.items.map((item, i) => {
                                        const active = selection?.section === section && selection.r === r && selection.c === c && selection.i === i;
                                        return (
                                            <button key={i} type="button" aria-pressed={active} onClick={() => select(active ? null : { section, r, c, i })}
                                                className={cx('flex min-h-11 w-full min-w-0 items-center gap-2 rounded-control border bg-(--app-card) px-2 py-1.5 transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#122170]',
                                                    active ? 'border-[#122170] ring-2 ring-[#122170]/25 dark:border-blue-300' : 'border-(--app-border) hover:border-[#122170]/50',
                                                    cell.align === 'center' ? 'justify-center text-center' : cell.align === 'right' ? 'justify-end text-right' : 'justify-start text-left')}>
                                                {item.type === 'image' ? (
                                                    imageUrl(item.image)
                                                        ? <img src={imageUrl(item.image) as string} alt={imageChoices.find((x) => x.key === item.image)?.name ?? ''} className="h-8 max-w-24 object-contain" />
                                                        : <span className="inline-flex items-center gap-1 text-xs text-(--app-muted-foreground)"><GalleryIcon className="size-4" aria-hidden="true" />{lt('letterheads.chooseImage')}</span>
                                                ) : (
                                                    <ChipText item={item} placeholderLabel={placeholderLabel} />
                                                )}
                                                {item.language !== 'both' && <span className="shrink-0 rounded bg-(--app-muted) px-1 text-[10px] font-semibold uppercase">{lt(`letterheads.languagesShort.${item.language}`)}</span>}
                                            </button>
                                        );
                                    })}
                                    {cell.items.length < options.max_items && (
                                        <div className="mt-auto flex justify-center gap-1 pt-1">
                                            <SmallButton label={lt('letterheads.addText')} onClick={() => addLine(r, c, 'text')}><PlusIcon className="size-3.5" aria-hidden="true" /><TextLineIcon className="size-4" aria-hidden="true" /></SmallButton>
                                            <SmallButton label={lt('letterheads.addImage')} onClick={() => addLine(r, c, 'image')}><PlusIcon className="size-3.5" aria-hidden="true" /><ImageAddIcon className="size-4" aria-hidden="true" /></SmallButton>
                                        </div>
                                    )}
                                </div>
                            ))}
                            {row.cells.length < options.max_cells && (
                                <button type="button" onClick={() => updateRows((rs) => { rs[r].cells.push(newCell(rs[r].cells.length === 1 ? 'right' : 'center')); return rs; })}
                                    className="flex w-11 shrink-0 items-center justify-center rounded-control border border-dashed border-(--app-border) text-(--app-muted-foreground) transition-colors duration-150 hover:border-[#122170] hover:text-[#122170] focus-visible:outline-2 focus-visible:outline-[#122170]"
                                    aria-label={lt('letterheads.addColumn')} title={lt('letterheads.addColumn')}>
                                    <PlusIcon className="size-5" aria-hidden="true" />
                                </button>
                            )}
                        </div>

                        <details className="group border-t border-(--app-border)">
                            <summary className="flex min-h-10 cursor-pointer list-none items-center gap-2 px-3 text-sm text-(--app-muted-foreground) hover:text-(--app-foreground) focus-visible:outline-2 focus-visible:outline-[#122170] [&::-webkit-details-marker]:hidden">
                                <RulerIcon className="size-4" aria-hidden="true" />{lt('letterheads.rowStyle')}
                                <ChevronDown className="ms-auto size-4 transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" />
                            </summary>
                            <div className="grid gap-4 px-3 pb-3 sm:grid-cols-2">
                                <div className="sm:col-span-2">
                                    <Segmented label={lt('letterheads.border')} value={row.border_position} full onChange={(v) => updateRows((rs) => { rs[r].border_position = v as LayoutRow['border_position']; return rs; })}
                                        options={(['none', 'top', 'bottom', 'both'] as const).map((p) => [p, lt(`letterheads.borderPositions.${p}`)])} />
                                </div>
                                {row.border_position !== 'none' && (
                                    <>
                                        <Segmented label={lt('letterheads.borderStyle')} value={row.border_style} onChange={(v) => updateRows((rs) => { rs[r].border_style = v as LayoutRow['border_style']; return rs; })}
                                            options={(['thin', 'thick', 'double'] as const).map((p) => [p, lt(`letterheads.borderStyles.${p}`)])} />
                                        <Stepper id={`lf-${section}-${r}-space`} label={lt('letterheads.spaceAfter')} value={row.space_after} min={0} max={30} step={1} onChange={(v) => updateRows((rs) => { rs[r].space_after = v; return rs; })} />
                                        <div className="sm:col-span-2"><ColorPicker label={lt('letterheads.borderColor')} value={row.border_color} onChange={(v) => updateRows((rs) => { rs[r].border_color = v; return rs; })} /></div>
                                    </>
                                )}
                                {row.border_position === 'none' && <Stepper id={`lf-${section}-${r}-space`} label={lt('letterheads.spaceAfter')} value={row.space_after} min={0} max={30} step={1} onChange={(v) => updateRows((rs) => { rs[r].space_after = v; return rs; })} />}
                            </div>
                        </details>
                    </article>
                );
            })}
            {rows.length < options.max_rows && (
                <button type="button" onClick={() => updateRows((r) => [...r, newRow()])}
                    className="flex min-h-12 w-full items-center justify-center gap-2 rounded-control border border-dashed border-(--app-border) text-sm font-medium text-(--app-muted-foreground) transition-colors duration-200 hover:border-[#122170] hover:text-[#122170] focus-visible:outline-2 focus-visible:outline-[#122170]">
                    <RowAddIcon className="size-5" aria-hidden="true" />{lt('letterheads.addRow')}
                </button>
            )}
        </div>
    );
}

/** A line as it reads: its own colour, weight and underline; placeholders as named tokens. */
function ChipText({ item, placeholderLabel }: { item: LayoutItem; placeholderLabel: (key: string) => string }): JSX.Element {
    const { lt } = useLetters();
    const text = item.text ?? '';
    if (text.trim() === '') return <span className="text-xs italic text-(--app-muted-foreground)">{lt('letterheads.emptyText')}</span>;
    const parts: ReactNode[] = [];
    let last = 0;
    for (const match of text.matchAll(TOKEN)) {
        const at = match.index ?? 0;
        if (at > last) parts.push(text.slice(last, at));
        parts.push(<span key={at} className="mx-0.5 inline-block rounded bg-[#122170]/10 px-1 text-[0.8em] font-normal text-[#122170] dark:bg-blue-300/15 dark:text-blue-200">{placeholderLabel(match[1])}</span>);
        last = at + match[0].length;
    }
    if (last < text.length) parts.push(text.slice(last));

    return (
        <span className="line-clamp-2 min-w-0 break-words" style={{ color: item.color, fontWeight: item.bold ? 700 : 400, textDecoration: item.underline ? 'underline' : undefined, fontSize: `${Math.min(17, Math.max(12, (item.font_size ?? 10) * 1.05))}px` }}>
            {parts}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/* Inspector                                                           */
/* ------------------------------------------------------------------ */

function Inspector({ selection, item, cell, options, imageChoices, textRef, placeholderLabel, updateItem, updateCell, onInsert, onClose, onMove, onDelete, onUploadRequest }: {
    selection: NonNullable<Selection>; item: LayoutItem; cell: LayoutCell; options: LayoutEditorOptions; imageChoices: ImageChoice[];
    textRef: MutableRefObject<HTMLTextAreaElement | null>; placeholderLabel: (key: string) => string;
    updateItem: (change: (item: LayoutItem) => void) => void; updateCell: (change: (cell: LayoutCell) => void) => void; onInsert: (key: string) => void;
    onClose: () => void; onMove: (delta: number) => void; onDelete: () => void; onUploadRequest: () => void;
}): JSX.Element {
    const { lt } = useLetters();
    const fontKey = item.font_family || '';
    const font = options.fonts.find((f) => f.key === (fontKey || 'abyssinica'));
    const id = 'lf-inspector';
    const alignOptions: SegmentOption[] = [
        ['left', lt('letterheads.aligns.left'), <AlignLeftIcon key="l" className="size-4" aria-hidden="true" />],
        ['center', lt('letterheads.aligns.center'), <AlignCenterIcon key="c" className="size-4" aria-hidden="true" />],
        ['right', lt('letterheads.aligns.right'), <AlignRightIcon key="r" className="size-4" aria-hidden="true" />],
    ];

    return (
        <section className={cx(panelCls, 'overflow-hidden ring-1 ring-[#122170]/20')} aria-labelledby={`${id}-title`}>
            <header className="flex items-center gap-2 border-b border-(--app-border) bg-(--app-muted)/50 px-4 py-2">
                {item.type === 'text' ? <TextLineIcon className="size-5 text-[#122170] dark:text-blue-200" aria-hidden="true" /> : <GalleryIcon className="size-5 text-[#122170] dark:text-blue-200" aria-hidden="true" />}
                <div className="min-w-0">
                    <h2 id={`${id}-title`} className="text-sm font-semibold">{item.type === 'text' ? lt('letterheads.lineText') : lt('letterheads.lineImage')}</h2>
                    <p className="truncate text-xs text-(--app-muted-foreground)">{lt('letterheads.location', { section: lt(`letterheads.${selection.section}`), row: selection.r + 1, column: selection.c + 1 })}</p>
                </div>
                <span className="ms-auto flex gap-0.5">
                    <IconButton label={lt('letterheads.moveUp')} disabled={selection.i === 0} onClick={() => onMove(-1)}><ChevronUp className="size-4" /></IconButton>
                    <IconButton label={lt('letterheads.moveDown')} disabled={selection.i >= cell.items.length - 1} onClick={() => onMove(1)}><ChevronDown className="size-4" /></IconButton>
                    <IconButton label={lt('letterheads.removeItem')} danger onClick={onDelete}><TrashIcon className="size-4" /></IconButton>
                    <IconButton label={lt('letterheads.close')} onClick={onClose}><X className="size-4" /></IconButton>
                </span>
            </header>

            <div className="space-y-4 p-4">
                {item.type === 'text' ? (
                    <>
                        <div>
                            <label htmlFor={`${id}-text`} className={labelCls}>{lt('letterheads.text')}</label>
                            <textarea id={`${id}-text`} ref={textRef} rows={3} className={textareaCls} value={item.text ?? ''} maxLength={500}
                                onChange={(e) => updateItem((x) => { x.text = e.target.value; })} aria-describedby={`${id}-text-help`} />
                            <p id={`${id}-text-help`} className="mt-1 text-xs text-(--app-muted-foreground)">{lt('letterheads.textHelp')}</p>
                        </div>
                        <div>
                            <p className={labelCls} id={`${id}-ph`}>{lt('letterheads.placeholders')}</p>
                            <div className="flex flex-wrap gap-1.5" role="group" aria-labelledby={`${id}-ph`}>
                                {options.placeholders.map((p) => (
                                    <button key={p} type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => onInsert(p)} title={`{{${p}}}`}
                                        className="inline-flex min-h-8 items-center gap-1 rounded-full border border-(--app-border) px-2.5 text-xs transition-colors duration-150 hover:border-[#122170] hover:text-[#122170] focus-visible:outline-2 focus-visible:outline-[#122170]">
                                        <PlusIcon className="size-3" aria-hidden="true" />{placeholderLabel(p)}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <fieldset className="space-y-3 rounded-control border border-(--app-border) p-3">
                            <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{lt('letterheads.format')}</legend>
                            <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                                <div>
                                    <label htmlFor={`${id}-font`} className={labelCls}>{lt('letterheads.font')}</label>
                                    <select id={`${id}-font`} className={inputCls} value={fontKey} onChange={(e) => updateItem((x) => { x.font_family = e.target.value; })}>
                                        <option value="">{lt('letterheads.fontInherit')}</option>
                                        {options.fonts.map((f) => <option key={f.key} value={f.key}>{f.family}</option>)}
                                    </select>
                                </div>
                                <Stepper id={`${id}-size`} label={lt('letterheads.size')} value={item.font_size ?? 10} min={6} max={28} step={0.5} onChange={(v) => updateItem((x) => { x.font_size = v; })} />
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Toggle label={lt('letterheads.bold')} pressed={!!item.bold} onChange={(v) => updateItem((x) => { x.bold = v; })}><BoldIcon className="size-4" aria-hidden="true" /></Toggle>
                                <Toggle label={lt('letterheads.underline')} pressed={!!item.underline} onChange={(v) => updateItem((x) => { x.underline = v; })}><UnderlineIcon className="size-4" aria-hidden="true" /></Toggle>
                                <span className="mx-1 h-6 w-px bg-(--app-border)" aria-hidden="true" />
                                <Segmented label={lt('letterheads.align')} value={cell.align} iconOnly onChange={(v) => updateCell((c) => { c.align = v as LayoutCell['align']; })} options={alignOptions} />
                            </div>
                            {item.bold && font && !font.bold && <p className="text-xs text-amber-800 dark:text-amber-300" role="note">{lt('letterheads.noBold')}</p>}
                            <ColorPicker label={lt('letterheads.color')} value={item.color ?? '#111111'} onChange={(v) => updateItem((x) => { x.color = v; })} />
                        </fieldset>

                        <Segmented label={lt('letterheads.language')} value={item.language} full onChange={(v) => updateItem((x) => { x.language = v as LayoutItem['language']; })}
                            options={(['both', 'am', 'en'] as const).map((l) => [l, lt(`letterheads.languages.${l}`)])} />
                    </>
                ) : (
                    <>
                        <div>
                            <p className={labelCls} id={`${id}-imgs`}>{lt('letterheads.image')}</p>
                            {imageChoices.length === 0 ? (
                                <button type="button" className={cx(secondaryBtn, 'inline-flex items-center gap-1.5')} onClick={onUploadRequest}><ImageAddIcon className="size-4" aria-hidden="true" />{lt('letterheads.uploadImage')}</button>
                            ) : (
                                <div className="grid grid-cols-3 gap-2" role="radiogroup" aria-labelledby={`${id}-imgs`}>
                                    {imageChoices.map((choice) => (
                                        <button key={choice.key} type="button" role="radio" aria-checked={item.image === choice.key} onClick={() => updateItem((x) => { x.image = choice.key; })}
                                            className={cx('flex min-h-20 flex-col items-center justify-center gap-1 rounded-control border p-2 text-center transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-[#122170]',
                                                item.image === choice.key ? 'border-[#122170] ring-2 ring-[#122170]/25' : 'border-(--app-border) hover:border-[#122170]/50')}>
                                            {choice.url ? <img src={choice.url} alt="" className="h-10 max-w-full object-contain" /> : <GalleryIcon className="size-6 text-(--app-muted-foreground)" aria-hidden="true" />}
                                            <span className="line-clamp-1 text-[11px]">{choice.name}</span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                        <div>
                            <label htmlFor={`${id}-w`} className={labelCls}>{lt('letterheads.imageWidth')}: <span className="tabular-nums">{item.width_mm ?? 22}</span></label>
                            <input id={`${id}-w`} type="range" min={5} max={120} step={1} value={item.width_mm ?? 22} onChange={(e) => updateItem((x) => { x.width_mm = Number(e.target.value); })} className="w-full accent-[#122170]" />
                        </div>
                        <div className="flex items-center gap-3">
                            <span className={labelCls}>{lt('letterheads.align')}</span>
                            <Segmented label={lt('letterheads.align')} value={cell.align} iconOnly onChange={(v) => updateCell((c) => { c.align = v as LayoutCell['align']; })} options={alignOptions} />
                        </div>
                    </>
                )}

                <fieldset className="grid gap-3 rounded-control border border-(--app-border) p-3 sm:grid-cols-2">
                    <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-(--app-muted-foreground)">{lt('letterheads.columnSection', { n: selection.c + 1 })}</legend>
                    <Stepper id={`${id}-cw`} label={lt('letterheads.width')} value={cell.width} min={0} max={100} step={5} onChange={(v) => updateCell((c) => { c.width = v; })} help={lt('letterheads.widthHelp')} />
                    <Segmented label={lt('letterheads.valign')} value={cell.valign} onChange={(v) => updateCell((c) => { c.valign = v as LayoutCell['valign']; })}
                        options={(['top', 'middle', 'bottom'] as const).map((a) => [a, lt(`letterheads.valigns.${a}`)])} />
                </fieldset>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Page and images                                                     */
/* ------------------------------------------------------------------ */

function PagePanel({ page, setPage, options }: { page: LayoutPage; setPage: (p: LayoutPage) => void; options: LayoutEditorOptions }): JSX.Element {
    const { lt } = useLetters();
    const margin = (key: 'margin_top' | 'margin_right' | 'margin_bottom' | 'margin_left', label: string) => (
        <label className="flex flex-col items-center gap-1 text-xs">
            <span className="text-(--app-muted-foreground)">{label}</span>
            <input type="number" min={5} max={70} step={1} value={page[key]} className={cx(inputCls, 'w-20 text-center tabular-nums')}
                onChange={(e) => { const v = Number(e.target.value); setPage({ ...page, [key]: Number.isFinite(v) ? Math.min(70, Math.max(5, v)) : 5 }); }} />
        </label>
    );

    return (
        <div className="space-y-6">
            <fieldset>
                <legend className="mb-2 text-sm font-semibold">{lt('letterheads.paper')}</legend>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4" role="radiogroup" aria-label={lt('letterheads.paper')}>
                    {options.papers.map((p) => {
                        const [w, h] = PAPER_MM[p] ?? PAPER_MM.a4;
                        const active = page.paper === p;
                        return (
                            <button key={p} type="button" role="radio" aria-checked={active} onClick={() => setPage({ ...page, paper: p })}
                                className={cx('flex min-h-28 flex-col items-center justify-end gap-2 rounded-control border p-3 transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-[#122170]',
                                    active ? 'border-[#122170] bg-[#122170]/5 ring-2 ring-[#122170]/20 dark:bg-blue-300/10' : 'border-(--app-border) hover:border-[#122170]/50')}>
                                <span className={cx('block rounded-sm border bg-(--app-card)', active ? 'border-[#122170]' : 'border-(--app-muted-foreground)/60')} style={page.orientation === 'landscape' ? { width: h / 6, height: w / 6 } : { width: w / 6, height: h / 6 }} aria-hidden="true" />
                                <span className="text-sm font-medium">{lt(`letterheads.papers.${p}`)}</span>
                                <span className="text-[11px] tabular-nums text-(--app-muted-foreground)">{w} × {h} mm</span>
                            </button>
                        );
                    })}
                </div>
            </fieldset>

            <div className="grid gap-4 sm:grid-cols-2">
                <Segmented label={lt('letterheads.orientation')} value={page.orientation} onChange={(v) => setPage({ ...page, orientation: v })} options={options.orientations.map((o) => [o, lt(`letterheads.orientations.${o}`)])} />
                <Segmented label={lt('letterheads.referenceAlign')} value={page.reference_align} onChange={(v) => setPage({ ...page, reference_align: v as LayoutPage['reference_align'] })}
                    options={(['left', 'right'] as const).map((a) => [a, lt(`letterheads.referenceAligns.${a}`)])} />
            </div>

            <fieldset>
                <legend className="mb-2 text-sm font-semibold">{lt('letterheads.margins')}</legend>
                <div className="grid max-w-md grid-cols-[auto_1fr_auto] grid-rows-[auto_1fr_auto] items-center justify-items-center gap-2">
                    <span />{margin('margin_top', lt('letterheads.marginTop'))}<span />
                    {margin('margin_left', lt('letterheads.marginLeft'))}
                    <div className="flex h-24 w-full items-center justify-center rounded-sm border-2 border-dashed border-(--app-border) px-2 text-center text-xs text-(--app-muted-foreground)">{lt('letterheads.textArea')}</div>
                    {margin('margin_right', lt('letterheads.marginRight'))}
                    <span />{margin('margin_bottom', lt('letterheads.marginBottom'))}<span />
                </div>
                <p className="mt-2 text-xs text-(--app-muted-foreground)">{lt('letterheads.marginHelp')}</p>
            </fieldset>

            <fieldset className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto]">
                <legend className="mb-2 text-sm font-semibold">{lt('letterheads.letterText')}</legend>
                <div>
                    <label htmlFor="lf-body-font" className={labelCls}>{lt('letterheads.bodyFont')}</label>
                    <select id="lf-body-font" className={inputCls} value={page.font_family} onChange={(e) => setPage({ ...page, font_family: e.target.value })}>
                        {options.fonts.map((f) => <option key={f.key} value={f.key}>{f.family}</option>)}
                    </select>
                </div>
                <Stepper id="lf-body-size" label={lt('letterheads.fontSize')} value={page.font_size} min={8} max={16} step={0.5} onChange={(v) => setPage({ ...page, font_size: v })} />
                <div className="sm:col-span-2"><ColorPicker label={lt('letterheads.textColor')} value={page.text_color} onChange={(v) => setPage({ ...page, text_color: v })} /></div>
            </fieldset>
        </div>
    );
}

function ImagesPanel({ options, imageChoices, uploadedCount, usage, addImages, removeImage, stampPreview, hasStoredStamp, removeStamp, setStamp, setRemoveStamp, errors }: {
    options: LayoutEditorOptions; imageChoices: ImageChoice[]; uploadedCount: number; usage: (key: string) => number; addImages: (f: FileList | File[] | null) => void; removeImage: (key: string) => void;
    stampPreview: string | null; hasStoredStamp: boolean; removeStamp: boolean; setStamp: (f: File | null) => void; setRemoveStamp: (v: boolean) => void; errors: Record<string, string>;
}): JSX.Element {
    const { lt } = useLetters();
    const [dragging, setDragging] = useState(false);
    const onDrop = (e: DragEvent<HTMLLabelElement>) => { e.preventDefault(); setDragging(false); addImages(e.dataTransfer.files); };

    return (
        <div className="space-y-6">
            <section aria-labelledby="lf-gallery">
                <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h3 id="lf-gallery" className="text-sm font-semibold">{lt('letterheads.images')}</h3>
                    <span className="text-xs tabular-nums text-(--app-muted-foreground)">{uploadedCount} / {options.max_images}</span>
                </div>
                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {imageChoices.map((image) => {
                        const used = usage(image.key);
                        const builtIn = image.key === options.org_logo;
                        return (
                            <li key={image.key} className="flex flex-col overflow-hidden rounded-control border border-(--app-border)">
                                <div className="flex h-24 items-center justify-center bg-white p-2 dark:bg-gray-100">
                                    {image.url ? <img src={image.url} alt="" className="max-h-full max-w-full object-contain" /> : <GalleryIcon className="size-8 text-gray-400" aria-hidden="true" />}
                                </div>
                                <div className="flex flex-1 flex-col gap-1 border-t border-(--app-border) p-2">
                                    <span className="line-clamp-2 text-xs font-medium" title={image.name}>{image.name}</span>
                                    <span className="text-[11px] text-(--app-muted-foreground)">{lt('letterheads.usedIn', { count: used })}</span>
                                    {!builtIn && (
                                        <button type="button" className="mt-auto inline-flex min-h-9 items-center justify-center gap-1 rounded-control text-xs text-red-700 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-[#122170] dark:text-red-400 dark:hover:bg-red-950/40"
                                            onClick={() => removeImage(image.key)} title={used > 0 ? lt('letterheads.removeImageUsed') : undefined}>
                                            <TrashIcon className="size-4" aria-hidden="true" />{lt('letterheads.removeImage')}
                                        </button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                    {uploadedCount < options.max_images && (
                        <li>
                            <label onDragOver={(e) => { e.preventDefault(); setDragging(true); }} onDragLeave={() => setDragging(false)} onDrop={onDrop}
                                className={cx('flex h-full min-h-40 cursor-pointer flex-col items-center justify-center gap-2 rounded-control border-2 border-dashed p-3 text-center text-sm transition-colors duration-150 focus-within:outline-2 focus-within:outline-[#122170]',
                                    dragging ? 'border-[#122170] bg-[#122170]/5 text-[#122170]' : 'border-(--app-border) text-(--app-muted-foreground) hover:border-[#122170] hover:text-[#122170]')}>
                                <ImageAddIcon className="size-7" aria-hidden="true" />
                                <span className="font-medium">{lt('letterheads.uploadImage')}</span>
                                <span className="text-xs">{lt('letterheads.dropHere')}</span>
                                <input type="file" accept="image/png,image/jpeg,image/svg+xml" multiple className="sr-only" onChange={(e) => { addImages(e.target.files); e.target.value = ''; }} />
                            </label>
                        </li>
                    )}
                </ul>
                <p className="mt-2 text-xs text-(--app-muted-foreground)">{lt('letterheads.imagesHelp', { max: options.max_images })}</p>
                {errors.new_images && <p className={errorCls} role="alert">{errors.new_images}</p>}
            </section>

            <section className="grid gap-4 rounded-control border border-(--app-border) p-4 sm:grid-cols-[8rem_minmax(0,1fr)]" aria-labelledby="lf-stamp-title">
                <div className="flex size-32 items-center justify-center rounded-full border border-dashed border-(--app-border) bg-white dark:bg-gray-100">
                    {stampPreview ? <img src={stampPreview} alt="" className="max-h-28 max-w-28 object-contain" /> : <span className="px-2 text-center text-xs text-gray-500">{lt('letterheads.noStamp')}</span>}
                </div>
                <div className="space-y-2">
                    <h3 id="lf-stamp-title" className="text-sm font-semibold">{lt('letterheads.stamp')}</h3>
                    <p className="text-xs text-(--app-muted-foreground)">{lt('letterheads.stampHelp')}</p>
                    <label className={cx(secondaryBtn, 'inline-flex cursor-pointer items-center gap-1.5 focus-within:outline-2 focus-within:outline-[#122170]')}>
                        <ImageAddIcon className="size-4" aria-hidden="true" />{lt('letterheads.chooseStamp')}
                        <input type="file" accept="image/png,image/jpeg,image/svg+xml" className="sr-only" onChange={(e) => setStamp(e.target.files?.[0] ?? null)} />
                    </label>
                    {hasStoredStamp && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={removeStamp} onChange={(e) => setRemoveStamp(e.target.checked)} /> {lt('letterheads.removeStamp')}</label>}
                    {errors.stamp && <p className={errorCls} role="alert">{errors.stamp}</p>}
                </div>
            </section>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Live preview                                                        */
/* ------------------------------------------------------------------ */

function fillText(text: string, vars: Record<string, string>): string {
    return text.replace(TOKEN, (_, key: string) => vars[key] ?? '');
}

function prints(item: LayoutItem, language: string, vars: Record<string, string>): boolean {
    if (!['both', language].includes(item.language ?? 'both')) return false;
    if (item.type === 'image') return true;
    return fillText(item.text ?? '', vars).replace(/[\s·|,\-–—/:]+/g, '') !== '';
}

const BORDER: Record<string, string> = { thin: '1px solid', thick: '2.5px solid', double: '3px double' };

function Rows({ section, rows, page, vars, language, imageUrl, selection, onSelect }: {
    section: SectionKey; rows: LayoutRow[]; page: LayoutPage; vars: Record<string, string>; language: string; imageUrl: (key?: string) => string | null; selection: Selection; onSelect: (s: Selection) => void;
}): JSX.Element {
    return (
        <>
            {rows.map((row, r) => {
                const line = `${BORDER[row.border_style] ?? '1px solid'} ${row.border_color}`;
                const top = row.border_position === 'top' || row.border_position === 'both';
                const bottom = row.border_position === 'bottom' || row.border_position === 'both';
                const style: CSSProperties = { marginBottom: `${row.space_after}mm`, borderTop: top ? line : undefined, borderBottom: bottom ? line : undefined, paddingTop: top ? 3 : undefined, paddingBottom: bottom ? 3 : undefined };
                const fixed = row.cells.reduce((sum, c) => sum + (c.width > 0 ? c.width : 0), 0);
                const autos = row.cells.filter((c) => c.width <= 0).length;
                const share = autos > 0 ? Math.max(0, 100 - fixed) / autos : 0;

                return (
                    <div key={r} className="flex w-full" style={style}>
                        {row.cells.map((cell, c) => (
                            <div key={c} className="flex flex-col px-0.5" style={{ width: `${cell.width > 0 ? cell.width : share}%`, textAlign: cell.align, justifyContent: cell.valign === 'top' ? 'flex-start' : cell.valign === 'bottom' ? 'flex-end' : 'center' }}>
                                {cell.items.map((item, i) => {
                                    if (!prints(item, language, vars)) return null;
                                    const active = selection?.section === section && selection.r === r && selection.c === c && selection.i === i;
                                    const ring = cx('cursor-pointer rounded-[2px] outline-offset-2', active ? 'outline outline-2 outline-[#1D4ED8]' : 'hover:outline hover:outline-1 hover:outline-dashed hover:outline-[#1D4ED8]/60');
                                    const pickThis = (e: MouseEvent) => { e.stopPropagation(); onSelect({ section, r, c, i }); };
                                    if (item.type === 'image') {
                                        const url = imageUrl(item.image);
                                        return url ? <div key={i} className={ring} onClick={pickThis} style={{ textAlign: cell.align }}><img src={url} alt="" style={{ width: `${item.width_mm}mm`, maxHeight: `${(item.width_mm ?? 22) * 1.4}mm`, display: 'inline-block' }} /></div> : null;
                                    }
                                    return (
                                        <div key={i} className={ring} onClick={pickThis} style={{
                                            fontFamily: FONT_CSS[item.font_family || page.font_family] ?? FONT_CSS.abyssinica, fontSize: `${item.font_size}pt`, lineHeight: 1.3, color: item.color,
                                            fontWeight: item.bold ? 700 : 400, textDecoration: item.underline ? 'underline' : undefined, whiteSpace: 'pre-line',
                                        }}>{fillText(item.text ?? '', vars)}</div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                );
            })}
        </>
    );
}

/** The page to scale: margins, header, a stand-in body with the reference block and stamp, and the footer in the bottom margin. Lines are clickable. */
function Sheet({ page, sections, vars, language, imageUrl, stamp, selection, onSelect, maxHeightRatio }: {
    page: LayoutPage; sections: Record<SectionKey, LayoutSection>; vars: Record<string, string>; language: string; imageUrl: (key?: string) => string | null; stamp: string | null;
    selection: Selection; onSelect: (s: Selection) => void; maxHeightRatio?: number;
}): JSX.Element {
    const { lt } = useLetters();
    const holder = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(0.5);
    const [w, h] = (() => { const [a, b] = PAPER_MM[page.paper] ?? PAPER_MM.a4; return page.orientation === 'landscape' ? [b, a] : [a, b]; })();

    // Fit the width, and on wide screens the height left beside the inspector, so the whole page stays in view.
    useEffect(() => {
        const el = holder.current;
        if (!el) return;
        const measure = () => {
            const byWidth = el.clientWidth / (w * MM_PX);
            const byHeight = maxHeightRatio ? (window.innerHeight * maxHeightRatio) / (h * MM_PX) : Infinity;
            setScale(Math.min(1, byWidth, byHeight));
        };
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        window.addEventListener('resize', measure);
        return () => { observer.disconnect(); window.removeEventListener('resize', measure); };
    }, [w, h, maxHeightRatio]);

    return (
        <div ref={holder} className="w-full">
            <div className="mx-auto overflow-hidden rounded-sm transition-[width,height] duration-200 motion-reduce:transition-none" style={{ width: w * MM_PX * scale, height: h * MM_PX * scale }}>
            <div className="relative bg-white text-black shadow-md ring-1 ring-black/10" style={{
                width: `${w}mm`, height: `${h}mm`, transform: `scale(${scale})`, transformOrigin: 'top left',
                padding: `${page.margin_top}mm ${page.margin_right}mm ${page.margin_bottom}mm ${page.margin_left}mm`,
                fontFamily: FONT_CSS[page.font_family] ?? FONT_CSS.abyssinica, fontSize: `${page.font_size}pt`, color: page.text_color,
            }}>
                <div className="pointer-events-none absolute border border-dashed border-sky-300/70" style={{ top: `${page.margin_top}mm`, right: `${page.margin_right}mm`, bottom: `${page.margin_bottom}mm`, left: `${page.margin_left}mm` }} aria-hidden="true" />
                <Rows section="header" rows={sections.header.rows} page={page} vars={vars} language={language} imageUrl={imageUrl} selection={selection} onSelect={onSelect} />
                <div className="mt-4" style={{ width: page.reference_align === 'right' ? '55%' : '100%', marginLeft: page.reference_align === 'right' ? '45%' : 0 }}>
                    <div>{lt('common.number')}: <strong>{vars['letter.number']}</strong></div>
                    <div>{lt('common.date')}: <strong>{vars['letter.date_ec']}</strong></div>
                </div>
                <div className="mt-6 space-y-2" aria-hidden="true">
                    {[92, 100, 96, 88, 100, 70].map((width, i) => <div key={i} className="h-2 rounded bg-gray-200" style={{ width: `${width}%` }} />)}
                </div>
                <div className="mt-8 flex items-end justify-between" aria-hidden="true">
                    <div className="h-3 w-32 rounded bg-gray-200" />
                    {stamp && <img src={stamp} alt="" style={{ width: '32mm', opacity: 0.9 }} />}
                </div>
                <div className="absolute" style={{ left: `${page.margin_left}mm`, right: `${page.margin_right}mm`, bottom: '6mm' }}>
                    <Rows section="footer" rows={sections.footer.rows} page={page} vars={vars} language={language} imageUrl={imageUrl} selection={selection} onSelect={onSelect} />
                </div>
            </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Controls                                                            */
/* ------------------------------------------------------------------ */

function Switch({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }): JSX.Element {
    return (
        <button type="button" role="switch" aria-checked={checked} onClick={() => onChange(!checked)} className="group inline-flex min-h-11 items-center gap-3 text-start text-sm focus-visible:outline-none">
            <span className={cx('relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 group-focus-visible:outline-2 group-focus-visible:outline-offset-2 group-focus-visible:outline-[#122170]', checked ? 'bg-[#122170]' : 'bg-gray-300 dark:bg-gray-600')}>
                <span className={cx('inline-block size-5 rounded-full bg-white shadow transition-transform duration-200 motion-reduce:transition-none', checked ? 'translate-x-5.5 rtl:-translate-x-5.5' : 'translate-x-0.5 rtl:-translate-x-0.5')} />
            </span>
            {label}
        </button>
    );
}

function Segmented({ label, value, onChange, options, full, iconOnly, hideLabel }: { label: string; value: string; onChange: (v: string) => void; options: SegmentOption[]; full?: boolean; iconOnly?: boolean; hideLabel?: boolean }): JSX.Element {
    return (
        <div className={full ? 'w-full' : undefined}>
            {!iconOnly && !hideLabel && <p className={labelCls}>{label}</p>}
            <div role="radiogroup" aria-label={label} className={cx('inline-flex rounded-control border border-(--app-border) bg-(--app-muted)/50 p-0.5', full && 'flex w-full')}>
                {options.map(([v, text, icon]) => (
                    <button key={v} type="button" role="radio" aria-checked={value === v} aria-label={iconOnly ? text : undefined} title={iconOnly ? text : undefined} onClick={() => onChange(v)}
                        className={cx('inline-flex min-h-9 items-center justify-center gap-1 rounded-[5px] px-3 text-sm transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-[#122170]',
                            full && 'flex-1', iconOnly && 'w-9 px-0',
                            value === v ? 'bg-(--app-card) font-medium text-[#122170] shadow-sm dark:text-blue-200' : 'text-(--app-muted-foreground) hover:text-(--app-foreground)')}>
                        {iconOnly ? icon ?? text : text}
                    </button>
                ))}
            </div>
        </div>
    );
}

function Toggle({ label, pressed, onChange, children }: { label: string; pressed: boolean; onChange: (v: boolean) => void; children: ReactNode }): JSX.Element {
    return (
        <button type="button" aria-pressed={pressed} aria-label={label} title={label} onClick={() => onChange(!pressed)}
            className={cx('inline-flex size-9 items-center justify-center rounded-control border transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#122170]',
                pressed ? 'border-[#122170] bg-[#122170] text-white' : 'border-(--app-border) hover:border-[#122170]/60')}>
            {children}
        </button>
    );
}

function Stepper({ id, label, value, min, max, step, onChange, help }: { id: string; label: string; value: number; min: number; max: number; step: number; onChange: (v: number) => void; help?: string }): JSX.Element {
    const { lt } = useLetters();
    const clamp = (v: number) => Math.min(max, Math.max(min, Math.round(v * 10) / 10));
    const btn = 'inline-flex w-9 items-center justify-center text-lg transition-colors duration-150 hover:bg-(--app-muted) focus-visible:outline-2 focus-visible:outline-[#122170] disabled:opacity-40';
    return (
        <div>
            <label htmlFor={id} className={labelCls}>{label}</label>
            <div className="inline-flex h-10 items-stretch overflow-hidden rounded-control border border-(--app-border) bg-(--app-card)">
                <button type="button" className={btn} aria-label={`${lt('letterheads.decrease')}: ${label}`} disabled={value <= min} onClick={() => onChange(clamp(value - step))}>−</button>
                <input id={id} type="number" inputMode="decimal" value={value} min={min} max={max} step="any" aria-describedby={help ? `${id}-help` : undefined}
                    className="w-16 border-x border-(--app-border) bg-transparent text-center text-sm tabular-nums focus-visible:outline-2 focus-visible:outline-[#122170]"
                    onChange={(e) => { const v = Number(e.target.value); onChange(Number.isFinite(v) ? clamp(v) : min); }} />
                <button type="button" className={btn} aria-label={`${lt('letterheads.increase')}: ${label}`} disabled={value >= max} onClick={() => onChange(clamp(value + step))}>+</button>
            </div>
            {help && <p id={`${id}-help`} className="mt-1 text-xs text-(--app-muted-foreground)">{help}</p>}
        </div>
    );
}

function ColorPicker({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }): JSX.Element {
    const { lt } = useLetters();
    const current = value.toUpperCase();
    return (
        <div>
            <p className={labelCls}>{label}</p>
            <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label={label}>
                {SWATCHES.map((c) => (
                    <button key={c} type="button" aria-label={c} aria-pressed={current === c} title={c} onClick={() => onChange(c)}
                        className={cx('size-8 rounded-full border-2 border-white transition-transform duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#122170] motion-reduce:transition-none',
                            current === c ? 'scale-110 ring-2 ring-[#122170]' : 'ring-1 ring-black/15 hover:scale-105')}
                        style={{ backgroundColor: c }} />
                ))}
                <label className="ms-1 inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-full border border-(--app-border) px-2 text-xs focus-within:outline-2 focus-within:outline-[#122170]" title={lt('letterheads.customColor')}>
                    <input type="color" value={value} onChange={(e) => onChange(e.target.value.toUpperCase())} className="size-5 cursor-pointer rounded-full border-0 bg-transparent p-0" aria-label={lt('letterheads.customColor')} />
                    <span className="font-mono">{current}</span>
                </label>
            </div>
        </div>
    );
}

function IconButton({ label, onClick, disabled, danger, children }: { label: string; onClick: () => void; disabled?: boolean; danger?: boolean; children: ReactNode }): JSX.Element {
    return (
        <button type="button" aria-label={label} title={label} disabled={disabled} onClick={onClick}
            className={cx('inline-flex size-9 items-center justify-center rounded-control text-(--app-muted-foreground) transition-colors duration-150 hover:bg-(--app-muted) hover:text-(--app-foreground) focus-visible:outline-2 focus-visible:outline-[#122170] disabled:pointer-events-none disabled:opacity-35',
                danger && 'hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950/40 dark:hover:text-red-400')}>
            {children}
        </button>
    );
}

function SmallButton({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }): JSX.Element {
    return (
        <button type="button" onClick={onClick} aria-label={label} title={label}
            className="inline-flex min-h-9 items-center gap-0.5 rounded-control border border-transparent px-2 text-[#122170] transition-colors duration-150 hover:border-[#122170]/30 hover:bg-(--app-card) focus-visible:outline-2 focus-visible:outline-[#122170] dark:text-blue-200">
            {children}
        </button>
    );
}
