# EUISIS typography

EUISIS has one typography system, and every surface uses it: the web app,
the employee and provider portals, the public site, ID cards and PDFs. Font
families are defined in two central places. Nothing else names a font.

| Surface | Amharic | English | Defined in |
|---|---|---|---|
| Web app: dashboard, forms, tables, portals, public site | **Abyssinica SIL** | **Inter** | `resources/css/app.css` (tokens), `resources/css/fonts.css` (faces) |
| ID card: preview, print, PNG | **Abyssinica SIL** | **Inter** | `--font-id-card` in `app.css`; `typography.id_card.stack` in `config/typography.php` |
| PDF reports and statements (Amharic locale) | **Abyssinica SIL** | Abyssinica SIL's own Latin companion | `config/typography.php` → `report.locales.am` |
| PDF reports and statements (English locale) | Noto Sans Ethiopic | Noto Sans (the Latin glyphs of the same font) | `config/typography.php` → `report` |
| Formal official documents (decision letters) | **Abyssinica SIL** | Abyssinica SIL's own Latin companion | `config/typography.php` → `formal` |

## How it works

### Language switching

`<html lang>` is set by the server from the viewer's locale
(`SetClientLocale` → `app.blade.php`). `LocaleContext` updates it when the
user switches language. CSS reads the attribute:

```css
[lang|="en"] { --font-ui: var(--font-ui-en); … }
[lang|="am"] { --font-ui: var(--font-ui-am); … }
body          { font-family: var(--font-ui); line-height: var(--ui-line-height); }
:where([lang]) { font-family: var(--font-ui); }   /* an element with its own lang */
```

Every component therefore inherits the right font without knowing which
language is active. An element that carries its own `lang` (a bilingual ID
card field, or an English name inside Amharic UI) switches its own subtree.

### Tokens (`resources/css/app.css`)

| Token | Value |
|---|---|
| `--font-ui-en` | `'Inter', 'Inter Fallback', 'Abyssinica SIL', 'Noto Sans Ethiopic', 'Noto Sans', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif` |
| `--font-ui-am` | `'Abyssinica SIL', 'Inter', 'Inter Fallback', 'Noto Sans Ethiopic', 'Nyala', 'Kefa', system-ui, sans-serif` |
| `--font-document-am` | `'Abyssinica SIL', 'Noto Sans Ethiopic', 'Nyala', serif` |
| `--font-document-en` | `'Noto Sans', 'Inter', system-ui, sans-serif` |
| `--font-id-card` | `'Inter', 'Inter Fallback', 'Abyssinica SIL', 'Noto Sans Ethiopic', 'Nyala', sans-serif` |
| `--font-ui` / `--font-document` | the active language's stack |

Each stack names the other language's font second, so a stray Ethiopic word
in English UI still gets a designed face. Noto Sans Ethiopic follows as a
glyph-level fallback for any character Abyssinica SIL lacks, then locally
installed Ethiopic fonts: Nyala (Windows), Kefa (Apple). The
system UI font comes last, so the page stays readable if a webfont fails to
load.

### Web fonts (`resources/css/fonts.css`)

- Self-hosted from the `@fontsource/inter`, `@fontsource/abyssinica-sil` and
  `@fontsource/noto-sans-ethiopic` npm packages (OFL-1.1). Vite bundles them into `public/build/assets` with
  hashed, cacheable filenames. No CDN is used: the CSP is
  `font-src 'self' data:`.
- Weights **400, 500, 600, 700** only, woff2 only, `font-display: swap`.
  Abyssinica SIL ships one weight, declared at 400. The browser emboldens it
  for 600/700, and 500 renders as regular.
- The `unicode-range` split is deliberate. Inter serves Latin, and Abyssinica
  SIL (with Noto Sans Ethiopic behind it) is limited to the Ethiopic blocks. Latin text (employee numbers,
  codes, amounts, English words in an Amharic sentence) therefore always
  renders in Inter, and a browser downloads the Ethiopic files only for
  pages that contain Ethiopic.
- Payload: Inter ≈ 24 KB per weight, and one Abyssinica SIL file for all
  weights (Ethiopic pages only). Noto Sans Ethiopic is fetched only if a page
  uses a character Abyssinica SIL does not have.
- `Inter Fallback` is local Arial with Inter's metrics (`size-adjust`,
  `ascent-override`, …), so the swap to Inter does not reflow tables.
- No `<link rel="preload">`. Fonts are cached after the first visit, and
  preloading Ethiopic on English pages would waste bandwidth.

