/*
 * The only module that knows the editor library. RichTextEditor lazy-loads it,
 * so TinyMCE lands in its own chunk and only pages showing an editor fetch it.
 * Modules never import TinyMCE (a test enforces this): they use
 * <RichTextEditor>, and a library change stays inside this file.
 *
 * Everything is bundled and self-hosted: no CDN, no API key, nothing loaded
 * from outside the app's origin, so the Content-Security-Policy needs no
 * change. The editor runs inline (no iframe), so the text is drawn with the
 * app's own locale fonts (resources/css/fonts.css, Ethiopic included) and the
 * same `.rich-text` styles the viewer uses.
 */
import 'tinymce/tinymce';
import 'tinymce/models/dom/model';
import 'tinymce/themes/silver/theme';
import 'tinymce/icons/default/icons';
import 'tinymce/plugins/autolink/plugin';
import 'tinymce/plugins/link/plugin';
import 'tinymce/plugins/lists/plugin';
import 'tinymce/plugins/table/plugin';
import { Editor, type IAllProps } from '@tinymce/tinymce-react';
import type { Editor as TinyMceEditor, TinyMCE } from 'tinymce';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { Theme } from '@/lib/theme';
import { RICH_TEXT_POLICY, RICH_TEXT_PRESETS, allowedAttributes, type RichTextPreset } from './policy';

export interface RichTextAdapterProps {
    id: string;
    value: string;
    onChange: (html: string) => void;
    preset: RichTextPreset;
    theme: Theme;
    /** Interface language: toolbar and dialogs. */
    locale: 'en' | 'am';
    /** Language of the text itself, when it differs from the interface. */
    textLocale?: 'en' | 'am';
    /** Already translated: the editor library's English UI string → the label to show. */
    translations: Record<string, string>;
    blockLabels: { paragraph: string; heading2: string; heading3: string; heading4: string };
    licenseKey: string;
    disabled: boolean;
    readOnly: boolean;
    placeholder?: string;
    minHeight: number;
    labelledBy: string;
    describedBy?: string;
    invalid: boolean;
    required: boolean;
    onReady?: (api: { focus: () => void }) => void;
}

declare global {
    interface Window {
        tinymce: TinyMCE;
    }
}

const skins: Record<Theme, () => Promise<unknown>> = {
    light: () => Promise.all([import('tinymce/skins/ui/oxide/skin.js'), import('tinymce/skins/ui/oxide/content.inline.js')]),
    dark: () => Promise.all([import('tinymce/skins/ui/oxide-dark/skin.js'), import('tinymce/skins/ui/oxide-dark/content.inline.js')]),
};

/* valid_elements in the editor's syntax, from the preset's share of the policy. */
function validElements(preset: RichTextPreset): string {
    return RICH_TEXT_PRESETS[preset].tags
        .map((tag) => {
            const attributes = allowedAttributes(tag);
            const renamed = Object.entries(RICH_TEXT_POLICY.rename).filter(([, to]) => to === tag).map(([from]) => from);
            const name = [tag, ...renamed].join('/');

            return attributes.length > 0 ? `${name}[${attributes.join('|')}]` : name;
        })
        .join(',');
}

function validStyles(preset: RichTextPreset): Record<string, string> {
    return Object.fromEntries(
        RICH_TEXT_PRESETS[preset].tags.filter((tag) => RICH_TEXT_POLICY.aligned.includes(tag)).map((tag) => [tag, 'text-align']),
    );
}

function applyAccessibility(editor: TinyMceEditor, props: RichTextAdapterProps): void {
    const body = editor.getBody();
    body.setAttribute('role', 'textbox');
    body.setAttribute('aria-multiline', 'true');
    body.setAttribute('aria-labelledby', props.labelledBy);
    body.removeAttribute('aria-label');
    if (props.describedBy) body.setAttribute('aria-describedby', props.describedBy);
    else body.removeAttribute('aria-describedby');
    body.setAttribute('aria-invalid', props.invalid ? 'true' : 'false');
    body.setAttribute('aria-required', props.required ? 'true' : 'false');
    body.setAttribute('aria-readonly', props.readOnly ? 'true' : 'false');
    if (props.disabled) body.setAttribute('aria-disabled', 'true');
    else body.removeAttribute('aria-disabled');
}

