import { Component, Suspense, lazy, useCallback, useEffect, useId, useMemo, useRef, useState, type ErrorInfo, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Button, FieldError, FormDescription, FormLabel, Textarea, cx } from '@/Components/ui';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import { useDocumentTheme } from '@/hooks/useDocumentTheme';
import { useLocale } from '@/hooks/useLocale';
import { RICH_TEXT_PRESETS, isRichTextEmpty, isRichTextHtml, plainTextToRichText, richTextLength, type RichTextPreset } from '@/richtext/policy';
import type { PageProps } from '@/types';

// The editor library is fetched only when an editor is on screen.
const TinyMceAdapter = lazy(() => import('@/richtext/TinyMceAdapter'));

export interface RichTextEditorProps {
    label: ReactNode;
    /** The form's value: HTML from this editor, or legacy plain text. */
    value: string | null | undefined;
    /** Receives sanitizable HTML, or '' when the field holds no words. */
    onChange: (html: string) => void;
    error?: string;
    helperText?: ReactNode;
    required?: boolean;
    disabled?: boolean;
    /** Shown, focusable and selectable, but not editable. */
    readOnly?: boolean;
    placeholder?: string;
    preset?: RichTextPreset;
    /** Height of the writing area in px before it grows; defaults to the preset's. */
    minHeight?: number;
    /** Plain-text characters; shows a counter. Match the server rule (RichTextContent). */
    maxLength?: number;
    /** Show the counter without a limit. */
    showCount?: boolean;
    id?: string;
    /** For a classic form post, the value is also written to a hidden input of this name. */
    name?: string;
    /** Language of the text being written, when it differs from the interface (an Amharic field). */
    locale?: 'en' | 'am';
    className?: string;
}

const LIBRARY_STRINGS = {
    Undo: 'undo', Redo: 'redo', Bold: 'bold', Italic: 'italic', Underline: 'underline',
    'Bullet list': 'bulletList', 'Numbered list': 'numberedList', Link: 'link',
    'Insert/edit link': 'insertEditLink', 'Insert/Edit Link': 'linkDialogTitle', URL: 'url',
    'Text to display': 'textToDisplay', Title: 'title', 'Open link in...': 'openLinkIn',
    'Current window': 'currentWindow', 'New window': 'newWindow', 'Remove link': 'removeLink', 'Open link': 'openLink',
    'The URL you entered seems to be an email address. Do you want to add the required mailto: prefix?': 'emailPrompt',
    'The URL you entered seems to be an external link. Do you want to add the required https:// prefix?': 'externalPrompt',
    'Clear formatting': 'clearFormatting', Blocks: 'blocks', 'Align left': 'alignLeft', 'Align center': 'alignCenter',
    'Align right': 'alignRight', Justify: 'justify', Blockquote: 'blockquote', Table: 'table', 'Insert table': 'insertTable',
    'Table properties': 'tableProperties', 'Delete table': 'deleteTable', Row: 'row', Rows: 'rows', Column: 'column',
    Columns: 'columns', Cell: 'cell', 'Insert row before': 'insertRowBefore', 'Insert row after': 'insertRowAfter',
    'Delete row': 'deleteRow', 'Insert column before': 'insertColumnBefore', 'Insert column after': 'insertColumnAfter',
    'Delete column': 'deleteColumn', 'Merge cells': 'mergeCells', 'Split cell': 'splitCell', 'Header row': 'headerRow',
    'Header column': 'headerColumn', Save: 'save', Cancel: 'cancel', Close: 'close', Ok: 'ok', Yes: 'yes', No: 'no',
} as const;

/**
 * The one formatted-text field (docs/rich-text-editor.md). Every module uses
 * this; none imports the editor library.
 *
 *   <RichTextEditor label={t('…')} value={data.body} onChange={(html) => setData('body', html)}
 *       error={errors.body} preset="standard" maxLength={5000} required />
 *
 * Controlled: reset(), cancel and a failed submit show whatever the form holds.
 * Nothing is autosaved or kept in the browser. The server sanitizes on save.
 */
