<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inertia
|--------------------------------------------------------------------------
|
| Only the keys that differ from inertiajs/inertia-laravel's defaults live
| here; the package merges the rest (SSR, testing, history) at the top level.
|
| Pages: the package looks for page components in resources/js/pages, while
| this application keeps them in resources/js/Pages (app.tsx globs
| ./Pages/**). On a case-sensitive filesystem — Linux, CI — the testing
| finder behind assertInertia()->component() could not find any page. The
| whole `pages` block is redefined because the merge is shallow.
|
*/

return [

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/Pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

];
