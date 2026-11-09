/*
 * The one rich-text policy, shared with the server (config/richtext.php reads
 * the same policy.json). The editor presets, the viewer's sanitizer and the
 * plain-text helpers below all derive from it, so the browser never offers a
 * format the server would drop on save. docs/rich-text-editor.md.
 *
 * Nothing here imports the editor library: pages that only display formatted
 * text stay free of it.
 */
import policy from './policy.json';

export type RichTextTag = (typeof policy.tags)[number];

export const RICH_TEXT_POLICY: {
    tags: readonly string[];
    attributes: Readonly<Record<string, readonly string[]>>;
    aligned: readonly string[];
    alignments: readonly string[];
    schemes: readonly string[];
    targets: readonly string[];
    rename: Readonly<Record<string, string>>;
} = policy;

export type RichTextPreset = 'basic' | 'standard' | 'document';

export interface RichTextPresetDefinition {
    /** Tags the toolbar can produce; paste keeps only these. */
    tags: readonly string[];
    /** TinyMCE toolbar groups, in order. Button names, not labels. */
    toolbar: readonly string[];
    plugins: readonly string[];
    /** Editable area height before it grows with the text. */
    minHeight: number;
    /** Whether the field offers an expand-to-full-screen button. */
    fullscreen: boolean;
}

const BASIC_TAGS = ['p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'a'] as const;
const STANDARD_TAGS = [...BASIC_TAGS, 'u', 'h2', 'h3', 'h4'] as const;
const DOCUMENT_TAGS = [...STANDARD_TAGS, 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td'] as const;

/*
 * basic    — short formatted notes: emphasis, lists, links.
 * standard — official text with headings and alignment.
 * document — long documents: adds quotes and tables.
 */
export const RICH_TEXT_PRESETS: Readonly<Record<RichTextPreset, RichTextPresetDefinition>> = {
    basic: {
        tags: BASIC_TAGS,
        toolbar: ['undo redo', 'bold italic', 'bullist numlist', 'link', 'removeformat'],
        plugins: ['autolink', 'link', 'lists'],
        minHeight: 120,
        fullscreen: false,
    },
    standard: {
        tags: STANDARD_TAGS,
        toolbar: ['undo redo', 'blocks', 'bold italic underline', 'alignleft aligncenter alignright alignjustify', 'bullist numlist', 'link', 'removeformat'],
        plugins: ['autolink', 'link', 'lists'],
        minHeight: 200,
        fullscreen: true,
    },
    document: {
        tags: DOCUMENT_TAGS,
        toolbar: ['undo redo', 'blocks', 'bold italic underline', 'alignleft aligncenter alignright alignjustify', 'bullist numlist', 'blockquote table', 'link', 'removeformat'],
        plugins: ['autolink', 'link', 'lists', 'table'],
        minHeight: 320,
        fullscreen: true,
    },
};

/* A preset may only offer tags the server keeps; caught while developing. */
if (import.meta.env.DEV) for (const definition of Object.values(RICH_TEXT_PRESETS)) {
    for (const tag of definition.tags) {
        if (!RICH_TEXT_POLICY.tags.includes(tag)) {
            throw new Error(`Rich text preset uses "${tag}", which policy.json does not allow.`);
        }
    }
}

/** Attributes a tag may carry, `style` included where alignment is allowed. */
export function allowedAttributes(tag: string): readonly string[] {
    const attributes = RICH_TEXT_POLICY.attributes[tag] ?? [];

    return RICH_TEXT_POLICY.aligned.includes(tag) ? [...attributes, 'style'] : attributes;
}

/** Whether a URL uses an allowed scheme (relative and fragment links are refused). */
export function isAllowedUrl(url: string): boolean {
    const match = /^([a-z][a-z0-9+.-]*):/i.exec(url.trim());

    return match !== null && RICH_TEXT_POLICY.schemes.includes(match[1].toLowerCase());
}

const BLOCK_END = /<\/(p|h[1-6]|li|blockquote|tr|div|ul|ol|table|thead|tbody)\s*>/gi;
const CELL_END = /<\/(td|th)\s*>/gi;
const LINE_BREAK = /<br\s*\/?>/gi;

/** Whether a stored value is markup rather than legacy plain text. Mirrors RichText::isHtml(). */
export function isRichTextHtml(value: string | null | undefined): boolean {
    return typeof value === 'string' && /<\/?[a-z][a-z0-9]*(\s[^<>]*)?\/?>/i.test(value);
}

function decodeEntities(text: string): string {
    if (typeof DOMParser === 'undefined' || !text.includes('&')) return text;

    // A parsed, never-attached document: nothing in it runs or loads.
    return new DOMParser().parseFromString(`<!doctype html><body>${text.replace(/</g, '&lt;')}`, 'text/html').body.textContent ?? text;
}

function tidyLines(text: string, maxBlankLines: number): string {
    const lines = text
        .replace(/[ ]/g, ' ')
        .replace(/[​﻿]/g, '')
        .split('\n')
        .map((line) => line.replace(/[ \t]+/g, ' ').trim());

    return lines.join('\n').replace(new RegExp(`\\n{${maxBlankLines + 2},}`, 'g'), '\n'.repeat(maxBlankLines + 1)).trim();
}

/** What a reader sees, one block per line. Mirrors RichText::plainText() on the server. */
export function richTextToPlainText(value: string | null | undefined): string {
    if (!value) return '';
    if (!isRichTextHtml(value)) return tidyLines(value.replace(/\r\n?/g, '\n'), 1);

    const text = value
        .replace(LINE_BREAK, '\n')
        .replace(CELL_END, '\t')
        .replace(BLOCK_END, '\n')
        .replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, '')
        .replace(/<[^>]*>/g, '');

    return tidyLines(decodeEntities(text), 0);
}

/** Characters a reader sees, counted per character as the server counts them. */
export function richTextLength(value: string | null | undefined): number {
    return Array.from(richTextToPlainText(value)).length;
}

/** True for null, blank, and markup with no words (`<p><br></p>`). */
export function isRichTextEmpty(value: string | null | undefined): boolean {
    return richTextToPlainText(value) === '';
}

function escapeHtml(text: string): string {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/** A legacy plain-text value as escaped paragraphs. Mirrors RichText::fromPlainText(). */
export function plainTextToRichText(value: string): string {
    return value
        .replace(/\r\n?/g, '\n')
        .trim()
        .split(/\n\s*\n/)
        .map((paragraph) => paragraph.trim())
        .filter((paragraph) => paragraph !== '')
        .map((paragraph) => `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`)
        .join('');
}