export default function RichTextEditor({
    label, value, onChange, error, helperText, required = false, disabled = false, readOnly = false,
    placeholder, preset = 'standard', minHeight, maxLength, showCount = false, id, name, locale, className,
}: RichTextEditorProps) {
    const { t, locale: uiLocale } = useLocale();
    const { props } = usePage<PageProps>();
    const theme = useDocumentTheme();
    const generatedId = useId();
    const fieldId = id ?? `rich-text-${generatedId.replace(/:/g, '')}`;
    const labelId = `${fieldId}-label`;
    const helperId = helperText ? `${fieldId}-helper` : undefined;
    const errorId = error ? `${fieldId}-error` : undefined;
    const countId = maxLength || showCount ? `${fieldId}-count` : undefined;
    const describedBy = [helperId, countId, errorId].filter(Boolean).join(' ') || undefined;
    const definition = RICH_TEXT_PRESETS[preset];
    const height = minHeight ?? definition.minHeight;
    const editorLocale = uiLocale === 'am' ? 'am' : 'en';
    const [expanded, setExpanded] = useState(false);
    const focusRef = useRef<(() => void) | null>(null);

    // Legacy plain text opens as escaped paragraphs, never parsed as markup.
    const editorValue = useMemo(() => (value && !isRichTextHtml(value) ? plainTextToRichText(value) : value ?? ''), [value]);
    const handleChange = useCallback((html: string) => onChange(isRichTextEmpty(html) ? '' : html), [onChange]);

    const translations = useMemo(
        () => Object.fromEntries(Object.entries(LIBRARY_STRINGS).map(([english, key]) => [english, t(`richText.ui.${key}`)])),
        [t],
    );
    const blockLabels = useMemo(() => ({
        paragraph: t('richText.blocks.paragraph'),
        heading2: t('richText.blocks.heading2'),
        heading3: t('richText.blocks.heading3'),
        heading4: t('richText.blocks.heading4'),
    }), [t]);

    useEffect(() => {
        if (!expanded) return undefined;
        const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setExpanded(false);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);

        return () => {
            document.body.style.overflow = overflow;
            window.removeEventListener('keydown', onKey);
        };
    }, [expanded]);

    const length = richTextLength(value);
    const over = maxLength !== undefined && length > maxLength;
    const fallbackRich = isRichTextHtml(value) && !isRichTextEmpty(value);

    return (
        <div
            className={cx(
                'space-y-1.5',
                expanded && 'fixed inset-0 z-70 overflow-y-auto bg-(--app-background) p-4 sm:p-8',
                className,
            )}
            role={expanded ? 'dialog' : undefined}
            aria-modal={expanded || undefined}
            aria-labelledby={expanded ? labelId : undefined}
        >
            <div className="flex items-end justify-between gap-3">
                <FormLabel id={labelId} required={required} onClick={() => focusRef.current?.()}>{label}</FormLabel>
                {definition.fullscreen && !disabled && (
                    <Button type="button" variant="ghost" size="sm" aria-pressed={expanded} onClick={() => setExpanded((open) => !open)}>
                        {expanded ? t('richText.collapse') : t('richText.expand')}
                    </Button>
                )}
            </div>

            <div
                className={cx(
                    'rich-text-field rounded-control border bg-(--app-surface)',
                    'focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/20',
                    error ? 'border-red-600 ring-red-600/20' : 'border-(--app-border-strong)',
                    (disabled || readOnly) && 'bg-(--app-surface-muted)',
                    disabled && 'cursor-not-allowed opacity-70',
                )}
                data-disabled={disabled || undefined}
                data-readonly={readOnly || undefined}
            >
                <EditorBoundary
                    fallback={fallbackRich ? (
                        <div className="space-y-3 p-3">
                            <p role="alert" className="text-helper text-amber-700 dark:text-amber-300">{t('richText.loadFailed')}</p>
                            <RichTextViewer value={value} />
                            <Button type="button" variant="secondary" size="sm" onClick={() => window.location.reload()}>{t('richText.reload')}</Button>
                        </div>
                    ) : (
                        <div className="space-y-2 p-2">
                            <p className="text-helper text-(--app-muted-foreground)">{t('richText.plainFallback')}</p>
                            <Textarea
                                id={fieldId}
                                value={value ?? ''}
                                onChange={(event) => onChange(event.target.value)}
                                aria-labelledby={labelId}
                                aria-describedby={describedBy}
                                aria-invalid={Boolean(error)}
                                required={required}
                                disabled={disabled}
                                readOnly={readOnly}
                                placeholder={placeholder}
                                style={{ minHeight: height }}
                            />
                        </div>
                    )}
                >
                    <Suspense
                        fallback={(
                            <div aria-busy="true" className="flex items-center px-3 text-sm text-(--app-muted-foreground)" style={{ minHeight: height + 42 }}>
                                {t('richText.loading')}
                            </div>
                        )}
                    >
                        <TinyMceAdapter
                            key={`${theme}-${editorLocale}-${preset}`}
                            id={fieldId}
                            value={editorValue}
                            onChange={handleChange}
                            preset={preset}
                            theme={theme}
                            locale={editorLocale}
                            translations={translations}
                            blockLabels={blockLabels}
                            licenseKey={props.richText?.licenseKey ?? 'gpl'}
                            disabled={disabled}
                            readOnly={readOnly}
                            placeholder={placeholder}
                            minHeight={height}
                            labelledBy={labelId}
                            describedBy={describedBy}
                            invalid={Boolean(error)}
                            required={required}
                            textLocale={locale}
                            onReady={({ focus }) => { focusRef.current = focus; }}
                        />
                    </Suspense>
                </EditorBoundary>
            </div>

            {name && <input type="hidden" name={name} value={value ?? ''} />}
            {helperText && <FormDescription id={helperId}>{helperText}</FormDescription>}
            {countId && (
                <p id={countId} className={cx('text-helper tabular-nums', over ? 'font-medium text-red-600 dark:text-red-400' : 'text-(--app-muted-foreground)')}>
                    {maxLength
                        ? t('richText.charactersOfMax').replace(':count', length.toLocaleString()).replace(':max', maxLength.toLocaleString())
                        : t('richText.characters').replace(':count', length.toLocaleString())}
                    {over && <span role="status"> — {t('richText.overLimit').replace(':count', (length - (maxLength ?? 0)).toLocaleString())}</span>}
                </p>
            )}
            <FieldError id={errorId}>{error}</FieldError>
        </div>
    );
}

/* If the editor library fails to load or throws, the field degrades instead of taking the page down. */
class EditorBoundary extends Component<{ fallback: ReactNode; children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError(): { failed: boolean } {
        return { failed: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        console.error('RichTextEditor failed to load', error, info.componentStack);
    }

    render(): ReactNode {
        return this.state.failed ? this.props.fallback : this.props.children;
    }
}
