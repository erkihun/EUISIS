<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Rich text — the one policy for formatted content
|--------------------------------------------------------------------------
|
| What formatted fields may hold. The allowlist itself lives in
| resources/js/richtext/policy.json so the server sanitizer
| (App\Support\RichText\RichTextSanitizer), the editor presets and the
| viewer read the same list: a tag cannot be allowed in the editor and
| quietly dropped on save, or the reverse. docs/rich-text-editor.md.
|
| Anything not listed is removed on save: scripts, iframes, embeds,
| images, event handlers, inline CSS other than text-align, and every
| URL scheme other than the four below.
|
*/

$policy = json_decode(
    (string) file_get_contents(resource_path('js/richtext/policy.json')),
    true,
    flags: JSON_THROW_ON_ERROR,
);

return [

    'allowed_tags' => $policy['tags'],

    /* Attributes per tag. Alignment (style="text-align: …") is separate. */
    'allowed_attributes' => $policy['attributes'],

    /* Block tags that may carry text-align, and its allowed values. */
    'aligned_tags' => $policy['aligned'],
    'allowed_styles' => $policy['styles'],
    'alignments' => $policy['alignments'],

    'allowed_schemes' => $policy['schemes'],

    /* Links may open in a new tab; they always get rel="noopener noreferrer". */
    'allowed_targets' => $policy['targets'],

    /* Legacy and pasted tags folded into allowed ones instead of dropped. */
    'rename_tags' => $policy['rename'],

    /*
    | Limits. `max_html_bytes` guards the stored markup and fits a MySQL TEXT
    | column (65,535 bytes). `max_plain_length` is the default limit on the
    | words a reader sees; a field can set a lower one in its rule.
    */
    'max_html_bytes' => (int) env('RICHTEXT_MAX_HTML_BYTES', 60000),
    'max_plain_length' => (int) env('RICHTEXT_MAX_PLAIN_LENGTH', 20000),

    /*
    | The browser editor (TinyMCE, self-hosted and bundled; nothing loads
    | from a CDN). TinyMCE is GPL-2.0-or-later unless a commercial licence
    | is bought: set RICHTEXT_EDITOR_LICENSE_KEY to the key Tiny issued, or
    | leave "gpl" after legal review. The key is not a secret in the
    | cryptographic sense, but it stays out of the repository.
    */
    'editor' => [
        'license_key' => env('RICHTEXT_EDITOR_LICENSE_KEY', 'gpl'),
    ],

];