export default function TinyMceAdapter(props: RichTextAdapterProps) {
    const { id, value, onChange, preset, theme, locale, translations, blockLabels, licenseKey, disabled, readOnly, placeholder, minHeight } = props;
    const definition = RICH_TEXT_PRESETS[preset];
    const toolbarRef = useRef<HTMLDivElement>(null);
    const editorRef = useRef<TinyMceEditor | null>(null);
    const [skinLoaded, setSkinLoaded] = useState<Theme | null>(null);

    useEffect(() => {
        let active = true;
        skins[theme]().then(() => active && setSkinLoaded(theme));

        return () => {
            active = false;
        };
    }, [theme]);

    // The editor's UI strings in Amharic. English is the library's own.
    useEffect(() => {
        if (locale === 'am') window.tinymce.addI18n('am', translations);
    }, [locale, translations]);

    const latest = useRef(props);
    latest.current = props;
    useEffect(() => {
        if (editorRef.current?.initialized) applyAccessibility(editorRef.current, props);
    }, [props.labelledBy, props.describedBy, props.invalid, props.required, props.readOnly, props.disabled]);

    const init = useMemo<NonNullable<IAllProps['init']>>(() => ({
        // Inline: the field is an ordinary element in the page, styled by
        // `.rich-text`, with the toolbar pinned above it.
        menubar: false,
        statusbar: false,
        branding: false,
        promotion: false,
        toolbar_persist: true,
        toolbar_mode: 'wrap',
        fixed_toolbar_container_target: toolbarRef.current ?? undefined,
        skin: theme === 'dark' ? 'oxide-dark' : 'oxide',
        content_css: false,
        language: locale,
        language_load: false,
        placeholder,
        plugins: [...definition.plugins],
        toolbar: definition.toolbar.join(' | '),
        block_formats: `${blockLabels.paragraph}=p;${blockLabels.heading2}=h2;${blockLabels.heading3}=h3;${blockLabels.heading4}=h4`,
        // What may exist in the document: the preset's share of policy.json.
        // Paste and drop are filtered through the same list.
        valid_elements: validElements(preset),
        valid_styles: validStyles(preset),
        invalid_elements: 'script,style,iframe,object,embed,img,svg,math,form,input,button,video,audio,source,link,meta,base',
        formats: {
            underline: { inline: 'u', remove: 'all' },
            alignleft: { selector: 'p,h2,h3,h4,li,td,th', styles: { textAlign: 'left' } },
            aligncenter: { selector: 'p,h2,h3,h4,li,td,th', styles: { textAlign: 'center' } },
            alignright: { selector: 'p,h2,h3,h4,li,td,th', styles: { textAlign: 'right' } },
            alignjustify: { selector: 'p,h2,h3,h4,li,td,th', styles: { textAlign: 'justify' } },
        },
        forced_root_block: 'p',
        entity_encoding: 'raw',
        // Images and files never enter formatted text.
        paste_data_images: false,
        paste_block_drop: true,
        block_unsupported_drop: true,
        paste_webkit_styles: 'none',
        paste_remove_styles_if_webkit: true,
        smart_paste: true,
        object_resizing: false,
        // Links: absolute http(s), mailto, tel. New-tab links get rel="noopener noreferrer".
        convert_urls: false,
        relative_urls: false,
        allow_script_urls: false,
        allow_html_data_urls: false,
        allow_unsafe_link_target: false,
        link_default_protocol: 'https',
        link_assume_external_targets: 'https',
        link_context_toolbar: true,
        link_title: true,
        link_target_list: [
            { title: translations['Current window'] ?? 'Current window', value: '' },
            { title: translations['New window'] ?? 'New window', value: '_blank' },
        ],
        // Tables: structure only; widths and borders come from the stylesheet.
        table_toolbar: 'tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol | tabledelete',
        table_appearance_options: false,
        table_advtab: false,
        table_cell_advtab: false,
        table_row_advtab: false,
        table_resize_bars: false,
        table_style_by_css: false,
        table_use_colgroups: false,
        table_default_attributes: {},
        table_default_styles: {},
        table_sizing_mode: 'responsive',
        // No autosave and no local copies: the form owns the value.
        browser_spellcheck: true,
        contextmenu: false,
        setup: (editor: TinyMceEditor) => {
            editorRef.current = editor;
            // The React binding syncs on key and composition events. Text that
            // arrives as a bare `input` (some Ethiopic input methods, dictation,
            // autocorrect) would reach the form only on blur, so the counter
            // lagged and a keyboard save could miss the last words. CommentChange
            // is one of the binding's own sync events and nothing else here uses it.
            editor.on('input', () => editor.dispatch('CommentChange'));
            editor.on('init', () => {
                const body = editor.getBody();
                body.classList.add('rich-text', 'rich-text-editable');
                body.style.minHeight = `${minHeight}px`;
                body.setAttribute('lang', latest.current.textLocale ?? locale);
                applyAccessibility(editor, latest.current);
                latest.current.onReady?.({ focus: () => editor.focus() });
            });
        },
    // skinLoaded: the toolbar container exists from the first commit, and the
    // options are built once the skin is in, so they always find it.
    }), [skinLoaded, theme, locale, preset, definition, placeholder, blockLabels, translations, minHeight]);

    return (
        <div className="rich-text-editor-shell" data-preset={preset}>
            <div ref={toolbarRef} className="rich-text-toolbar" />
            {skinLoaded === theme && (
                <Editor
                    id={id}
                    inline
                    licenseKey={licenseKey}
                    value={value}
                    onEditorChange={(html) => onChange(html)}
                    disabled={disabled}
                    readonly={readOnly}
                    init={init}
                />
            )}
        </div>
    );
}
