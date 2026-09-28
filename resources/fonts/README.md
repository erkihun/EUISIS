# Bundled fonts

Fonts the **server** renders with: dompdf (PDF reports and letters) and
Imagick/librsvg (server-side ID card PNG). They live in the repository so every
deployment has them. No PDF or card export depends on fonts installed on the
server, and none depends on the viewer's computer.

The **web UI** fonts are not here. They come from the `@fontsource/*` npm
packages and are bundled by Vite (see `resources/css/fonts.css`).

How the system fits together is described in `docs/ui-typography.md`.

| File | Family | Used by | Source | SHA-256 |
|---|---|---|---|---|
| `NotoSansEthiopic-Regular.ttf` | Noto Sans Ethiopic 400 | PDF reports, card PNG | Google Fonts static build v50, `fonts.gstatic.com/s/notosansethiopic/v50/…T35OK6Dj.ttf` | `6d66ffc7a4a33f95d56df3c02417083d14f2bfd1f7b4c50ebcdcda3d3f89ea9c` |
| `NotoSansEthiopic-Bold.ttf` | Noto Sans Ethiopic 700 | PDF reports, card PNG | Google Fonts static build v50, `fonts.gstatic.com/s/notosansethiopic/v50/…T36pLKDj.ttf` | `dcd2194308a22136f931c3665bbba652bd4d71b1bc33be9c7aabddaddc8ce05d` |
| `AbyssinicaSIL-Regular.ttf` | Abyssinica SIL | Formal PDF documents | `github.com/google/fonts` → `ofl/abyssinicasil/` | `f0e4fb92ee26967a3e6462342494956a3798b952345f917e1388913bb191cf2b` |
| `Inter-Variable.ttf` | Inter (variable, `opsz`,`wght`) | Card PNG (Latin) | `github.com/google/fonts` → `ofl/inter/Inter[opsz,wght].ttf` | `29160a80ff49ddcab2c97711247e08b1fab27a484a329ce8b813d820dc559031` |

## Why these builds

dompdf draws each text run with **one** font and does not fall back glyph by
glyph. A PDF font must therefore contain Ethiopic **and** Latin.

- The Google Fonts static build of Noto Sans Ethiopic carries both scripts.
- The notofonts "hinted" build is Ethiopic-only. With it, English text, digits
  and codes print as missing glyphs.
- Abyssinica SIL carries its own Latin companion design.

Check coverage after replacing a file:

```bash
php -r 'require "vendor/autoload.php"; $f = FontLib\Font::load("resources/fonts/NotoSansEthiopic-Regular.ttf"); $f->parse(); $c = []; foreach ($f->getData("cmap")["subtables"] as $t) { $c += $t["glyphIndexArray"] ?? []; } var_dump(!empty($c[0x41]), !empty($c[0x1230]));'
```

`tests/Feature/Typography/PdfTypographyTest.php` also fails if a PDF stops
embedding real Ethiopic glyphs.

## Licence

All four families are licensed under the **SIL Open Font License 1.1**. The
licence texts are in `licenses/`. The OFL permits bundling the fonts with
software and embedding them (including as subsets) in generated documents.
The fonts must not be sold on their own, and a modified font must not be
distributed under the reserved names "Abyssinica" / "SIL". This application
does neither.

## fonts.conf

`fonts.conf` is a fontconfig file that adds this directory to the system font
set. `IdCardPngExporter` points `FONTCONFIG_FILE` at it when the environment
has not set one, so librsvg finds Inter and Noto Sans Ethiopic.
