<?php

namespace Modules\Court\Application\Support;

use DOMDocument;
use DOMElement;

class SafeHtml
{
    /**
     * Allow basic formatting and tables; strip scripts/styles/handlers/inline css.
     */
    public static function clean(?string $html): string
    {
        $html = (string)($html ?? '');
        if ($html === '') return '';

        // If the content is entity-encoded (&lt;p&gt;...), decode it first
        if (str_contains($html, '&lt;') || str_contains($html, '&gt;')) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Wrap to help DOMDocument parse fragments
        $wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';

        $dom = new DOMDocument();
        // suppress warnings on malformed html
        @$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        /** @var DOMElement $body */
        $body = $dom->getElementsByTagName('body')->item(0);

        // Allowed tags (lowercase)
        $allowed = [
            'p',
            'br',
            'ul',
            'ol',
            'li',
            'strong',
            'b',
            'em',
            'i',
            'u',
            'blockquote',
            'h1',
            'h2',
            'h3',
            'h4',
            'table',
            'thead',
            'tbody',
            'tr',
            'th',
            'td',
            'a'
        ];

        // Remove disallowed nodes entirely
        self::removeDisallowed($body, $allowed);

        // Scrub attributes
        self::scrubAttributes($body);

        // Return innerHTML of body
        $out = '';
        foreach ($body->childNodes as $node) {
            $out .= $dom->saveHTML($node);
        }
        return $out;
    }

    private static function removeDisallowed(DOMElement $root, array $allowed): void
    {
        $disallowed = [];
        foreach ($root->getElementsByTagName('*') as $el) {
            $tag = strtolower($el->tagName);
            if (!in_array($tag, $allowed, true)) {
                $disallowed[] = $el;
            }
        }
        // remove after collecting to avoid live list issues
        foreach ($disallowed as $el) {
            $el->parentNode?->removeChild($el);
        }
    }

    private static function scrubAttributes(DOMElement $root): void
    {
        foreach ($root->getElementsByTagName('*') as $el) {
            $tag = strtolower($el->tagName);

            // drop all attributes first
            if ($el->hasAttributes()) {
                $toRemove = [];
                foreach ($el->attributes as $attr) {
                    $toRemove[] = $attr->name;
                }
                foreach ($toRemove as $name) {
                    $el->removeAttribute($name);
                }
            }

            // Re-allow safe attributes on <a>
            if ($tag === 'a') {
                // Only http/https links
                $href = $el->getAttribute('href'); // will be empty (we just removed), keep as local var
                if ($href === '') {
                    // try to extract from text (rare)
                    continue;
                }
                if (preg_match('~^(https?://)~i', $href)) {
                    $el->setAttribute('href', $href);
                    $el->setAttribute('rel', 'nofollow noopener noreferrer');
                    $el->setAttribute('target', '_blank');
                } else {
                    // non-http(s) → strip href
                    $el->removeAttribute('href');
                }
            }
        }
    }
}
