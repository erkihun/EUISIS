<?php

declare(strict_types=1);

namespace App\Services\PublicSite;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Str;

/**
 * Turns administrator-written Markdown into HTML that is safe to render on the
 * public site.
 *
 * Two independent layers, so a gap in one does not become an injection:
 *
 *  1. CommonMark (Laravel's Str::markdown) with raw HTML stripped and unsafe
 *     link schemes refused. An admin cannot type `<script>` or an `<iframe>`
 *     and have it survive; it is removed before any HTML is produced.
 *  2. HTMLPurifier with an explicit element/attribute allow-list and a URI
 *     scheme allow-list, as defence in depth against anything CommonMark lets
 *     through (e.g. a future option change).
 *
 * The frontend renders the result with dangerouslySetInnerHTML, which is only
 * acceptable because this class is the single path content takes to get there.
 */
final class SafeContentRenderer
{
    private ?HTMLPurifier $purifier = null;

    public function toHtml(?string $markdown): string
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return '';
        }

        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);

        return trim($this->purifier()->purify($html));
    }

    /** Plain text for excerpts and meta descriptions: no markup at all. */
    public function toPlainText(?string $markdown, int $limit = 0): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($this->toHtml($markdown))) ?? '');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $limit > 0 ? Str::limit($text, $limit) : $text;
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'strong', 'em', 'code', 'pre', 'blockquote', 'hr',
            'h2', 'h3', 'h4',
            'ul', 'ol', 'li',
            'a[href|title]',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
        ]));
        $config->set('URI.AllowedSchemes', ['https' => true, 'http' => true, 'mailto' => true, 'tel' => true]);
        // Every link opens in place and cannot reach back into this window.
        $config->set('HTML.Nofollow', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('AutoFormat.RemoveEmpty', true);

        return $this->purifier = new HTMLPurifier($config);
    }
}
