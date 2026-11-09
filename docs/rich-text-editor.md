# Rich text: one editor, one viewer, one sanitizer

Formatted official content (announcements, job-description sections, management actions) is written in one shared editor, shown by one shared viewer and made safe by one server sanitizer. Modules never configure the editor library themselves.

```
Module page
  └─ <RichTextEditor>            resources/js/Components/forms/RichTextEditor.tsx
       └─ TinyMceAdapter (lazy)  resources/js/richtext/TinyMceAdapter.tsx   ← the only file that imports TinyMCE
            └─ TinyMCE 8 (self-hosted, bundled)

Form post ─► validation (App\Rules\RichTextContent)
          ─► App\Support\RichText\RichTextSanitizer (HTMLPurifier)  ← mandatory, on every write
          ─► TEXT column (sanitized HTML)

Stored HTML ─► <RichTextViewer html=…>   (DOMPurify, same allowlist, defence in depth)
            ─► @richtext($value) in Blade (PDF, email)
            ─► RichText::plainText() for CSV / Excel / search / excerpts
```

## The policy

`resources/js/richtext/policy.json` is the single allowlist. `config/richtext.php` reads it for the server; `resources/js/richtext/policy.ts` reads it for the editor presets and the viewer. A tag cannot be offered in the editor and dropped on save, or the reverse (`tests/Unit/RichText/RichTextArchitectureTest.php` checks the presets against it).

| | Allowed |
|---|---|
| Tags | `p br strong em u h2 h3 h4 ul ol li blockquote table thead tbody tr th td a` |
| Attributes | `a`: `href title target rel`; `th`, `td`: `colspan rowspan` |
| Style | `text-align` (left, center, right, justify) on `p h2 h3 h4 li th td`; nothing else |
| URL schemes | `http https mailto tel` |
| Link targets | `_blank` only, always with `rel="noopener noreferrer"` |
| Folded | `b→strong`, `i→em`, `h1→h2`, `h5/h6→h4` (pasted and legacy markup keeps its meaning) |

Everything else is removed: `script`, `style`, `iframe`, `object`, `embed`, `img` (and base64 images), `svg`, `math`, forms and inputs, every `on*` handler, `id`/`class`, all other CSS, `javascript:`/`vbscript:`/`data:` URLs.

Limits (`config/richtext.php`, env-overridable):

- `max_html_bytes` (`RICHTEXT_MAX_HTML_BYTES`, default 60 000): the stored markup. PostgreSQL TEXT has no practical limit, so this is what keeps a submission small (it also fits a MySQL TEXT column). Short text wrapped in a huge pile of tags is refused.
- `max_plain_length` (`RICHTEXT_MAX_PLAIN_LENGTH`, default 20 000): the default limit on characters a reader sees. Each field passes its own lower limit to the rule.

## Presets

Defined once in `resources/js/richtext/policy.ts` (`RICH_TEXT_PRESETS`).

| Preset | Toolbar | Height | Full screen | Use for |
|---|---|---|---|---|
| `basic` | undo/redo, bold, italic, bullets, numbering, link, clear formatting | 120 px | no | short formatted notes |
| `standard` | + paragraph/heading 2–4, underline, alignment | 200 px | yes | official messages |
| `document` | + blockquote, table | 320 px | yes | long documents |

Paste and drop keep only the tags of the field's preset; the server still applies the full policy.

Not offered, by design: source-code view, font family/size/colour pickers, images, media, embeds, autosave.

## Using it

### Create or edit form (Inertia `useForm`)

```tsx
import RichTextEditor from '@/Components/forms/RichTextEditor';

const form = useForm({ description: record?.description ?? '' });

<RichTextEditor
    preset="document"
    label={t('module.description')}
    value={form.data.description}
    onChange={(html) => form.setData('description', html)}
    error={form.errors.description}
    maxLength={5000}
    required
/>
```

The field is controlled: `reset()`, a cancelled edit, a failed submit and a page change all show what the form holds. An emptied editor reports `''`. Nothing is kept in the browser.

Props: `label`, `value`, `onChange`, `error`, `helperText`, `required`, `disabled`, `readOnly`, `placeholder`, `preset`, `minHeight`, `maxLength` (shows the counter), `showCount`, `id`, `name` (hidden input for a classic form post), `locale` (`'am'` for an Amharic field on an English page), `className`.

### Viewing

```tsx
import RichTextViewer from '@/Components/forms/RichTextViewer';

<RichTextViewer html={record.description} empty={<p>—</p>} />
```

Pages never render stored HTML with `dangerouslySetInnerHTML` themselves.

### Server side

```php
// Validation (FormRequest or $request->validate)
'description' => [RichTextContent::required(maxLength: 5000)],      // <p><br></p> counts as empty
'notes'       => ['nullable', new RichTextContent(maxLength: 2000)],

// Model: sanitized on every write (form, import, seeder); empty markup stored as NULL
protected function casts(): array
{
    return ['description' => RichTextHtml::class];
}

// Or explicitly, through DI, where a cast does not fit (JobDescriptionService)
$clean = $this->richText->sanitize($validated['description']);    // RichTextSanitizer

// Plain text (CSV, Excel, search, excerpts, length)
RichText::plainText($html);   RichText::isEmpty($html);   RichText::plainLength($html);
```

Blade (PDF, email): `@richtext($value)` is the only sanctioned way to print stored HTML. PDFs include `@include('pdf.partials.rich-text-styles')` inside their `<style>` and wrap the value in `<div class="rich-text">`.

## Trust boundary

