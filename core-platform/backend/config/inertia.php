<?php

/*
 * The pages are the EUISIS screens copied into core-platform/frontend; the
 * tests check that every page a controller renders exists there.
 */
return [
    'ssr' => [
        'enabled' => false,
    ],
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [base_path('../frontend/resources/js/Pages')],
        'extensions' => ['tsx'],
    ],
    'testing' => [
        'ensure_pages_exist' => true,
    ],
    'expose_shared_prop_keys' => true,
    'history' => [
        'encrypt' => false,
    ],
];