### Tailwind (`tailwind.config.js`)

| Utility | Meaning |
|---|---|
| `font-sans`, `font-ui` | `var(--font-ui)`, the locale's UI stack |
| `font-document` | `var(--font-document)` |
| `font-id-card` | `var(--font-id-card)` |
| `font-mono` | Tailwind default. Use only for codes, keys and technical data |

> The project runs Tailwind 3.4 (`tailwind.config.js`), not Tailwind 4's
> `@theme`. The tokens are ordinary CSS variables, so a later move to
> Tailwind 4 only changes where they are registered.

## Weights

| Weight | Use |
|---|---|
| 400 | body text, table cells, helper text |
| 500 | labels, navigation, buttons, column headers, validation messages |
| 600 | section and card headings, selected navigation |
| 700 | page titles, KPI values, genuinely important values |

`font-extrabold` and `font-black` are pinned to 700. 800/900 are not shipped,
and heavy Ethiopic strokes fill in. Do not set Amharic body text in bold.

## Sizes

The Tailwind scale is unchanged. English keeps its sizes; Amharic gets more
line height and a 12.8 px smallest step.

| Role | Utility | English | Amharic |
|---|---|---|---|
| Page title | `text-page-title` | 22 px / 1.3, 700 | 22 px / 1.45, 700 |
| Section heading | `text-section-title` | 18 px / 1.4, 600 | 18 px / 1.55, 600 |
| Body | `text-body` / `text-sm` | 14 px / 20 px | 14 px / 22 px |
| Label | `text-label` | 14 px, 500 | 14 px, 500 |
| Table | `text-table` / `text-sm` | 13–14 px / 20 px | 13–14 px / 21–22 px |
| Helper / validation | `text-helper` | 12 px / 16 px | 12.8 px / 20 px |
| Smallest step | `text-xs` | 12 px / 16 px | 12.8 px / 20 px |
| KPI value | `text-3xl font-bold` | 30 px | 30 px |

Line heights are variables (`--lh-sm`, `--lh-xs`, …) switched by `lang`, so
an explicit `leading-*` class on an element still wins. Base line height is
1.5 in English and 1.6 in Amharic.

`FormLabel`, `FormDescription`, `FieldError` (`@euisis/ui`) and `PageHeader`
use the semantic utilities. Prefer them in new shared components.

## Amharic rules

These are enforced centrally in `app.css`; don't work around them per page.

- **No uppercase.** `.uppercase` is neutralised on Ethiopic text
  (`.uppercase:lang(am)`), because Ethiopic has no case.
- **No tracking.** `tracking-*` classes reset to normal letter-spacing on
  Ethiopic text. The public site and portal headings also drop their
  negative tracking in Amharic.
- **Readable floor.** 9–11 px arbitrary sizes (`text-[10px]` …) render at
  12.8 px in Amharic. ID card faces are exempt, because their sizes come from
  the card template.
- **Room to breathe.** Line height is 1.55–1.7 for Amharic text styles.
- **No excessive bold.** Use 400 for body text and 600 for headings.
- Check Ethiopic punctuation (፡ ። ፣ ፤ ፥ ፦) and mixed content such as
  `ሰራተኛ ቁጥር AAC-00123456`. Digits render in Inter.
- Amharic and English are both LTR. Never add `dir="rtl"`.

## English rules

- Inter everywhere in the UI. Never mix in Roboto, Arial, Segoe UI or
  Figtree; they appear only as fallbacks inside the tokens.
- Restrained tracking on small uppercase English metadata labels is fine.

## ID cards

- The preview (`IdCardFront`/`Back`/`Portrait*`, marked by
  `data-card-template`) uses `--font-id-card`: Latin in Inter, Ethiopic in
  Abyssinica SIL. Recommended weights are 500/600, with 700 only where
  necessary. `font-mono` on code fields is deliberate and matches the
  server SVG.
- **Client PNG** (html-to-image): an SVG image cannot use the page's web
  fonts, so `captureFontOptions()` (`resources/js/lib/typography.ts`) inlines
  the loaded Inter / Abyssinica SIL faces into the capture. If that fails, the export
  still runs with system fonts.
- **Server SVG / PNG**: `IdCardSvgRenderer` takes its stack from
  `typography.id_card.stack`. `IdCardPngExporter` sets `FONTCONFIG_FILE` to
  `resources/fonts/fonts.conf`, so librsvg draws with the bundled Inter and
  Abyssinica SIL.