1. The browser editor restricts what can be typed or pasted. This is convenience, never trusted.
2. **The server sanitizer is the boundary.** Every write to a rich-text column goes through `RichTextSanitizer` (via the `RichTextHtml` cast or an explicit call). It is idempotent: sanitizing its own output returns it unchanged, so nothing is double-encoded.
3. The viewer runs DOMPurify with the same allowlist before anything reaches the DOM, so a row written before the policy existed, or by a path that skipped the cast, still cannot run script. Verified by writing malicious HTML straight into the database and viewing it.
4. Server output (PDF, email) uses `@richtext`, which sanitizes again.

## Existing data

No migration rewrites stored values. Fields that held plain text keep it:

- The viewer shows a plain-text value with its line breaks (`white-space: pre-line`) and never parses it as HTML.
- The editor opens a plain-text value as escaped paragraphs.
- The sanitizer turns a plain-text value into escaped paragraphs the next time the record is saved.

A value counts as HTML when it contains a tag-shaped token (`<p>`, `</li>`, `<br/>` …). Text such as `5 < 6` stays plain.

## Language and theme

- Amharic and English content both work; Amharic is never set right-to-left. The writing area is part of the page (inline editor, no iframe), so it uses the app's locale fonts from `resources/css/fonts.css` and the same `.rich-text` styles as the viewer, with a taller line height for Amharic (`:lang(am)`).
- Toolbar, menus and dialogs follow the interface language (`resources/js/i18n/{en,am}/richText.ts`).
- Light and dark: the toolbar skin follows the `dark` class on `<html>` and switches with the theme. Field border, focus ring and radius are the app's tokens; the error state is a red border **plus** the message, linked through `aria-describedby`.

## Accessibility

- The writing area is `role="textbox"`, `aria-multiline`, labelled by the visible label (clicking the label focuses it), described by the helper text, counter and error, and carries `aria-invalid`, `aria-required` and `aria-readonly`.
- Alt+F10 moves to the toolbar, arrow keys move within a group, Tab between groups and Escape back to the text. Toolbar buttons show the app's focus ring.
- Full screen (standard and document presets) is a dialog that Escape closes.

## PDF and exports

- **PDF**: verified on the job-description PDF (dompdf, formal Ethiopic font) in English and Amharic: headings, paragraphs, alignment, numbered lists, links and tables render; no missing glyphs. Other PDFs do not print rich-text fields yet; add `@richtext` and the styles partial when they do.
- **CSV / Excel**: none of the converted fields is exported today. When one is, export `RichText::plainText()` (one block per line), never the markup.
- **Email**: announcement notifications carry the title only; no rich body is emailed. If one is, render it with `@richtext` in the mail view.
- **Search and excerpts**: use `RichText::plainText()` (announcement excerpts do).

## Loading and bundle

- TinyMCE is fetched only when an editor appears (`React.lazy`), into its own chunk; the app shell and every public and feedback page never load it (`RichTextArchitectureTest` and a build-manifest check).
- Sizes (production build): editor library 1 249 KB (431 KB gzip, once per editor page and cached); toolbar skin 186 KB (26 KB gzip) plus 56 KB (9 KB gzip), only for the theme in use; viewer with DOMPurify 34 KB (13 KB gzip), only on pages that show formatted text.
- If the editor fails to load, a field that holds formatted text shows it read-only with a reload button. An empty or plain-text field falls back to a plain textarea, so no formatting is ever silently flattened.

## Content-Security-Policy

No change. Everything is bundled and served from the app's origin. The skin's CSS is injected as a `<style>` element (`style-src 'unsafe-inline'` is already allowed).

## Licence and deployment

TinyMCE is GPL-2.0-or-later unless a commercial licence is bought; this project is MIT. `RICHTEXT_EDITOR_LICENSE_KEY` (default `gpl`) is passed to the editor from config, never committed. **NEEDS_DEPLOYMENT_CONFIGURATION:** confirm with legal whether GPL use is acceptable for this deployment, or set the commercial key issued by Tiny. No cloud API key is used.

## When to use rich text, and when not

| Classification | Fields |
|---|---|
| **Converted** | Announcement body (`standard`), job-description custom sections (`document`), Situation Room management action details (`basic`) |
| RICH_TEXT_REQUIRED, next | Position profile purpose, working conditions, authorities and relationships (they seed the job-description sections) |
| NEEDS_REVIEW | Mandate, function, service and process descriptions (5 000-character limits, shown in lists); performance objective descriptions; grievance letter templates and letter bodies (token substitution would have to escape values into HTML, and the PDF uses `nl2br`); grievance decisions and discipline reasoning (legal text; formatting needs a policy decision) |
| PLAIN_TEXT_PREFERRED | IDP/PIP fields (short single-purpose entries), occupation descriptions, notes, comments, remarks, reasons, filters |
| Never rich text | Public feedback, suggestions and complaints, QR pages, daily-work notes, transfer reasons, quick comments, search fields |

The public site keeps its own Markdown pipeline (`SafeContentRenderer`), which is separate from this one.

## Adding rich text to a field

1. The column is `text` (no migration needed if it already is; never convert existing values).
2. Model: `'field' => RichTextHtml::class` in `casts()`.
3. Validation: `RichTextContent::required(maxLength: …)` or `new RichTextContent(maxLength: …)`.
4. Form: `<RichTextEditor preset=…>`. Display: `<RichTextViewer html=…>`.
5. Exports, search, excerpts: `RichText::plainText()`. PDF or email: `@richtext`.
6. Tests: a feature test that posts a script payload and `<p><br></p>`, and checks the stored value.

Do not put the editor inside the shared `Modal`: its focus trap blocks the editor's link dialog. Edit in place on the page (as the job-description sections do).
