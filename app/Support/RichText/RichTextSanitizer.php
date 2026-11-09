<?php

declare(strict_types=1);

namespace App\Support\RichText;

use HTMLPurifier;
use HTMLPurifier_Config;
use HTMLPurifier_TagTransform_Simple;

/**
 * The only way formatted text is made safe — on save and again before the
 * server outputs it (PDF, email). Editor output is never trusted: whatever
 * the browser sends, only the allowlist in config/richtext.php survives.
 *
 * - Tags, attributes, URL schemes and link targets: the config allowlist.
 * - CSS: text-align on block tags, nothing else.
 * - Links opening a new tab get rel="noopener noreferrer".
 * - A plain-text value (legacy rows, or a form posting plain text) becomes
 *   escaped paragraphs instead of being read as markup.
 * - Empty content (`<p><br></p>`) is null, so a blank field stores NULL.
 *
 * Idempotent: sanitizing sanitized output returns it unchanged, so values
 * are never double-encoded however many times they pass through here.
 */
final class RichTextSanitizer
{
    private ?HTMLPurifier $purifier = null;

    /** @param array<string, mixed> $policy config('richtext') */
    public function __construct(private readonly array $policy) {}

    public function sanitize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $html = RichText::isHtml($value) ? $value : RichText::fromPlainText($value);
        $clean = trim($this->purifier()->purify($html));

        return RichText::isEmpty($clean) ? null : $clean;
    }

    private function purifier(): HTMLPurifier
    {
        return $this->purifier ??= new HTMLPurifier($this->config());
    }

    private function config(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        // HTML 4.01 serialises <br> as the editor does, so a saved value
        // round-trips through the editor without changing.
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', $this->allowedElements());
        $config->set('CSS.AllowedProperties', array_fill_keys($this->policy['allowed_styles'], true));
        $config->set('URI.AllowedSchemes', array_fill_keys($this->policy['allowed_schemes'], true));
        $config->set('Attr.AllowedFrameTargets', $this->policy['allowed_targets']);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer']);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('Attr.EnableID', false);
        $config->set('Core.RemoveProcessingInstructions', true);
        // Definitions are rebuilt per process instead of cached on disk; this
        // object is a singleton, so that is once per request or worker.
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.DefinitionID', 'richtext');
        $config->set('HTML.DefinitionRev', 1);

        $definition = $config->maybeGetRawHTMLDefinition();
        if ($definition !== null) {
            foreach ($this->policy['rename_tags'] as $from => $to) {
                $definition->info_tag_transform[$from] = new HTMLPurifier_TagTransform_Simple($to);
            }
        }

        return $config;
    }

    private function allowedElements(): string
    {
        $attributes = $this->policy['allowed_attributes'];
        $aligned = $this->policy['aligned_tags'];

        return implode(',', array_map(static function (string $tag) use ($attributes, $aligned): string {
            $allowed = $attributes[$tag] ?? [];
            if (in_array($tag, $aligned, true)) {
                $allowed[] = 'style';
            }

            return $allowed === [] ? $tag : $tag.'['.implode('|', $allowed).']';
        }, $this->policy['allowed_tags']));
    }
}
