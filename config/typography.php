<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Typography — server-rendered output
|--------------------------------------------------------------------------
|
| The web UI's font stacks live in resources/css/app.css. This file covers
| what PHP renders itself: PDFs (dompdf) and the ID card SVG / PNG.
| docs/ui-typography.md explains the whole system.
|
| Font files are bundled in resources/fonts (OFL-1.1, see the README there)
| so no PDF or card export depends on fonts installed on a server or on the
| viewer's computer. Only files inside `font_path` can be referenced.
|
*/

return [

    'font_path' => resource_path('fonts'),

    /*
    | ID card SVG. Latin first so codes, dates and English lines use Inter;
    | Ethiopic falls through to Noto Sans Ethiopic. Keep in step with
    | `--font-id-card` in resources/css/app.css, which the browser preview
    | and the client-side PNG export use.
    */
    'id_card' => [
        'stack' => "'Inter','Noto Sans Ethiopic','Abyssinica SIL','Nyala',sans-serif",
    ],

    /*
    | PDF documents.
    |
    | dompdf picks ONE font per text run and does not fall back per glyph, so
    | each variant's font must cover both scripts. Both files below carry
    | Ethiopic and Latin.
    |
    |  report — tables, statements, dashboards on paper, posters.
    |  formal — letters and official decisions: Abyssinica SIL, whose Latin
    |           glyphs are its own companion serif.
    |
    | Neither family ships an italic, and Abyssinica SIL has no bold; those
    | faces map to the files that exist so dompdf never drops to a core font
    | (which has no Ethiopic and prints boxes).
    */
    'documents' => [
        'report' => [
            'family' => 'Noto Sans Ethiopic',
            'files' => [
                'normal' => 'NotoSansEthiopic-Regular.ttf',
                'bold' => 'NotoSansEthiopic-Bold.ttf',
            ],
            'fallback' => ['DejaVu Sans', 'sans-serif'],
        ],
        'formal' => [
            'family' => 'Abyssinica SIL',
            'files' => [
                'normal' => 'AbyssinicaSIL-Regular.ttf',
                'bold' => 'AbyssinicaSIL-Regular.ttf',
            ],
            'fallback' => ['DejaVu Serif', 'serif'],
        ],
    ],

];
