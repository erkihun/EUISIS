<?php

declare(strict_types=1);

/*
 * The court module (ADR 0020). Merged under `court` by CourtServiceProvider.
 * Sections come from court-ms: `compliance` (its config/compliance.php),
 * `purifier` (its mews/purifier profiles, read by Application\Support\Purifier)
 * and `euisis` (its services.euisis link, now the COURT_EUISIS_* variables).
 */
return [
    // The court's languages (court-ms config app.locales).
    'locales' => ['en', 'am'],

    'compliance' => [
            'security' => [
                'app_headers_enabled' => env('SECURITY_APP_HEADERS_ENABLED', false),
                'hsts_enabled' => env('SECURITY_HSTS_ENABLED', false),
                'hsts_max_age' => env('SECURITY_HSTS_MAX_AGE', 31_536_000),
                'csp_enforce' => env('SECURITY_CSP_ENFORCE', false),
                'content_security_policy' => env('SECURITY_CONTENT_SECURITY_POLICY', ''),
            ],

            'uploads' => [
                'scanner' => env('UPLOAD_SCANNER', 'none'),
                'scanner_binary' => env('UPLOAD_SCANNER_BINARY', 'clamscan'),
                'scanner_timeout_seconds' => env('UPLOAD_SCANNER_TIMEOUT', 30),
                'require_clean_scan' => env('UPLOAD_REQUIRE_CLEAN_SCAN', false),
            ],

            'continuity' => [
                'automated_backup' => env('BACKUP_AUTOMATED', false),
                'encrypted' => env('BACKUP_ENCRYPTED', false),
                'offsite' => env('BACKUP_OFFSITE', false),
                'restore_tested_at' => env('BACKUP_RESTORE_TESTED_AT'),
                'rpo_hours' => env('RECOVERY_RPO_HOURS'),
                'rto_hours' => env('RECOVERY_RTO_HOURS'),
            ],
    ],

    'purifier' => [
            'encoding'      => 'UTF-8',
            'finalize'      => true,
            // File mode for cache files (directories are handled by the package)

            'settings' => [
                /**
                 * Global default profile (used by Purifier::clean($html))
                 * Broader allowlist for trusted content.
                 */
                'default' => [
                    'HTML.Doctype'             => 'XHTML 1.0 Transitional',
                    'AutoFormat.AutoParagraph' => false,
                    'AutoFormat.RemoveEmpty'   => false,

                    // Generous allowlist (includes <img>, tables, etc.)
                    'HTML.Allowed' => implode(',', [
                        // inline & text
                        'p[style|class|dir]',
                        'br',
                        'span[style|class|dir]',
                        'mark[style|class]',
                        's',
                        'sub',
                        'sup',
                        'strong',
                        'b',
                        'em',
                        'i',
                        'u',
                        'small',
                        'code',
                        'kbd',
                        'samp',
                        // links
                        'a[href|title|target|rel|class|style]',
                        // separators
                        'hr',
                        // lists
                        'ul[style|class]',
                        'ol[style|class]',
                        'li[style|class]',
                        // headings
                        'h1[style|class]',
                        'h2[style|class]',
                        'h3[style|class]',
                        'h4[style|class]',
                        'h5[style|class]',
                        'h6[style|class]',
                        // blocks
                        'div[style|class|dir]',
                        'blockquote[style|class|cite]',
                        // tables
                        'table[style|class|border|cellpadding|cellspacing|width]',
                        'thead[style|class]',
                        'tbody[style|class]',
                        'tr[style|class]',
                        'th[style|class|colspan|rowspan|scope|width|height]',
                        'td[style|class|colspan|rowspan|width|height]',
                        // preformatted
                        'pre[style|class]',
                        // images
                        'img[src|alt|title|width|height|style|class]',
                    ]),

                    'CSS.AllowedProperties' => implode(',', [
                        'color',
                        'background-color',
                        'font-family',
                        'font-size',
                        'font-weight',
                        'font-style',
                        'text-decoration',
                        'text-align',
                        'line-height',
                        'letter-spacing',
                        'text-indent',
                        'white-space',
                        'direction',
                        'margin',
                        'margin-left',
                        'margin-right',
                        'margin-top',
                        'margin-bottom',
                        'padding',
                        'padding-left',
                        'padding-right',
                        'padding-top',
                        'padding-bottom',
                        'border',
                        'border-left',
                        'border-right',
                        'border-top',
                        'border-bottom',
                        'border-collapse',
                        'border-spacing',
                        'vertical-align',
                        'width',
                        'height',
                        'max-width',
                        'min-width',
                        'list-style-type',
                        'list-style-position',
                    ]),

                    // Allow IDs for trusted content only
                    'Attr.EnableID' => true,

                    // Safer link targets + limit schemes
                    'Attr.AllowedFrameTargets' => ['_blank'],
                    'URI.AllowedSchemes'       => ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true],

                    // Ensure definition changes take effect
                    'HTML.DefinitionID'  => 'global-defs',
                    'HTML.DefinitionRev' => 2,
                ],

                /**
                 * Strict profile for applicant/user-submitted case fields.
                 * Use like: Purifier::clean($html, 'cases')
                 */
                'cases' => [
                    'HTML.DefinitionID'  => 'cases-def',
                    'HTML.DefinitionRev' => 5, // bump when you change this profile
                    'HTML.Doctype'       => 'HTML 4.01 Transitional',
                    // Tight allowlist (no images/tables; minimal attributes)
                    'HTML.Allowed' => implode(',', [
                        // blocks
                        'p',
                        'br',
                        'div[style|dir]',
                        'blockquote',
                        'ul',
                        'ol[style|class]',
                        'ol',
                        'li',
                        'hr',
                        // headings
                        'h1',
                        'h2',
                        'h3',
                        'h4',
                        'h5',
                        'h6',
                        // inline
                        'b',
                        'strong',
                        'i',
                        'em',
                        'u',
                        'sub',
                        'sup',
                        'small',
                        'span[style|dir]',
                        'mark',
                        // links (no rel/class/style to keep it simple)
                        'a[href|title|target]',
                    ]),

                    'CSS.AllowedProperties' => implode(',', [
                        'text-align',
                        'color',
                        'background-color',
                        'font-size',
                        'font-weight',
                        'font-style',
                        'text-decoration',
                        'line-height',
                        'direction',
                    ]),

                    // Disallow IDs in user HTML
                    'Attr.EnableID'            => false,
                    'Attr.AllowedFrameTargets' => ['_blank'],

                    // Only safe URI schemes
                    'URI.AllowedSchemes'       => ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true],

                    // Clean out empty tags
                    'AutoFormat.RemoveEmpty'   => true,
                    'AutoFormat.AutoParagraph' => false,
                ],

                /**
                 * Extra elements/attributes appended safely to all profiles.
                 * Includes <mark> so both profiles recognize it.
                 */
                'custom_definition' => [
                    'id'    => 'html5-definitions',
                    'rev'   => 1,
                    'debug' => false,
                    'elements' => [
                        ['mark', 'Inline', 'Inline', 'Common'],
                    ],
                    'attributes' => [
                        ['a', 'target', 'Enum#_blank,_self,_parent,_top'],
                    ],
                ],
            ],
    ],

    'euisis' => [
        'enabled' => (bool) env('COURT_EUISIS_ENABLED', false),
        // Only EUISIS employees and institutions may sue or be sued.
        'parties_only' => (bool) env('COURT_PARTIES_ONLY', (bool) env('COURT_EUISIS_ENABLED', false)),
        // Employees work on their cases in My Portal (Flow 6): the applicant and respondent portal is closed.
        'portal_in_euisis' => (bool) env('COURT_PORTAL_IN_EUISIS', (bool) env('COURT_PARTIES_ONLY', (bool) env('COURT_EUISIS_ENABLED', false))),
        'portal_url' => env('COURT_PORTAL_URL'),
        'url' => rtrim((string) env('COURT_EUISIS_URL', ''), '/'),
        'token' => env('COURT_EUISIS_TOKEN'),
        'timeout' => (int) env('COURT_EUISIS_TIMEOUT', 15),
        'verify_tls' => (bool) env('COURT_EUISIS_VERIFY_TLS', true),
        'per_page' => (int) env('COURT_EUISIS_PER_PAGE', 100),
        'max_pages_per_run' => (int) env('COURT_EUISIS_MAX_PAGES_PER_RUN', 20),
    ],
];