- Long names wrap within their field and are not truncated in the data.
  Presentation (line clamps, scaling) is the only place fitting happens.

## PDF and reports

dompdf (`barryvdh/laravel-dompdf` 3.x) draws each text run with **one** font
and does not fall back per glyph. A document font must therefore cover both
Ethiopic and Latin, and it must be embedded from files the server controls.

- Fonts are bundled in `resources/fonts/`. Sources, checksums and licences
  are in `resources/fonts/README.md`.
- Every PDF template includes the partial and does not set its own body font:

  ```blade
  @include('pdf.partials.typography', ['variant' => 'report'])  {{-- or 'formal' --}}
  ```

  The partial (via `App\Support\Typography\DocumentFonts`) declares
  normal/bold/italic faces, all mapped to real files so dompdf never drops
  to a core font, and sets `html, body { font-family: … }`.
- Use `report` for tables, statements, organograms and QR sheets. Use
  `formal` for letters and official decisions.
- A variant can override its font per app locale (`locales` in
  `config/typography.php`). In Amharic, `report` switches to Abyssinica SIL
  like the UI. Abyssinica SIL has no bold file, so bold text in those PDFs
  prints at regular weight. English reports keep Noto Sans Ethiopic and its
  real bold.
- Font subsetting is on (`config/dompdf.php`), so a document embeds only the
  glyphs it uses (tens of KB instead of about 365 KB per face).
- `font-family: monospace` is fine for ASCII codes and URLs.

### Check sheet

Outside production, a signed-in user can open `/dev/typography-test`
(`?variant=report|formal`, `?format=html`). It renders English and Amharic
headings, mixed text, numbers, dates, a table, regular and bold. The route
is not registered in production, and the controller refuses to run there.

`tests/Feature/Typography/PdfTypographyTest.php` renders that sheet and
fails if any Ethiopic or Latin character maps to glyph 0 (the "box").

## Other outputs

- **Toasts (Sonner)**: Sonner injects its own system stack. `app.css`
  overrides it with `--font-ui`.
- **Charts (Recharts)**: SVG text inherits the page font. Do not pass
  `fontFamily` to chart components.
- **Print windows** (organogram PDF) receive the app's `@font-face` rules
  and the current `--font-ui`, and wait for `document.fonts.ready` before
  printing.
- **Emails**: Laravel's default mail theme stack is used. Email clients
  don't load webfonts reliably, and Amharic renders through the operating
  system's Ethiopic fallback (Nyala/Ebrima on Windows, Noto on Android and
  Linux, Kefa on Apple). Nothing depends on downloading a font.
- **Excel**: cells keep Excel's default font. The requirement is correct
  Unicode text, and Excel substitutes an installed Ethiopic font for display.
- **CSV**: plain UTF-8 with a BOM for Excel (existing exporters). A CSV file
  carries no font.

## Deployment

- `npm ci && npm run build` bundles the web fonts. Nothing else is needed for
  the browser.
- PDFs need nothing installed on the server: fonts come from
  `resources/fonts`. `storage/fonts` must stay writable (dompdf's font
  metric cache, `config/dompdf.php`).
- Server-side ID card PNG export (Imagick + librsvg) uses
  `resources/fonts/fonts.conf` automatically. If the PHP environment already
  sets `FONTCONFIG_FILE`, add `resources/fonts` to that configuration, or
  install the fonts system-wide:

  ```bash
  sudo mkdir -p /usr/local/share/fonts/euisis
  sudo cp resources/fonts/*.ttf /usr/local/share/fonts/euisis/
  sudo fc-cache -f
  ```

  `storage/framework/cache/fontconfig` should be writable for fontconfig's
  cache. Without it fontconfig still works, just more slowly.

## Adding or changing a font

1. Get the files from an official source (Google Fonts / notofonts / SIL /
   the npm `@fontsource` package). Never copy them from a developer machine.
   Confirm the licence permits bundling and embedding.
2. Web: declare the faces in `fonts.css` (woff2, needed weights only,
   `unicode-range`) and change the token in `app.css`.
3. PDF / card: add the TTF and licence to `resources/fonts`, update the
   README table (source + SHA-256) and `config/typography.php`. For PDFs,
   confirm the font covers **both** Ethiopic and Latin.
4. Run `php artisan test tests/Feature/Typography tests/Unit/TypographySystemTest.php`
   and open `/dev/typography-test`.
